<?php
// auth.php — логика авторизации и ролей

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/config.php';

/**
 * Возвращает данные текущего пользователя или null, если не залогинен.
 */
function get_current_user_data(): ?array
{
    global $pdo;

    if (empty($_SESSION['user_id'])) {
        return null;
    }

    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $userId = (int)$_SESSION['user_id'];

    // базовые данные из users
    $stmt = $pdo->prepare("
        SELECT id, login, full_name, email
        FROM users
        WHERE id = ?
    ");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        return null;
    }

    $uid = (int)$user['id'];

    $user['role']    = 'guest';
    $user['role_id'] = null;

    // admin
    $stmt = $pdo->prepare("SELECT id FROM admins WHERE id_user = ?");
    $stmt->execute([$uid]);
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $user['role']    = 'admin';
        $user['role_id'] = (int)$row['id'];
        return $cached = $user;
    }

    // doctor
    $stmt = $pdo->prepare("SELECT id FROM doctors WHERE id_user = ?");
    $stmt->execute([$uid]);
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $user['role']    = 'doctor';
        $user['role_id'] = (int)$row['id'];
        return $cached = $user;
    }

    // pharmacist — БЕЗ id_clinic
    $stmt = $pdo->prepare("SELECT id FROM pharmacists WHERE id_user = ?");
    $stmt->execute([$uid]);
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $user['role']    = 'pharmacist';
        $user['role_id'] = (int)$row['id'];
        return $cached = $user;
    }

    // patient
    $stmt = $pdo->prepare("SELECT id FROM customers WHERE id_user = ?");
    $stmt->execute([$uid]);
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $user['role']    = 'patient';
        $user['role_id'] = (int)$row['id'];
        return $cached = $user;
    }

    return $cached = $user;
}

/**
 * Требует авторизацию.
 */
function require_login(): array
{
    $user = get_current_user_data();
    if (!$user) {
        header('Location: login.php');
        exit;
    }
    return $user;
}

/**
 * Требует одну из ролей.
 */
function require_role(string ...$roles): array
{
    $user = require_login();
    if (!in_array($user['role'], $roles, true)) {
        http_response_code(403);
        echo "Доступ запрещен";
        exit;
    }
    return $user;
}
