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
    echo '<div class="container"><p>Не удалось определить профиль пациента.</p></div>';
    require_once __DIR__ . '/footer.php';
    exit;
}

// Загружаем заказы пациента
$stmt = $pdo->prepare("
    SELECT 
        o.id,
        o.order_date,
        o.total_amount,
        c.short_name AS clinic_name,
        c.full_name  AS clinic_full_name
    FROM orders o
    LEFT JOIN clinics c ON c.id = o.clinic_id
    WHERE o.customer_id = :cid
    ORDER BY o.order_date DESC, o.id DESC
");
$stmt->execute(['cid' => $customerId]);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<main class="page page-orders">
    <div class="container pageHeader">
        <h1>Мои заказы</h1>
        <p class="muted">
            Здесь отображаются ваши брони и заказы на выдачу лекарств в социальных аптеках.
        </p>
    </div>

    <div class="container">
        <?php if (empty($orders)): ?>
            <div class="emptyState">
                <div class="emptyState-title">У вас пока нет оформленных заказов</div>
                <p class="emptyState-text">
                    Когда вы забронируете лекарства по рецепту, ваши заказы появятся в этом разделе.
                </p>
            </div>
        <?php else: ?>

            <div class="ordersList">
                <?php
                // Подготовим запрос для позиций каждого заказа
                $stmtItems = $pdo->prepare("
                    SELECT 
                        oi.id,
                        oi.drug_id,
                        oi.quantity,
                        d.name   AS drug_name,
                        d.form   AS drug_form,
                        d.dosage AS drug_dosage
                    FROM order_items oi
                    JOIN drugs d ON d.id = oi.drug_id
                    WHERE oi.order_id = :oid
                    ORDER BY d.name
                ");

                foreach ($orders as $o):
                    $stmtItems->execute(['oid' => $o['id']]);
                    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

                    // Простой статус: пока без сложной логики, можно потом доработать
                    $statusLabel = 'Оформлен';
                    ?>
                    <article class="landing-card orderCard">
                        <div class="landing-card-header">
                            <div>
                                <div class="pill-badge pill-badge--green">
                                    Заказ №<?= (int)$o['id'] ?>
                                </div>
                                <h2 class="card-title">
                                    От <?= htmlspecialchars(date('d.m.Y', strtotime($o['order_date']))) ?>
                                </h2>
                            </div>
                            <div class="orderCard-status">
                                <span class="pill-badge pill-badge--soft">
                                    <?= htmlspecialchars($statusLabel) ?>
                                </span>
                            </div>
                        </div>

                        <div class="landing-card-body">
                            <div class="landing-card-row">
                                <span>Аптека</span>
                                <strong>
                                    <?= htmlspecialchars($o['clinic_name'] ?? $o['clinic_full_name'] ?? '—') ?>
                                </strong>
                            </div>

                            <?php if (!empty($items)): ?>
                                <div class="landing-card-row landing-card-row--stacked">
                                    <span>Состав заказа</span>
                                    <ul class="prescriptionDrugsList">
                                        <?php foreach ($items as $it): ?>
                                            <li>
                                                <div class="drugLine-main">
                                                    <strong><?= htmlspecialchars($it['drug_name']) ?></strong>
                                                    <?php if (!empty($it['drug_form']) || !empty($it['drug_dosage'])): ?>
                                                        <span class="drugLine-meta">
                                                            <?= htmlspecialchars(trim(($it['drug_form'] ?? '') . ' ' . ($it['drug_dosage'] ?? ''))) ?>
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
                                <div class="landing-card-row">
                                    <span>Состав заказа</span>
                                    <div class="landing-card-note">Для этого заказа не найдены позиции.</div>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($o['total_amount'])): ?>
                                <div class="landing-card-row">
                                    <span>Итого</span>
                                    <strong><?= htmlspecialchars($o['total_amount']) ?></strong>
                                </div>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

        <?php endif; ?>
    </div>
</main>

<?php require_once __DIR__ . '/footer.php'; ?>
