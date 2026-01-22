<?php
require_once __DIR__ . '/auth.php';
$user = require_role('admin');
global $pdo;

$message = null;
$error   = null;

function role_options(): array
{
    return [
            'none'       => 'нет',
            'patient'    => 'пациент',
            'doctor'     => 'врач',
            'pharmacist' => 'фармацевт',
            'admin'      => 'админ',
    ];
}
function validate_role(string $role): bool
{
    return array_key_exists($role, role_options());
}
function human_role(array $u): string
{
    if (!empty($u['admin_id']))       return 'admin';
    if (!empty($u['doctor_id']))      return 'doctor';
    if (!empty($u['pharmacist_id']))  return 'pharmacist';
    if (!empty($u['customer_id']))    return 'patient';
    return 'none';
}

/** doctors.id_specialty NOT NULL → берём первую specialty */
function get_default_specialty_id(PDO $pdo): int
{
    $specId = (int)$pdo->query("SELECT id FROM specialties ORDER BY id LIMIT 1")->fetchColumn();
    if ($specId <= 0) {
        throw new RuntimeException('В таблице specialties нет записей. Добавь хотя бы одну специальность.');
    }
    return $specId;
}

/* -------------------------
   POST: создать/редактировать/роль/удаление
   ------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Создать аккаунт
    if ($action === 'create_user') {
        $login    = trim($_POST['login'] ?? '');
        $password = $_POST['password'] ?? '';
        $role     = $_POST['role'] ?? 'none';

        $fullName = trim($_POST['full_name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $phone    = trim($_POST['phone'] ?? '');

        if ($login === '' || $password === '') {
            $error = 'Логин и пароль обязательны.';
        } elseif (!validate_role($role)) {
            $error = 'Некорректная роль.';
        } else {
            try {
                $stmt = $pdo->prepare("SELECT id FROM users WHERE login = ? LIMIT 1");
                $stmt->execute([$login]);
                if ($stmt->fetch()) throw new RuntimeException('Такой логин уже занят.');

                $pdo->beginTransaction();

                $hash = password_hash($password, PASSWORD_DEFAULT);

                $stmt = $pdo->prepare("
                    INSERT INTO users (login, password_hash, full_name, phone, email)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                        $login,
                        $hash,
                        $fullName !== '' ? $fullName : null,
                        $phone !== '' ? $phone : null,
                        $email !== '' ? $email : null,
                ]);

                $newUserId = (int)$pdo->lastInsertId();

                if ($role === 'admin') {
                    $pdo->prepare("INSERT INTO admins (id_user) VALUES (?)")->execute([$newUserId]);
                } elseif ($role === 'patient') {
                    $pdo->prepare("INSERT INTO customers (id_user) VALUES (?)")->execute([$newUserId]);
                } elseif ($role === 'pharmacist') {
                    $pdo->prepare("INSERT INTO pharmacists (id_user) VALUES (?)")->execute([$newUserId]);
                } elseif ($role === 'doctor') {
                    $specId = get_default_specialty_id($pdo);
                    $pdo->prepare("INSERT INTO doctors (id_user, id_specialty) VALUES (?, ?)")->execute([$newUserId, $specId]);
                }

                $pdo->commit();
                $message = 'Аккаунт создан.';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = 'Ошибка при создании аккаунта: ' . $e->getMessage();
            }
        }
    }

    // Обновить пользователя (поиск пользователей по full_name, но редактировать можно всё)
    elseif ($action === 'update_user') {
        $userId      = (int)($_POST['user_id'] ?? 0);
        $login       = trim($_POST['login'] ?? '');
        $fullName    = trim($_POST['full_name'] ?? '');
        $email       = trim($_POST['email'] ?? '');
        $phone       = trim($_POST['phone'] ?? '');
        $newPassword = $_POST['new_password'] ?? '';

        if ($userId <= 0) {
            $error = 'Некорректный пользователь.';
        } elseif ($login === '') {
            $error = 'Логин обязателен.';
        } else {
            try {
                $stmt = $pdo->prepare("SELECT id FROM users WHERE login = ? AND id <> ? LIMIT 1");
                $stmt->execute([$login, $userId]);
                if ($stmt->fetch()) throw new RuntimeException('Такой логин уже занят.');

                $pdo->beginTransaction();

                if ($newPassword !== '') {
                    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("
                        UPDATE users
                        SET login=?, full_name=?, email=?, phone=?, password_hash=?
                        WHERE id=?
                    ");
                    $stmt->execute([
                            $login,
                            $fullName !== '' ? $fullName : null,
                            $email !== '' ? $email : null,
                            $phone !== '' ? $phone : null,
                            $hash,
                            $userId
                    ]);
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE users
                        SET login=?, full_name=?, email=?, phone=?
                        WHERE id=?
                    ");
                    $stmt->execute([
                            $login,
                            $fullName !== '' ? $fullName : null,
                            $email !== '' ? $email : null,
                            $phone !== '' ? $phone : null,
                            $userId
                    ]);
                }

                $pdo->commit();
                $message = 'Данные пользователя обновлены.';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = 'Ошибка при обновлении: ' . $e->getMessage();
            }
        }
    }

    // Сменить роль
    elseif ($action === 'change_role') {
        $userId  = (int)($_POST['user_id'] ?? 0);
        $newRole = $_POST['new_role'] ?? 'none';

        if ($userId <= 0) {
            $error = 'Некорректный пользователь.';
        } elseif (!validate_role($newRole)) {
            $error = 'Некорректная роль.';
        } else {
            try {
                $pdo->beginTransaction();

                $pdo->prepare("DELETE FROM admins      WHERE id_user = ?")->execute([$userId]);
                $pdo->prepare("DELETE FROM customers   WHERE id_user = ?")->execute([$userId]);
                $pdo->prepare("DELETE FROM doctors     WHERE id_user = ?")->execute([$userId]);
                $pdo->prepare("DELETE FROM pharmacists WHERE id_user = ?")->execute([$userId]);

                if ($newRole === 'admin') {
                    $pdo->prepare("INSERT INTO admins (id_user) VALUES (?)")->execute([$userId]);
                } elseif ($newRole === 'patient') {
                    $pdo->prepare("INSERT INTO customers (id_user) VALUES (?)")->execute([$userId]);
                } elseif ($newRole === 'pharmacist') {
                    $pdo->prepare("INSERT INTO pharmacists (id_user, id_clinic) VALUES (?, NULL)")->execute([$userId]);
                } elseif ($newRole === 'doctor') {
                    $specId = get_default_specialty_id($pdo);
                    $pdo->prepare("INSERT INTO doctors (id_user, id_specialty) VALUES (?, ?)")->execute([$userId, $specId]);
                }

                $pdo->commit();
                $message = 'Роль пользователя обновлена.';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = 'Ошибка при изменении роли: ' . $e->getMessage();
            }
        }
    }

    // Удалить пользователя
    elseif ($action === 'delete_user') {
        $userId = (int)($_POST['user_id'] ?? 0);

        if ($userId <= 0) {
            $error = 'Некорректный пользователь.';
        } elseif ($userId === (int)$user['id']) {
            $error = 'Нельзя удалить самого себя.';
        } else {
            try {
                $stmt = $pdo->prepare("CALL sp_delete_user_cascade(?)");
                $stmt->execute([$userId]);
                $stmt->closeCursor(); // важно после CALL

                $message = 'Пользователь и связанные данные удалены.';
            } catch (Throwable $e) {
                $error = 'Ошибка при удалении пользователя: ' . $e->getMessage();
            }
        }
    }


    // Удалить заказ
    elseif ($action === 'delete_order') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        try {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM order_items WHERE order_id = ?")->execute([$orderId]);
            $pdo->prepare("DELETE FROM orders WHERE id = ?")->execute([$orderId]);
            $pdo->commit();
            $message = 'Заказ удалён.';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = 'Ошибка при удалении заказа: ' . $e->getMessage();
        }
    }

    // Обновить заказ
    elseif ($action === 'update_order') {
        $orderId    = (int)($_POST['order_id'] ?? 0);
        $orderDate  = $_POST['order_date'] ?? null;
        $total      = ($_POST['total_amount'] ?? '') !== '' ? $_POST['total_amount'] : null;

        try {
            $stmt = $pdo->prepare("
                UPDATE orders 
                SET order_date = :dt, total_amount = :total
                WHERE id = :id
            ");
            $stmt->execute([
                    'dt'    => $orderDate,
                    'total' => $total,
                    'id'    => $orderId,
            ]);
            $message = 'Заказ обновлён.';
        } catch (Throwable $e) {
            $error = 'Ошибка при обновлении заказа: ' . $e->getMessage();
        }
    }
}

/* -------------------------
   GET: поиск вместо вывода всего
   ------------------------- */
