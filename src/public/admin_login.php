<?php
require_once __DIR__ . '/auth.php';
global $pdo;

// Если уже вошёл как админ — сразу в панель
$cu = get_current_user_data();
if ($cu && ($cu['role'] ?? null) === 'admin') {
    header('Location: admin_dashboard.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($login === '' || $password === '') {
        $error = 'Введите логин и пароль.';
    } else {
        // Ищем пользователя, который есть в таблице admins
        $stmt = $pdo->prepare("
            SELECT u.id, u.login, u.password_hash
            FROM users u
            JOIN admins a ON a.id_user = u.id
            WHERE u.login = :login
            LIMIT 1
        ");
        $stmt->execute(['login' => $login]);
        $userRow = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$userRow || !password_verify($password, $userRow['password_hash'])) {
            $error = 'Неверный логин или пароль администратора.';
        } else {
            // ВАЖНО: auth.php работает через $_SESSION['user_id']
            $_SESSION['user_id'] = (int)$userRow['id'];
            header('Location: admin_dashboard.php');
            exit;
        }
    }
}

include __DIR__ . '/header.php';
?>

<main class="page page-admin">
    <div class="container pageHeader">
        <h1>Вход администратора</h1>
        <p class="muted">Управление пользователями и заказами социальной аптечной сети.</p>
    </div>

    <div class="container">
        <div class="landing-card adminAuthCard">
            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="post" class="adminForm">
                <label class="form-label">
                    <span>Логин</span>
                    <input type="text" name="login" class="input" required>
                </label>

                <label class="form-label">
                    <span>Пароль</span>
                    <input type="password" name="password" class="input" required>
                </label>

                <button type="submit" class="btn btn-primary adminBtnWide">
                    Войти в админ-панель
                </button>
            </form>
        </div>
    </div>
</main>

<?php include __DIR__ . '/footer.php'; ?>
