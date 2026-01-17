<?php
require_once __DIR__ . '/auth.php';
global $pdo;

$error = null;

// Если уже залогинен — отправим по роли
$cu = get_current_user_data();
if ($cu) {
    if (($cu['role'] ?? '') === 'admin') {
        header('Location: admin_dashboard.php');
        exit;
    }
    // обычного пользователя — на главную/профиль (можешь поменять)
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($login === '' || $password === '') {
        $error = 'Введите логин и пароль.';
    } else {
        // Берём пользователя и определяем роль через таблицы ролей
        $stmt = $pdo->prepare("
            SELECT 
                u.id, u.login, u.password_hash,
                CASE
                    WHEN a.id_user IS NOT NULL THEN 'admin'
                    WHEN d.id_user IS NOT NULL THEN 'doctor'
                    WHEN p.id_user IS NOT NULL THEN 'pharmacist'
                    WHEN c.id_user IS NOT NULL THEN 'patient'
                    ELSE 'none'
                END AS role
            FROM users u
            LEFT JOIN admins a      ON a.id_user = u.id
            LEFT JOIN doctors d     ON d.id_user = u.id
            LEFT JOIN pharmacists p ON p.id_user = u.id
            LEFT JOIN customers c   ON c.id_user = u.id
            WHERE u.login = :login
            LIMIT 1
        ");
        $stmt->execute(['login' => $login]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || !password_verify($password, $row['password_hash'])) {
            $error = 'Неверный логин или пароль.';
        } else {
            // ВАЖНО: auth.php должен читать именно $_SESSION['user_id']
            if (session_status() !== PHP_SESSION_ACTIVE) session_start();
            $_SESSION['user_id'] = (int)$row['id'];

            // Редирект по роли
            if (($row['role'] ?? '') === 'admin') {
                header('Location: admin_dashboard.php');
                exit;
            }

            // Остальные — куда тебе нужно (можешь поменять логику)
            header('Location: index.php');
            exit;
        }
    }
}

include __DIR__ . '/header.php';
?>

<main class="page">
    <div class="container pageHeader">
        <h1>Вход</h1>
        <p class="muted">Введите логин и пароль.</p>
    </div>

    <div class="container">
        <div class="landing-card" style="max-width: 460px; margin: 0 auto;">
            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="post">
                <label class="form-label">
                    <span>Логин</span>
                    <input type="text" name="login" class="input" required>
                </label>

                <label class="form-label">
                    <span>Пароль</span>
                    <input type="password" name="password" class="input" required>
                </label>

                <div class="actions">
                    <button type="submit" class="btn btn-primary">Войти</button>
                    <a class="btn btn-secondary" href="register.php">Регистрация</a>
                </div>
            </form>
        </div>
    </div>
</main>

<?php include __DIR__ . '/footer.php'; ?>
