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
        'agree'     => false,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $login     = trim($_POST['login'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $phone     = trim($_POST['phone'] ?? '');
    $password  = $_POST['password'] ?? '';
    $password2 = $_POST['password_confirm'] ?? '';
    $agree     = (isset($_POST['agree']) && (string)$_POST['agree'] === '1');

    $old['full_name'] = $full_name;
    $old['login']     = $login;
    $old['email']     = $email;
    $old['phone']     = $phone;
    $old['agree']     = $agree;

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

    if (!$agree) {
        $errors[] = 'Для регистрации необходимо согласиться на обработку персональных данных.';
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

            <label class="field" style="display: flex; gap: 10px; align-items: flex-start;">
                <input type="checkbox" name="agree" value="1" required
                       style="margin-top: 4px;"
                        <?= !empty($old['agree']) ? 'checked' : '' ?>>
                <span class="muted" style="font-size: 13px; line-height: 1.35;">
                    Я согласен(на) на обработку персональных данных и ознакомлен(а) с <a href="#" id="privacyPolicyLink" style="color: #16a34a; text-decoration: none; font-weight: 600;">Политикой конфиденциальности</a>
                    </span>
            </label>

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


<!-- Privacy Policy Modal -->
<style>
    .modalOverlay {
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,.45);
        display: none;
        align-items: center;
        justify-content: center;
        padding: 16px;
        z-index: 9999;
    }
    .modalOverlay.isOpen { display: flex; }
    .modalWindow {
        width: 100%;
        max-width: 720px;
        max-height: 82vh;
        overflow: auto;
        background: #fff;
        border-radius: 16px;
        box-shadow: 0 20px 60px rgba(0,0,0,.25);
        border: 1px solid rgba(0,0,0,.08);
    }
    .modalHeader {
        position: sticky;
        top: 0;
        background: #fff;
        border-bottom: 1px solid rgba(0,0,0,.08);
        padding: 14px 16px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
    }
    .modalTitle { margin: 0; font-size: 16px; font-weight: 700; }
    .modalClose {
        border: 0;
        background: transparent;
        cursor: pointer;
        font-size: 20px;
        line-height: 1;
        padding: 6px 10px;
        border-radius: 10px;
    }
    .modalClose:hover { background: rgba(0,0,0,.06); }
    .modalBody { padding: 14px 16px 18px; font-size: 13px; line-height: 1.55; color: #111827; }
    .modalBody h3 { margin: 14px 0 8px; font-size: 14px; }
    .modalBody ul { margin: 8px 0 8px 18px; }
    .modalBody li { margin: 6px 0; }
    .modalNote { color: #6b7280; font-size: 12px; margin-top: 10px; }
</style>

<div class="modalOverlay" id="privacyPolicyModal" aria-hidden="true">
    <div class="modalWindow" role="dialog" aria-modal="true" aria-labelledby="privacyPolicyTitle">
        <div class="modalHeader">
            <h2 class="modalTitle" id="privacyPolicyTitle">Политика конфиденциальности</h2>
            <button type="button" class="modalClose" id="privacyPolicyClose" aria-label="Закрыть">×</button>
        </div>
        <div class="modalBody">
            <p><strong>Дата вступления в силу:</strong> <?= date('d.m.Y') ?></p>

            <p>
                Настоящая Политика конфиденциальности описывает, какие персональные данные мы собираем при регистрации и
                использовании сервиса, для каких целей обрабатываем, как защищаем и какие права есть у пользователя.
            </p>

            <h3>1. Какие данные мы собираем</h3>
            <ul>
                <li>Обязательные: ФИО, логин, пароль (хранится в виде хеша).</li>
                <li>Необязательные: email, телефон.</li>
            </ul>

            <h3>2. Цели обработки</h3>
            <ul>
                <li>Создание и ведение учетной записи пользователя.</li>
                <li>Предоставление доступа к функциям сервиса (например, просмотр рецептов, бронирование льготных лекарств).</li>
                <li>Связь с пользователем по вопросам работы сервиса (если указан email/телефон).</li>
                <li>Исполнение требований законодательства.</li>
            </ul>

            <h3>3. Правовые основания</h3>
            <ul>
                <li>Ваше согласие на обработку персональных данных.</li>
                <li>Необходимость исполнения договора/оказания услуг.</li>
                <li>Законные интересы оператора, если это не нарушает ваши права.</li>
            </ul>

            <h3>4. Условия обработки и хранения</h3>
            <ul>
                <li>Мы обрабатываем данные с использованием средств автоматизации и без них.</li>
                <li>Срок хранения: пока существует учетная запись и/или пока это необходимо для целей обработки и по закону.</li>
                <li>Пароли не хранятся в открытом виде - используется криптографический хеш.</li>
            </ul>

            <h3>5. Передача третьим лицам</h3>
            <p>
                Мы не продаем персональные данные. Передача возможна только:
            </p>
            <ul>
                <li>подрядчикам/провайдерам (хостинг, рассылка), если это необходимо для работы сервиса и при соблюдении мер защиты;</li>
                <li>по законному запросу государственных органов;</li>
            </ul>

            <h3>6. Права пользователя</h3>
            <ul>
                <li>Запросить доступ к вашим данным, их исправление или удаление.</li>
                <li>Отозвать согласие на обработку.</li>
                <li>Ограничить обработку в случаях, предусмотренных законом.</li>
            </ul>
            <p>
            </p>

            <h3>7. Меры защиты</h3>
            <ul>
                <li>Ограничение доступа к данным, разграничение прав.</li>
                <li>Хеширование чувствительных данных.</li>
                <li>Мониторинг и обновление программного обеспечения.</li>
            </ul>

            <h3>9. Изменения политики</h3>
            <p>
                Мы можем обновлять Политику. Актуальная версия публикуется здесь, а дата вступления в силу указана в начале документа.
            </p>
        </div>
    </div>
</div>

<script>
    (function () {
        const link = document.getElementById('privacyPolicyLink');
        const overlay = document.getElementById('privacyPolicyModal');
        const closeBtn = document.getElementById('privacyPolicyClose');

        if (!link || !overlay || !closeBtn) return;

        function openModal() {
            overlay.classList.add('isOpen');
            overlay.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
            closeBtn.focus();
        }

        function closeModal() {
            overlay.classList.remove('isOpen');
            overlay.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
            link.focus();
        }

        link.addEventListener('click', function (e) {
            e.preventDefault();
            openModal();
        });

        closeBtn.addEventListener('click', function () {
            closeModal();
        });

        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) closeModal();
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && overlay.classList.contains('isOpen')) {
                closeModal();
            }
        });
    })();
</script>
<?php include __DIR__ . '/footer.php'; ?>
