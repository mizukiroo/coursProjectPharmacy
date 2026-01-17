<?php
// Страница "Мои рецепты" для пациента

require_once __DIR__ . '/header.php';

// Проверка, что пользователь авторизован и это именно пациент
if (!isset($user) || ($user['role'] ?? null) !== 'patient') {
    header('Location: login.php');
    exit;
}

$customerId = $user['role_id'] ?? null;
if (!$customerId) {
    // На всякий случай, если роль настроена криво
    echo '<div class="container"><p>Не удалось определить профиль пациента.</p></div>';
    require_once __DIR__ . '/footer.php';
    exit;
}

// Загружаем рецепты для этого пациента
$stmt = $pdo->prepare("
    SELECT
        p.id,
        p.prescription_date,
        p.comment,
        duser.full_name AS doctor_name
    FROM prescriptions p
    LEFT JOIN doctors d 
        ON d.id = p.doctor_id
    LEFT JOIN users duser 
        ON duser.id = d.id_user
    WHERE p.customer_id = :cid
    ORDER BY p.prescription_date DESC, p.id DESC
");
$stmt->execute(['cid' => $customerId]);
$prescriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<main class="page page-prescriptions">
    <div class="container pageHeader">
        <h1>Мои рецепты</h1>
        <p class="muted">
            Здесь отображаются электронные рецепты, которые вам выписали врачи.
        </p>
    </div>

    <div class="container">
        <?php if (empty($prescriptions)): ?>
            <div class="emptyState">
                <div class="emptyState-title">У вас пока нет назначенных рецептов</div>
                <p class="emptyState-text">
                    Как только врач оформит для вас электронный рецепт, он появится в этом разделе.
                </p>
            </div>
        <?php else: ?>

            <div class="prescriptionList">
                <?php
                // Подготовим запрос для пунктов каждого рецепта
                $stmtItems = $pdo->prepare("
                    SELECT 
                        pi.id,
                        pi.drug_id,
                        pi.quantity,
                        d.name       AS drug_name,
                        d.form       AS drug_form,
                        d.dosage     AS drug_dosage
                    FROM prescription_items pi
                    JOIN drugs d ON d.id = pi.drug_id
                    WHERE pi.prescription_id = :pid
                    ORDER BY d.name
                ");

                foreach ($prescriptions as $p):
                    $stmtItems->execute(['pid' => $p['id']]);
                    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
                    ?>
                    <article class="landing-card prescriptionCard">
                        <div class="landing-card-header">
                            <div>
                                <div class="pill-badge pill-badge--green">
                                    Рецепт №<?= (int)$p['id'] ?>
                                </div>
                                <h2 class="card-title">
                                    Назначение от
                                    <?= htmlspecialchars(date('d.m.Y', strtotime($p['prescription_date']))) ?>
                                </h2>
                            </div>
                        </div>

                        <div class="landing-card-body">
                            <div class="landing-card-row">
                                <span>Врач</span>
                                <strong>
                                    <?= htmlspecialchars($p['doctor_name'] ?? '—') ?>
                                </strong>
                            </div>

                            <?php if (!empty($p['comment'])): ?>
                                <div class="landing-card-row">
                                    <span>Комментарий врача</span>
                                    <div class="landing-card-note">
                                        <?= nl2br(htmlspecialchars($p['comment'])) ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($items)): ?>
                                <div class="landing-card-row landing-card-row--stacked">
                                    <span>Назначенные препараты</span>
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
                                    <span>Назначенные препараты</span>
                                    <div class="landing-card-note">Для этого рецепта нет привязанных препаратов.</div>
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
