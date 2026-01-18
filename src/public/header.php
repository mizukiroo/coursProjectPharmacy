<?php
// header.php
require_once __DIR__ . '/auth.php';
$user    = get_current_user_data();
$current = basename($_SERVER['PHP_SELF']);
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Социальная Аптека</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link rel="stylesheet" href="css/styles.css">
</head>
<body class="<?= htmlspecialchars($page_class ?? '') ?>">

<div class="site">

    <header class="siteHeader herb-header">
        <div class="container herb-header-inner">

            <!-- ЛОГО -->
            <a href="index.php" class="herb-logo">
                <div class="herb-logo-mark">✤</div>
                <div class="herb-logo-text">
                    <div class="herb-logo-title">Социальные Аптеки Москвы</div>
                    <div class="herb-logo-subtitle">льготные лекарства онлайн</div>
                </div>
            </a>

            <!-- МЕНЮ -->
            <nav class="herb-nav">
                <a href="index.php"
                   class="herb-nav-link<?= $current === 'index.php' ? ' herb-nav-link--active' : '' ?>">
                    Главная
                </a>
                <a href="howto.php"
                   class="herb-nav-link<?= $current === 'howto.php' ? ' herb-nav-link--active' : '' ?>">
                    Как получить услугу
                </a>
                <a href="map.php"
                   class="herb-nav-link<?= $current === 'map.php' ? ' herb-nav-link--active' : '' ?>">
                    Карта аптек
                </a>

                <?php if ($user): ?>
                    <?php if ($user['role'] === 'patient'): ?>
                        <a href="patient_prescriptions.php"
                           class="herb-nav-link<?= $current === 'patient_prescriptions.php' ? ' herb-nav-link--active' : '' ?>">
                            Мои рецепты
                        </a>
                        <a href="patient_orders.php"
                           class="herb-nav-link<?= $current === 'patient_orders.php' ? ' herb-nav-link--active' : '' ?>">
                            Мои заказы
                        </a>

                    <?php elseif ($user['role'] === 'doctor'): ?>
                        <a href="doctor_prescriptions.php"
                           class="herb-nav-link<?= $current === 'doctor_prescriptions.php' ? ' herb-nav-link--active' : '' ?>">
                            Рецепты пациентов
                        </a>
                        <a href="doctor_new_prescription.php"
                           class="herb-nav-link<?= $current === 'doctor_new_prescription.php' ? ' herb-nav-link--active' : '' ?>">
                            Выписать рецепт
                        </a>

                    <?php elseif ($user['role'] === 'pharmacist'): ?>
                        <a href="pharmacist_orders.php"
                           class="herb-nav-link<?= $current === 'pharmacist_orders.php' ? ' herb-nav-link--active' : '' ?>">
                            Заказы аптеки
                        </a>
                        <a href="pharmacist_receipts.php"
                           class="herb-nav-link<?= $current === 'pharmacist_receipts.php' ? ' herb-nav-link--active' : '' ?>">
                            Накладные
                        </a>

                    <?php elseif ($user['role'] === 'admin'): ?>
                        <a href="admin_dashboard.php"
                           class="herb-nav-link<?= $current === 'admin_dashboard.php' ? ' herb-nav-link--active' : '' ?>">
                            Админ-панель
                        </a>
                    <?php endif; ?>
                <?php endif; ?>
            </nav>


            <!-- ПРАВО: ПОЛЬЗОВАТЕЛЬ -->
            <div class="herb-user">
                <?php if ($user): ?>
                    <div class="herb-user-info">
                        <div class="herb-user-avatar">
                            <?= mb_substr($user['full_name'] ?: $user['login'], 0, 1) ?>
                        </div>
                        <div class="herb-user-text">
                        <span class="herb-user-name">
                            <?= htmlspecialchars($user['full_name'] ?: $user['login']) ?>
                        </span>
                            <span class="herb-user-role">
                            <?= htmlspecialchars($user['role']) ?>
                        </span>
                        </div>
                    </div>
                    <a href="logout.php" class="herb-btn herb-btn-outline">Выйти</a>
                <?php else: ?>
                    <div class="herb-user">
                        <a href="login.php" class="herb-btn herb-btn-outline">Войти</a>
                        <a href="register.php" class="herb-btn herb-btn-filled">Регистрация</a>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </header>

    <main class="siteMain">
