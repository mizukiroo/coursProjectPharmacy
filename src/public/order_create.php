<?php
require_once __DIR__ . '/auth.php';
$user = require_role('patient');
global $pdo;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: patient_prescriptions.php');
    exit;
}

function columnExists(PDO $pdo, string $table, string $column): bool {
    $st = $pdo->prepare("
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
        LIMIT 1
    ");
    $st->execute([$table, $column]);
    return (bool)$st->fetchColumn();
}

$prescriptionId = (int)($_POST['prescription_id'] ?? 0);
$itemsIn = $_POST['items'] ?? null;

if ($prescriptionId <= 0 || !is_array($itemsIn)) {
    die('Неверные данные');
}

// обязательные колонки для учёта, уже заказано по рецепту
if (!columnExists($pdo, 'orders', 'prescription_id') || !columnExists($pdo, 'order_items', 'prescription_item_id')) {
    die(
        "База данных не готова для контроля повторных заказов.<br>" .
        "Выполни SQL:<br><pre>" .
        "ALTER TABLE orders ADD COLUMN prescription_id INT(10) UNSIGNED NULL;\n" .
        "ALTER TABLE order_items ADD COLUMN prescription_item_id INT(10) UNSIGNED NULL;\n" .
        "</pre>"
    );
}

// проверяем, что рецепт принадлежит пациенту
$st = $pdo->prepare("SELECT 1 FROM prescriptions WHERE id = ? AND customer_id = ?");
$st->execute([$prescriptionId, (int)$user['role_id']]);
if (!$st->fetchColumn()) {
    die('Рецепт недоступен');
}

$getAvailable = function(int $clinicId, int $drugId, int $formId) use ($pdo): int {
    $st = $pdo->prepare("CALL sp_get_available_qty(?, ?, ?)");
    $st->execute([$clinicId, $drugId, $formId]);

    // процедура делает SELECT available_qty, поэтому можно взять первый столбец
    $available = (int)$st->fetchColumn();

    $st->closeCursor();

    return $available;
};


$getAlreadyOrdered = function(int $prescriptionId, int $prescriptionItemId) use ($pdo): int {
    $st = $pdo->prepare("
        SELECT COALESCE(SUM(oi.quantity), 0)
        FROM orders o
        JOIN order_items oi ON oi.order_id = o.id
        WHERE o.prescription_id = ?
          AND o.status <> 'cancelled'
          AND oi.prescription_item_id = ?
    ");
    $st->execute([$prescriptionId, $prescriptionItemId]);
    return (int)$st->fetchColumn();
};

// подготовим statement для получения строки prescription_items
$stPi = $pdo->prepare("
    SELECT
        pi.id,
        pi.prescription_id,
        pi.drug_id,
        pi.form_id,
        COALESCE(pi.quantity, 0) AS qty,
        d.name AS drug_name,
        COALESCE(f.form_name, '') AS form_name
    FROM prescription_items pi
    JOIN drugs d ON d.id = pi.drug_id
    LEFT JOIN forms f ON f.id = pi.form_id
    WHERE pi.id = ? AND pi.prescription_id = ?
");

// собираем выбранные позиции, группируем по аптекам
$byClinic = []; // clinic_id => [ {pi_id, drug_id, form_id, qty} ... ]

foreach ($itemsIn as $piIdStr => $data) {
    $piId = (int)$piIdStr;
    if ($piId <= 0 || !is_array($data)) continue;

    $clinicId = (int)($data['clinic'] ?? 0);
    $qtyReq   = (int)($data['qty'] ?? 0);

    if ($qtyReq <= 0) continue; // не заказываем
    if ($clinicId <= 0) die('Выберите аптеку для всех выбранных позиций.');

    // найдём строку рецепта
    $stPi->execute([$piId, $prescriptionId]);
    $pi = $stPi->fetch(PDO::FETCH_ASSOC);
    if (!$pi) continue;

    $prescribed = (int)$pi['qty'];
    $already = $getAlreadyOrdered($prescriptionId, $piId);
    $leftByRx = max(0, $prescribed - $already);

    if ($leftByRx <= 0) {
        die('По позиции уже всё заказано: ' . htmlspecialchars($pi['drug_name'] . ' (' . $pi['form_name'] . ')'));
    }

    if ($qtyReq > $leftByRx) {
        die('Нельзя заказать больше, чем осталось по рецепту: ' .
            htmlspecialchars($pi['drug_name'] . ' (' . $pi['form_name'] . ')') .
            '<br>Осталось: ' . $leftByRx . ', выбрано: ' . $qtyReq
        );
    }

    $available = $getAvailable($clinicId, (int)$pi['drug_id'], (int)$pi['form_id']);
    if ($qtyReq > $available) {
        die('Недостаточно лекарства в аптеке: ' .
            htmlspecialchars($pi['drug_name'] . ' (' . $pi['form_name'] . ')') .
            "<br>Есть: $available, нужно: $qtyReq"
        );
    }

    if (!isset($byClinic[$clinicId])) $byClinic[$clinicId] = [];
    $byClinic[$clinicId][] = [
        'pi_id'   => $piId,
        'drug_id' => (int)$pi['drug_id'],
        'form_id' => (int)$pi['form_id'],
        'qty'     => $qtyReq,
    ];
}

if (empty($byClinic)) {
    die('Нечего заказывать (выберите аптеку и количество хотя бы для одного лекарства).');
}

$pdo->beginTransaction();
try {
    $insOrder = $pdo->prepare("
        INSERT INTO orders (customer_id, clinic_id, prescription_id, order_date, status)
        VALUES (?, ?, ?, CURRENT_DATE(), 'new')
    ");

    $insItem = $pdo->prepare("
        INSERT INTO order_items (order_id, drug_id, form_id, quantity, prescription_item_id)
        VALUES (?, ?, ?, ?, ?)
    ");

    foreach ($byClinic as $clinicId => $list) {
        if (empty($list)) continue;

        $insOrder->execute([(int)$user['role_id'], (int)$clinicId, $prescriptionId]);
        $orderId = (int)$pdo->lastInsertId();

        foreach ($list as $it) {
            $insItem->execute([
                $orderId,
                (int)$it['drug_id'],
                (int)$it['form_id'],
                (int)$it['qty'],
                (int)$it['pi_id'],
            ]);
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    die('Ошибка при создании заказа: ' . htmlspecialchars($e->getMessage()));
}

header('Location: patient_orders.php');
exit;
