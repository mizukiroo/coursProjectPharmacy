<?php
require_once __DIR__ . '/auth.php';
$user = require_role('patient');
global $pdo;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: patient_prescriptions.php');
    exit;
}

$prescription_id = (int)($_POST['prescription_id'] ?? 0);
$clinic_id = (int)($_POST['clinic_id'] ?? 0);

if (!$prescription_id || !$clinic_id) {
    die('Неверные данные');
}

// проверяем рецепт
$stmt = $pdo->prepare("
    SELECT * FROM prescriptions
    WHERE id = ? AND customer_id = ? AND (status IS NULL OR status = 'active')
");
$stmt->execute([$prescription_id, $user['role_id']]);
$presc = $stmt->fetch();
if (!$presc) {
    die('Рецепт недоступен');
}

// позиции рецепта
$stmt = $pdo->prepare("
    SELECT pi.*, d.stock
    FROM prescription_items pi
    JOIN drugs d ON d.id = pi.drug_id
    WHERE pi.prescription_id = ?
");
$stmt->execute([$prescription_id]);
$items = $stmt->fetchAll();

if (!$items) {
    die('Нет позиций для заказа');
}

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare("
        INSERT INTO orders (customer_id, clinic_id, prescription_id, order_date, status, total_amount)
        VALUES (?, ?, ?, CURRENT_DATE(), 'new', NULL)
    ");
    $stmt->execute([$user['role_id'], $clinic_id, $prescription_id]);
    $order_id = (int)$pdo->lastInsertId();

    foreach ($items as $it) {
        $used = (int)($it['used_quantity'] ?? 0);
        $available_by_prescription = max(0, (int)$it['quantity'] - $used);
        if ($available_by_prescription <= 0) {
            continue;
        }

        $stock = (int)($it['stock'] ?? 0);
        $qty = min($available_by_prescription, $stock);
        if ($qty <= 0) {
            continue;
        }

        $stmt2 = $pdo->prepare("
            INSERT INTO order_items (order_id, drug_id, form_id, quantity, price)
            VALUES (?, ?, ?, ?, NULL)
        ");
        $stmt2->execute([$order_id, $it['drug_id'], $it['form_id'], $qty]);

        $stmt2 = $pdo->prepare("UPDATE drugs SET stock = stock - ? WHERE id = ?");
        $stmt2->execute([$qty, $it['drug_id']]);

        $stmt2 = $pdo->prepare("
            UPDATE prescription_items
               SET used_quantity = used_quantity + ?
             WHERE id = ?
        ");
        $stmt2->execute([$qty, $it['id']]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    die('Ошибка при создании заказа: ' . htmlspecialchars($e->getMessage()));
}

header('Location: patient_orders.php');
exit;
