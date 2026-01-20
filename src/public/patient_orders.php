<?php
// Страница "Мои заказы" для пациента

require_once __DIR__ . '/header.php';

// Проверка, что пользователь авторизован и это именно пациент
if (!isset($user) || ($user['role'] ?? null) !== 'patient') {
    header('Location: login.php');
    exit;
}

$customerId = $user['role_id'] ?? null;
if (!$customerId) {
    echo '<div class="container"><div class="card"><div class="cardHeader">Ошибка</div><div class="muted">Не удалось определить профиль пациента.</div></div></div>';
    require_once __DIR__ . '/footer.php';
    exit;
}

// Загружаем заказы пациента
$stmt = $pdo->prepare("
    SELECT 
        o.id,
        o.order_date,
        o.status,
        c.short_name AS clinic_name,
        c.full_name  AS clinic_full_name
    FROM orders o
    LEFT JOIN clinics c ON c.id = o.clinic_id
    WHERE o.customer_id = :cid
    ORDER BY o.order_date DESC, o.id DESC
");
$stmt->execute(['cid' => $customerId]);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Позиции заказа
$stmtItems = $pdo->prepare("
    SELECT 
        oi.id,
        oi.drug_id,
        oi.form_id,
        oi.quantity,
        d.name      AS drug_name,
        f.form_name AS form_name
    FROM order_items oi
    JOIN drugs d      ON d.id = oi.drug_id
    LEFT JOIN forms f ON f.id = oi.form_id
    WHERE oi.order_id = :oid
    ORDER BY d.name, f.form_name
");

// Карта статусов как у аптекаря
$statusMap = [
        'new'       => 'Новый',
        'picked'    => 'Собран',
        'dispensed' => 'Выдан',
        'cancelled' => 'Отменён',
];
?>

<div class="container">
    <h1>Мои заказы</h1>
    <div class="muted" style="margin-bottom:12px;">
        Здесь отображаются ваши заказы на выдачу лекарств в выбранных аптеках.
        Статус обновляется, когда аптекарь отмечает сбор/выдачу.
    </div>

    <?php if (empty($orders)): ?>
        <div class="card">
            <div class="cardHeader">У вас пока нет оформленных заказов</div>
            <div class="muted">Когда вы забронируете лекарства по рецепту, ваши заказы появятся в этом разделе.</div>
        </div>
    <?php else: ?>

        <div>
            <?php foreach ($orders as $o): ?>
                <?php
                $stmtItems->execute(['oid' => $o['id']]);
                $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

                $status = $o['status'] ?? 'new';
                $statusLabel = $statusMap[$status] ?? $status;

                // Под те же классы, что у pharmacist_orders.php (если они есть в CSS)
                $badgeClass = 'status-new';
                if ($status === 'picked')    $badgeClass = 'status-picked';
                if ($status === 'dispensed') $badgeClass = 'status-dispensed';
                if ($status === 'cancelled') $badgeClass = 'status-cancelled';
                ?>

                <div class="card" style="margin-top:15px;">
                    <div class="cardHeader" style="display:flex; justify-content:space-between; gap:12px; align-items:center;">
                        <div>Заказ №<?= (int)$o['id'] ?> — <?= htmlspecialchars(date('d.m.Y', strtotime($o['order_date']))) ?></div>

                        <div class="orderCard-status">
                            <!-- Если у тебя нет statusBadge в стилях — всё равно будет читабельно -->
                            <span class="statusBadge <?= htmlspecialchars($badgeClass) ?>">
                                    <?= htmlspecialchars($statusLabel) ?>
                                </span>
                        </div>
                    </div>

                    <div style="padding: 0 0 12px 0;">
                        <div class="muted" style="margin-top:8px;">
                            <span>Аптека</span>
                            <strong>
                                <?= htmlspecialchars($o['clinic_name'] ?? $o['clinic_full_name'] ?? '—') ?>
                            </strong>
                        </div>

                        <?php if (!empty($items)): ?>
                            <div class="muted" style="margin-top:10px;">
                                <span>Состав заказа</span>
                                <ul style="margin: 6px 0 0 18px;">
                                    <?php foreach ($items as $it): ?>
                                        <li>
                                            <div class="drugLine-main">
                                                <strong><?= htmlspecialchars($it['drug_name']) ?></strong>
                                                <?php if (!empty($it['form_name'])): ?>
                                                    <span class="drugLine-meta">
                                                            <?= htmlspecialchars($it['form_name']) ?>
                                                        </span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="drugLine-qty">
                                                Количество: <?= (int)$it['quantity'] ?>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php else: ?>
                            <div class="muted" style="margin-top:8px;">
                                <span>Состав заказа</span>
                                <div class="muted" style="margin-top:6px;">Для этого заказа не найдены позиции.</div>
                            </div>
                        <?php endif; ?>



                        <?php if ($status === 'dispensed'): ?>
                            <div class="muted" style="margin-top:8px;">
                                <span>Комментарий</span>
                                <div class="muted" style="margin-top:6px;">
                                    Заказ выдан. Если что-то не выдали — уточни у аптекаря.
                                </div>
                            </div>
                        <?php elseif ($status === 'picked'): ?>
                            <div class="muted" style="margin-top:8px;">
                                <span>Комментарий</span>
                                <div class="muted" style="margin-top:6px;">
                                    Заказ собран. Можно подходить в аптеку.
                                </div>
                            </div>
                        <?php elseif ($status === 'cancelled'): ?>
                            <div class="muted" style="margin-top:8px;">
                                <span>Комментарий</span>
                                <div class="muted" style="margin-top:6px;">
                                    Заказ отменён аптекой или администратором.
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
