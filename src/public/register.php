<?php
// register.php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

// если уже залогинен – не даём регаться повторно
$currentUser = function_exists('get_current_user_data') ? get_current_user_data() : null;
if ($currentUser) {
    // если пациент – сразу в рецепты, иначе на главную
    if (!empty($currentUser['role']) && $currentUser['role'] === 'patient') {
        header('Location: patient_prescriptions.php');
    } else {
        header('Location: index.php');
    }
    exit;
}

$errors = [];
// для "залипания" значений в форме
$old = [
    'full_name' => '',
    'login'     => '',
    'email'     => '',
    'phone'     => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $login     = trim($_POST['login'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $phone     = trim($_POST['phone'] ?? '');
    $password  = $_POST['password'] ?? '';
    $password2 = $_POST['password_confirm'] ?? '';

    $old['full_name'] = $full_name;
    $old['login']     = $login;
    $old['email']     = $email;
    $old['phone']     = $phone;

    // ВАЛИДАЦИЯ
    if ($full_name === '') {
        $errors[] = 'Введите ФИО';
    }

    if ($login === '') {
        $errors[] = 'Введите логин';
    }

    if ($password === '' || $password2 === '') {
        $errors[] = 'Введите пароль и его подтверждение';
    } elseif ($password !== $password2) {
        $errors[] = 'Пароли не совпадают';
    } elseif (mb_strlen($password) < 6) {
        $errors[] = 'Пароль должен быть не короче 6 символов';
    }

    // проверка уникальности логина
    if (!$errors) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE login = ? LIMIT 1");
        $stmt->execute([$login]);
        if ($stmt->fetch()) {
            $errors[] = 'Такой логин уже занят. Выберите другой.';
        }
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            // хешируем пароль
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);

            // создаём пользователя
            $stmt = $pdo->prepare("
                INSERT INTO users (login, password_hash, full_name, phone, email)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $login,
                $passwordHash,
                $full_name !== '' ? $full_name : null,
                $phone !== '' ? $phone : null,
                $email !== '' ? $email : null,
            ]);

            $userId = (int)$pdo->lastInsertId();

            // создаём запись в customers, чтобы он был пациентом
            $stmt = $pdo->prepare("INSERT INTO customers (id_user) VALUES (?)");
            $stmt->execute([$userId]);

            $pdo->commit();

            // логиним
            if (session_status() !== PHP_SESSION_ACTIVE) {
                session_start();
            }
            $_SESSION['user_id'] = $userId;

            // отправляем в "Мои рецепты"
            header('Location: patient_prescriptions.php');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Ошибка при сохранении данных. Попробуйте позже или проверьте структуру таблиц.';
            // при необходимости можешь временно раскомментировать:
            // $errors[] = $e->getMessage();
        }
    }
}

include __DIR__ . '/header.php';
?>

<div class="container pageHeader">
    <h1>Регистрация пациента</h1>
    <p class="muted">Создайте учётную запись, чтобы видеть свои рецепты и бронировать льготные лекарства.</p>
</div>

<div class="container" style="max-width: 520px; margin-bottom: 32px;">
    <div class="card" style="padding: 20px;">
        <?php if ($errors): ?>
            <div class="errorBox" style="
                margin-bottom: 12px;
                padding: 10px 12px;
                border-radius: 12px;
                background: #fef2f2;
                border: 1px solid #fecaca;
                color: #991b1b;
                font-size: 13px;
            ">
                <?php foreach ($errors as $err): ?>
                    <div><?= htmlspecialchars($err, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="post" class="authForm" style="display: flex; flex-direction: column; gap: 12px;">
            <label class="field">
                <span class="fieldLabel">ФИО</span>
                <input type="text" name="full_name" class="input" required
                       value="<?= htmlspecialchars($old['full_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            </label>

            <label class="field">
                <span class="fieldLabel">Логин</span>
                <input type="text" name="login" class="input" required
                       value="<?= htmlspecialchars($old['login'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            </label>

            <label class="field">
                <span class="fieldLabel">Email (необязательно)</span>
                <input type="email" name="email" class="input"
                       value="<?= htmlspecialchars($old['email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            </label>

            <label class="field">
                <span class="fieldLabel">Телефон (необязательно)</span>
                <input type="text" name="phone" class="input"
                       value="<?= htmlspecialchars($old['phone'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            </label>

            <div class="grid2" style="gap: 10px;">
                <label class="field">
                    <span class="fieldLabel">Пароль</span>
                    <input type="password" name="password" class="input" required>
                </label>
                <label class="field">
                    <span class="fieldLabel">Повторите пароль</span>
                    <input type="password" name="password_confirm" class="input" required>
                </label>
            </div>

            <button class="btn btn primary" style="width: 100%; margin-top: 6px;">
                Создать аккаунт
            </button>

            <p class="muted" style="text-align: center; font-size: 13px; margin-top: 8px;">
                Уже есть аккаунт?
                <a href="login.php" style="color: #16a34a; text-decoration: none; font-weight: 600;">
                    Войти
                </a>
            </p>
        </form>
    </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>
