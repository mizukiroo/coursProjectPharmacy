<?php
require_once __DIR__ . '/auth.php';
$user = require_role('pharmacist');
global $pdo;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: pharmacist_orders.php');
    exit;
}

$order_id = (int)($_POST['order_id'] ?? 0);
if (!$order_id) {
    die('Неверный ID заказа');
}

$stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND clinic_id = ?");
$stmt->execute([$order_id, $user['clinic_id']]);
$order = $stmt->fetch();
if (!$order) {
    die('Заказ не найден');
}

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare("
        UPDATE orders
           SET status = 'dispensed',
               dispensed_by = ?,
               dispensed_at = NOW()
         WHERE id = ?
    ");
    $stmt->execute([$user['role_id'], $order_id]);

    $stmt = $pdo->prepare("
        INSERT INTO order_status_history (order_id, old_status, new_status, changed_by)
        VALUES (?, ?, 'dispensed', ?)
    ");
    $stmt->execute([$order_id, $order['status'] ?? 'new', $_SESSION['user_id'] ?? 0]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    die('Ошибка выдачи заказа');
}

header('Location: pharmacist_orders.php');
exit;
