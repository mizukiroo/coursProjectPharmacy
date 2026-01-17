<?php
require_once __DIR__ . '/auth.php';
$user = require_role('pharmacist');
global $pdo;

include __DIR__ . '/header.php';

$stmt = $pdo->prepare("
    SELECT o.*, u.full_name AS customer_name, c.full_name AS clinic_name
    FROM orders o
    JOIN customers cust ON cust.id = o.customer_id
    JOIN users u ON u.id = cust.id_user
    JOIN clinics c ON c.id = o.clinic_id
    WHERE o.clinic_id = ?
    ORDER BY o.order_date DESC, o.id DESC
");
$stmt->execute([$user['clinic_id']]);
$orders = $stmt->fetchAll();
?>
<div class="container pageHeader">
    <h1>Заказы моей аптеки</h1>
</div>

<div class="container">
    <?php foreach ($orders as $o): ?>
        <div class="card">
            <div class="cardHeader">
                Заказ №<?= $o['id'] ?> от <?= htmlspecialchars($o['order_date']) ?> —
                <?= htmlspecialchars($o['customer_name']) ?>
            </div>
            <div>Статус: <strong><?= htmlspecialchars($o['status'] ?? 'new') ?></strong></div>
            <?php if (empty($o['dispensed_at'])): ?>
                <form action="order_set_status.php" method="post" class="inlineForm">
                    <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
                    <select name="status">
                        <option value="processing">В обработке</option>
                        <option value="ready">Готов к выдаче</option>
                        <option value="cancelled">Отменён</option>
                    </select>
                    <button class="btn btn-secondary">Обновить статус</button>
                </form>

                <form action="order_dispense.php" method="post" class="inlineForm">
                    <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
                    <button class="btn btn-primary">Отметить как выданный</button>
                </form>
            <?php else: ?>
                <div class="muted">
                    Выдан: <?= htmlspecialchars($o['dispensed_at']) ?>
                </div>
            <?php endif; ?>

            <?php
            $stmtItems = $pdo->prepare("
                SELECT oi.*, d.name AS drug_name
                FROM order_items oi
                JOIN drugs d ON d.id = oi.drug_id
                WHERE oi.order_id = ?
            ");
            $stmtItems->execute([$o['id']]);
            $items = $stmtItems->fetchAll();
            ?>
            <ul>
                <?php foreach ($items as $it): ?>
                    <li><?= htmlspecialchars($it['drug_name']) ?> — <?= (int)$it['quantity'] ?> шт.</li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endforeach; ?>
</div>
<?php include __DIR__ . '/footer.php'; ?>
