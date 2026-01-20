<?php
require_once __DIR__ . '/auth.php';
$user = require_role('pharmacist');
global $pdo;

$pharmacistId = (int)$user['role_id'];

// 1. Аптеки, где работает фармацевт
$stmt = $pdo->prepare("
    SELECT c.id, c.full_name, c.short_name
    FROM pharmacist_clinics pc
    JOIN clinics c ON c.id = pc.clinic_id
    WHERE pc.pharmacist_id = ?
    ORDER BY c.full_name
");
$stmt->execute([$pharmacistId]);
$clinics = $stmt->fetchAll(PDO::FETCH_ASSOC);

$clinicId = (int)($_GET['clinic_id'] ?? ($clinics[0]['id'] ?? 0));

include __DIR__ . '/header.php';
?>

<div class="container">
    <h1>Заказы аптеки</h1>
    <p class="muted">
        Здесь отображаются заказы для выбранной аптеки. Фармацевт может отмечать их сбор и выдачу.
    </p>

    <?php if (empty($clinics)): ?>
        <div class="card">
            <div class="cardHeader">Нет привязанных аптек</div>
            <div class="muted">
                Для этого фармацевта не настроены аптеки в таблице <code>pharmacist_clinics</code>.
            </div>
        </div>
    <?php else: ?>

        <!-- выбор аптеки -->
        <form method="get" class="pharmToolbar" style="margin-bottom: 16px;">
            <div class="pharmField">
                <label>Аптека</label>
                <select name="clinic_id">
                    <?php foreach ($clinics as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= ((int)$c['id'] === $clinicId) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['short_name'] ?? $c['full_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="herb-btn herb-btn-outline" style="height:42px;">Показать</button>
        </form>

        <?php
        // 2. Заказы для выбранной аптеки
        $stmt = $pdo->prepare("
            SELECT 
                o.*,
                u.full_name  AS customer_name
            FROM orders o
            JOIN customers cust ON cust.id   = o.customer_id
            JOIN users u        ON u.id     = cust.id_user
            WHERE o.clinic_id = ?
            ORDER BY o.order_date DESC, o.id DESC
        ");
        $stmt->execute([$clinicId]);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // позиции заказа
        $stmtItems = $pdo->prepare("
            SELECT 
                oi.quantity,
                d.name      AS drug_name,
                f.form_name AS form_name
            FROM order_items oi
            JOIN drugs d      ON d.id = oi.drug_id
            LEFT JOIN forms f ON f.id = oi.form_id
            WHERE oi.order_id = ?
            ORDER BY d.name, f.form_name
        ");
        ?>

        <?php if (empty($orders)): ?>
            <div class="card">
                <div class="cardHeader">Заказов пока нет</div>
                <div class="muted">
                    Для выбранной аптеки ещё не оформлено ни одного заказа.
                </div>
            </div>
        <?php else: ?>

            <?php foreach ($orders as $o): ?>
                <?php
                $status = $o['status'] ?? 'new';
                $statusMap = [
                        'new'       => 'Новый',
                        'picked'    => 'Собран',
                        'dispensed' => 'Выдан',
                        'cancelled' => 'Отменён',
                ];
                $statusLabel = $statusMap[$status] ?? $status;

                $badgeClass = 'status-new';
                if ($status === 'picked')    $badgeClass = 'status-picked';
                if ($status === 'dispensed') $badgeClass = 'status-dispensed';
                if ($status === 'cancelled') $badgeClass = 'status-cancelled';

                $stmtItems->execute([$o['id']]);
                $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
                ?>

                <div class="card" style="margin-bottom: 16px;">
                    <div class="cardHeader">
                        Заказ №<?= (int)$o['id'] ?> от <?= htmlspecialchars($o['order_date']) ?>
                    </div>

                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                        <div class="muted">
                            Пациент: <strong><?= htmlspecialchars($o['customer_name'] ?? '—') ?></strong>
                        </div>
                        <div>
                            <span class="statusBadge <?= $badgeClass ?>">
                                <?= htmlspecialchars($statusLabel) ?>
                            </span>
                        </div>
                    </div>

                    <?php if (!empty($items)): ?>
                        <ul style="margin: 0 0 10px 18px;">
                            <?php foreach ($items as $it): ?>
                                <li>
                                    <?= htmlspecialchars($it['drug_name']) ?>
                                    <?php if (!empty($it['form_name'])): ?>
                                        (<?= htmlspecialchars($it['form_name']) ?>)
                                    <?php endif; ?>
                                    — <?= (int)$it['quantity'] ?> шт.
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <div class="muted">Позиции для этого заказа не найдены.</div>
                    <?php endif; ?>

                    <div class="inlineForm" style="margin-top: 8px; flex-wrap: wrap; gap: 8px;">
                        <?php if ($status === 'new'): ?>
                            <form action="order_set_status.php" method="post">
                                <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                                <input type="hidden" name="status"   value="picked">
                                <button class="btn btn-secondary">Отметить «Собран»</button>
                            </form>
                        <?php endif; ?>

                        <?php if ($status === 'picked'): ?>
                            <form action="order_set_status.php" method="post">
                                <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                                <input type="hidden" name="status"   value="dispensed">
                                <button class="btn btn-primary">Отметить «Выдан»</button>
                            </form>
                        <?php endif; ?>

                        <?php if ($status !== 'cancelled' && $status !== 'dispensed'): ?>
                            <form action="order_set_status.php" method="post"
                                  onsubmit="return confirm('Отменить заказ?');">
                                <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                                <input type="hidden" name="status"   value="cancelled">
                                <button class="btn btn-secondary">Отменить</button>
                            </form>
                        <?php endif; ?>

                    </div>
                </div>
            <?php endforeach; ?>

        <?php endif; ?>

    <?php endif; ?>
</div>

<?php include __DIR__ . '/footer.php'; ?>