$scope = $_GET['scope'] ?? 'users';   // users | orders | drugs
$qRaw  = trim($_GET['q'] ?? '');

if (!in_array($scope, ['users', 'orders', 'drugs'], true)) $scope = 'users';

$users  = [];
$orders = [];
$drugs  = [];

try {
    if ($qRaw !== '') {
        if ($scope === 'users') {
            // Поиск пользователей по FULL NAME
            $stmt = $pdo->prepare("
                SELECT 
                    u.*,
                    c.id AS customer_id,
                    d.id AS doctor_id,
                    p.id AS pharmacist_id,
                    a.id AS admin_id
                FROM users u
                LEFT JOIN customers   c ON c.id_user = u.id
                LEFT JOIN doctors     d ON d.id_user = u.id
                LEFT JOIN pharmacists p ON p.id_user = u.id
                LEFT JOIN admins      a ON a.id_user = u.id
                WHERE (u.full_name LIKE :q)
                ORDER BY u.id DESC
                LIMIT 80
            ");
            $stmt->execute(['q' => '%' . $qRaw . '%']);
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        if ($scope === 'orders') {
            // число -> по id заказа, текст -> по ФИО пациента
            if (ctype_digit($qRaw)) {
                $stmt = $pdo->prepare("
                    SELECT 
                        o.*,
                        u.full_name AS customer_name,
                        cl.short_name AS clinic_name
                    FROM orders o
                    LEFT JOIN customers c  ON c.id = o.customer_id
                    LEFT JOIN users u      ON u.id = c.id_user
                    LEFT JOIN clinics cl   ON cl.id = o.clinic_id
                    WHERE o.id = :id
                    LIMIT 30
                ");
                $stmt->execute(['id' => (int)$qRaw]);
            } else {
                $stmt = $pdo->prepare("
                    SELECT 
                        o.*,
                        u.full_name AS customer_name,
                        cl.short_name AS clinic_name
                    FROM orders o
                    LEFT JOIN customers c  ON c.id = o.customer_id
                    LEFT JOIN users u      ON u.id = c.id_user
                    LEFT JOIN clinics cl   ON cl.id = o.clinic_id
                    WHERE u.full_name LIKE :q
                    ORDER BY o.order_date DESC, o.id DESC
                    LIMIT 80
                ");
                $stmt->execute(['q' => '%' . $qRaw . '%']);
            }
            $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        if ($scope === 'drugs') {
            // Лекарства — строго по НОМЕРУ (id)
            if (!ctype_digit($qRaw)) {
                $error = 'Поиск лекарств: введи номер (ID), например 12.';
            } else {
                $stmt = $pdo->prepare("
                    SELECT d.id, d.name, d.atc_code
                    FROM drugs d
                    WHERE d.id = :id
                    LIMIT 30
                ");
                $stmt->execute(['id' => (int)$qRaw]);
                $drugs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }
    }
} catch (Throwable $e) {
    $error = 'Ошибка поиска: ' . $e->getMessage();
}

include __DIR__ . '/header.php';
?>

<main class="page page-admin">
    <div class="container pageHeader">
        <h1>Админ-панель</h1>
        <p class="muted">Поиск вместо огромных списков. Создание аккаунтов, роли, редактирование.</p>
    </div>

    <div class="container" style="max-width: 980px;">

        <?php if ($message): ?>
            <div class="successBox"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="errorBox"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <!-- Поиск -->
        <div class="card adminCard">
            <div class="rowBetween" style="margin-bottom: 10px;">
                <div>
                    <div class="adminTitle">Поиск</div>
                    <div class="muted adminSub">Выбери раздел и введи запрос.</div>
                </div>
                <div class="muted adminHint">
                    Пользователи — по ФИО, Лекарства — по ID, Заказы — по ID или ФИО.
                </div>
            </div>

            <form method="get" class="adminSearchRow">
                <select name="scope" class="input adminSearchSelect">
                    <option value="users"  <?= $scope === 'users' ? 'selected' : '' ?>>Пользователи</option>
                    <option value="orders" <?= $scope === 'orders' ? 'selected' : '' ?>>Заказы</option>
                    <option value="drugs"  <?= $scope === 'drugs' ? 'selected' : '' ?>>Лекарства</option>
                </select>

                <input type="text" name="q" class="input adminSearchInput"
                       placeholder="Введите запрос..." value="<?= htmlspecialchars($qRaw) ?>">

                <button class="btn btn primary adminSearchBtn" type="submit">Найти</button>

                <?php if ($qRaw !== ''): ?>
                    <a class="btn adminSearchClear" href="admin_dashboard.php">Сбросить</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Создание аккаунта -->
        <div class="card adminCard">
            <div class="rowBetween" style="margin-bottom: 10px;">
                <div>
                    <div class="adminTitle">Создать аккаунт</div>
                    <div class="muted adminSub">Обязательные: логин, пароль, роль. Email/телефон — можно пустыми.</div>
                </div>
            </div>

            <form method="post" class="adminCreateGrid">
                <input type="hidden" name="action" value="create_user">

                <label class="field">
                    <span class="fieldLabel">Логин *</span>
                    <input type="text" name="login" class="input" required>
                </label>

                <label class="field">
                    <span class="fieldLabel">Пароль *</span>
                    <input type="password" name="password" class="input" required>
                </label>

                <label class="field">
                    <span class="fieldLabel">Роль *</span>
                    <select name="role" class="input" required>
                        <?php foreach (role_options() as $k => $label): ?>
                            <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="field">
                    <span class="fieldLabel">ФИО (необязательно)</span>
                    <input type="text" name="full_name" class="input">
                </label>

                <label class="field">
                    <span class="fieldLabel">Email (необязательно)</span>
                    <input type="email" name="email" class="input">
                </label>

                <label class="field">
                    <span class="fieldLabel">Телефон (необязательно)</span>
                    <input type="text" name="phone" class="input">
                </label>

                <div class="adminCreateActions">
                    <button class="btn btn primary" type="submit">Создать</button>
                </div>
            </form>
        </div>

        <!-- Результаты поиска -->
        <?php if ($qRaw === ''): ?>
            <div class="card adminCard">
                <div class="muted">Введите запрос в поиске сверху — и результаты появятся здесь.</div>
            </div>
        <?php endif; ?>

        <?php if ($scope === 'users' && $qRaw !== ''): ?>
            <div class="card adminCard">
                <div class="rowBetween" style="margin-bottom: 10px;">
                    <div class="adminTitle">Пользователи: найдено <?= count($users) ?></div>
                </div>

                <?php if (!$users): ?>
                    <div class="muted">Ничего не найдено по ФИО.</div>
                <?php else: ?>
                    <div class="tableWrapper">
                        <table class="table adminTable">
                            <thead>
                            <tr>
                                <th>ID</th>
                                <th>Логин</th>
                                <th>ФИО</th>
                                <th>Email</th>
                                <th>Телефон</th>
                                <th>Роль</th>
                                <th>Сменить роль</th>
                                <th>Редактировать</th>
                                <th>Удалить</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($users as $u):
                                $currentRole = human_role($u);
                                ?>
                                <tr>
                                    <td><?= (int)$u['id'] ?></td>
                                    <td><?= htmlspecialchars($u['login']) ?></td>
                                    <td><?= htmlspecialchars($u['full_name'] ?? '—') ?></td>
                                    <td><?= htmlspecialchars($u['email'] ?? '—') ?></td>
                                    <td><?= htmlspecialchars($u['phone'] ?? '—') ?></td>
                                    <td><span class="adminRoleBadge"><?= htmlspecialchars($currentRole) ?></span></td>

                                    <td>
                                        <form method="post" class="adminInlineForm">
                                            <input type="hidden" name="action" value="change_role">
                                            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                            <select name="new_role" class="input adminInlineSelect">
                                                <?php foreach (role_options() as $k => $label): ?>
                                                    <option value="<?= htmlspecialchars($k) ?>" <?= $currentRole === $k ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($label) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button class="btn adminInlineBtn" type="submit">OK</button>
                                        </form>
                                    </td>

                                    <td>
                                        <details class="adminDetails">
                                            <summary class="adminDetailsSummary">Открыть</summary>

                                            <form method="post" class="adminEditForm">
                                                <input type="hidden" name="action" value="update_user">
                                                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">

                                                <div class="adminEditGrid">
                                                    <label class="field">
                                                        <span class="fieldLabel">Логин *</span>
                                                        <input type="text" name="login" class="input" required value="<?= htmlspecialchars($u['login']) ?>">
                                                    </label>

                                                    <label class="field">
                                                        <span class="fieldLabel">ФИО</span>
                                                        <input type="text" name="full_name" class="input" value="<?= htmlspecialchars($u['full_name'] ?? '') ?>">
                                                    </label>

                                                    <label class="field">
                                                        <span class="fieldLabel">Email</span>
                                                        <input type="email" name="email" class="input" value="<?= htmlspecialchars($u['email'] ?? '') ?>">
                                                    </label>

                                                    <label class="field">
                                                        <span class="fieldLabel">Телефон</span>
                                                        <input type="text" name="phone" class="input" value="<?= htmlspecialchars($u['phone'] ?? '') ?>">
                                                    </label>

                                                    <label class="field adminSpan2">
                                                        <span class="fieldLabel">Новый пароль (если нужно)</span>
                                                        <input type="password" name="new_password" class="input" placeholder="оставь пустым, чтобы не менять">
                                                    </label>
                                                </div>

                                                <div class="actions" style="justify-content: flex-end;">
                                                    <button class="btn" type="submit">Сохранить</button>
                                                </div>
                                            </form>
                                        </details>
                                    </td>

                                    <td>
                                        <?php if ((int)$u['id'] !== (int)$user['id']): ?>
                                            <form method="post" onsubmit="return confirm('Удалить пользователя и все его данные?');">
                                                <input type="hidden" name="action" value="delete_user">
                                                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                                <button class="btn danger" type="submit">Удалить</button>
                                            </form>
                                        <?php else: ?>
                                            <span class="muted">Это вы</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($scope === 'orders' && $qRaw !== ''): ?>
            <div class="card adminCard">
                <div class="rowBetween" style="margin-bottom: 10px;">
                    <div class="adminTitle">Заказы: найдено <?= count($orders) ?></div>
                </div>

                <?php if (!$orders): ?>
                    <div class="muted">Ничего не найдено.</div>
                <?php else: ?>
                    <div class="tableWrapper">
                        <table class="table adminTable">
                            <thead>
                            <tr>
                                <th>ID</th>
                                <th>Дата</th>
                                <th>Пациент</th>
                                <th>Аптека</th>
                                <th>Сохранить</th>
                                <th>Удалить</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($orders as $o): ?>
                                <tr>
                                    <td><?= (int)$o['id'] ?></td>
                                    <td>
                                        <form method="post" class="adminInlineForm">
                                            <input type="hidden" name="action" value="update_order">
                                            <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                                            <input type="date" name="order_date" value="<?= htmlspecialchars($o['order_date']) ?>" class="input adminDateInput">
                                    </td>
                                    <td><?= htmlspecialchars($o['customer_name'] ?? '—') ?></td>
                                    <td><?= htmlspecialchars($o['clinic_name'] ?? '—') ?></td>

                                    <td>
                                        <button class="btn adminInlineBtn" type="submit">Сохранить</button>
                                        </form>
                                    </td>
                                    <td>
                                        <form method="post" onsubmit="return confirm('Удалить этот заказ?');">
                                            <input type="hidden" name="action" value="delete_order">
                                            <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                                            <button class="btn danger" type="submit">Удалить</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($scope === 'drugs' && $qRaw !== ''): ?>
            <div class="card adminCard">
                <div class="rowBetween" style="margin-bottom: 10px;">
                    <div class="adminTitle">Лекарства: найдено <?= count($drugs) ?></div>
                </div>

                <?php if (!$drugs): ?>
                    <div class="muted">По этому ID лекарство не найдено.</div>
                <?php else: ?>
                    <div class="tableWrapper">
                        <table class="table adminTable">
                            <thead>
                            <tr>
                                <th>ID</th>
                                <th>Название</th>
                                <th>ATC</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($drugs as $d): ?>
                                <tr>
                                    <td><?= (int)$d['id'] ?></td>
                                    <td><?= htmlspecialchars($d['name']) ?></td>
                                    <td><?= htmlspecialchars($d['atc_code']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    </div>
</main>

<?php include __DIR__ . '/footer.php'; ?>
