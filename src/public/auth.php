<?php
// auth.php — логика авторизации и ролей
require_once __DIR__ . '/config.php';

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

    $stmt = $pdo->prepare("SELECT id, login, full_name, email FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    if (!$user) {
        return null;
    }

    $uid = $user['id'];
    $user['role'] = 'guest';
    $user['role_id'] = null;
    $user['clinic_id'] = null;

    // admin
    $stmt = $pdo->prepare("SELECT id FROM admins WHERE id_user = ?");
    $stmt->execute([$uid]);
    if ($row = $stmt->fetch()) {
        $user['role'] = 'admin';
        $user['role_id'] = $row['id'];
        return $cached = $user;
    }

    // doctor
    $stmt = $pdo->prepare("SELECT id FROM doctors WHERE id_user = ?");
    $stmt->execute([$uid]);
    if ($row = $stmt->fetch()) {
        $user['role'] = 'doctor';
        $user['role_id'] = $row['id'];
        return $cached = $user;
    }

    // pharmacist
    $stmt = $pdo->prepare("SELECT id, id_clinic FROM pharmacists WHERE id_user = ?");
    $stmt->execute([$uid]);
    if ($row = $stmt->fetch()) {
        $user['role'] = 'pharmacist';
        $user['role_id'] = $row['id'];
        $user['clinic_id'] = $row['id_clinic'];
        return $cached = $user;
    }

    // patient
    $stmt = $pdo->prepare("SELECT id FROM customers WHERE id_user = ?");
    $stmt->execute([$uid]);
    if ($row = $stmt->fetch()) {
        $user['role'] = 'patient';
        $user['role_id'] = $row['id'];
        return $cached = $user;
    }

    return $cached = $user;
}

function require_login(): array
{
    $user = get_current_user_data();
    if (!$user) {
        header('Location: login.php');
        exit;
    }
    return $user;
}

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
