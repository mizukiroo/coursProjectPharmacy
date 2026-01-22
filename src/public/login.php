<?php
// login.php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$error = null;
$info  = null;

// Если пользователь уже залогинен показываем сообщение
$currentUser = function_exists('get_current_user_data') ? get_current_user_data() : null;

//чистим сессию и остаёмся на login.php
if (isset($_GET['switch']) && $_GET['switch'] === '1') {
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
        );
    }

    session_destroy();
    session_start();

    $currentUser = null;
    $info = 'Сессия очищена. Войдите в другой аккаунт.';
}

// Если нажали "Перейти" при уже активной сессии
if ($currentUser && isset($_GET['go']) && $_GET['go'] === '1') {
    if (($currentUser['role'] ?? '') === 'admin') {
        header('Location: admin_dashboard.php');
        exit;
    }
    header('Location: index.php');
    exit;
}

// Обработка входа
$oldLogin = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $oldLogin = $login;

    if ($login === '' || $password === '') {
        $error = 'Введите логин и пароль.';
    } else {
        $stmt = $pdo->prepare("CALL sp_get_user_for_login(?)");
        $stmt->execute([$login]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        if (!$row || !password_verify($password, $row['password_hash'])) {
            $error = 'Неверный логин или пароль.';
        } else {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$row['id'];

            if (($row['role'] ?? '') === 'admin') {
                header('Location: admin_dashboard.php');
                exit;
            }

            header('Location: index.php');
            exit;
        }
    }
}


include __DIR__ . '/header.php';
?>

<div class="container pageHeader">
    <h1>Вход</h1>
    <p class="muted">Введите логин и пароль.</p>
</div>

<div class="container" style="max-width: 520px; margin-bottom: 32px;">
    <div class="card" style="padding: 20px;">

        <?php if ($currentUser): ?>
            <div class="errorBox" style="
                margin-bottom: 12px;
                padding: 10px 12px;
                border-radius: 12px;
                background: #eff6ff;
                border: 1px solid #bfdbfe;
                color: #1e3a8a;
                font-size: 13px;
            ">
                Вы уже вошли как <b><?= htmlspecialchars($currentUser['login'] ?? 'пользователь', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></b>.
                Если хотите войти другим — нажмите «Войти другим».
            </div>

            <div class="grid2" style="gap: 10px; margin-bottom: 14px;">
                <a class="btn btn primary" style="width:100%; text-align:center;" href="login.php?go=1">
                    Перейти
                </a>
                <a class="btn btn primary" style="width:100%; text-align:center; background:#fff; color:#111; border:1px solid #e6e8f0;"
                   href="login.php?switch=1">
                    Войти другим
                </a>
            </div>
        <?php endif; ?>

        <?php if ($info): ?>
            <div class="errorBox" style="
                margin-bottom: 12px;
                padding: 10px 12px;
                border-radius: 12px;
                background: #ecfdf5;
                border: 1px solid #bbf7d0;
                color: #065f46;
                font-size: 13px;
            ">
                <?= htmlspecialchars($info, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="errorBox" style="
                margin-bottom: 12px;
                padding: 10px 12px;
                border-radius: 12px;
                background: #fef2f2;
                border: 1px solid #fecaca;
                color: #991b1b;
                font-size: 13px;
            ">
                <?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <form method="post" class="authForm" style="display: flex; flex-direction: column; gap: 12px;" autocomplete="off">
            <label class="field">
                <span class="fieldLabel">Логин</span>
                <input type="text" name="login" class="input" required
                       autocomplete="off"
                       value="<?= htmlspecialchars($oldLogin, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            </label>

            <label class="field">
                <span class="fieldLabel">Пароль</span>
                <input type="password" name="password" class="input" required autocomplete="new-password">
            </label>

            <button class="btn btn primary" style="width: 100%; margin-top: 6px;">
                Войти
            </button>

            <p class="muted" style="text-align: center; font-size: 13px; margin-top: 8px;">
                Нет аккаунта?
                <a href="register.php" style="color: #16a34a; text-decoration: none; font-weight: 600;">
                    Зарегистрироваться
                </a>
            </p>
        </form>
    </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>
