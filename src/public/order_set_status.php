<?php
require_once __DIR__ . '/auth.php';
$user = require_role('pharmacist');
global $pdo;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: pharmacist_orders.php');
    exit;
}

$order_id = (int)($_POST['order_id'] ?? 0);
$status   = $_POST['status'] ?? '';

$allowed = ['processing','ready','cancelled'];
if (!$order_id || !in_array($status, $allowed, true)) {
    die('Неверные данные');
}

$stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND clinic_id = ?");
$stmt->execute([$order_id, $user['clinic_id']]);
$order = $stmt->fetch();
if (!$order) {
    die('Заказ не найден');
}

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare("UPDATE orders SET status = ? WHERE id = ?");
    $stmt->execute([$status, $order_id]);

    $stmt = $pdo->prepare("
        INSERT INTO order_status_history (order_id, old_status, new_status, changed_by)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$order_id, $order['status'] ?? 'new', $status, $_SESSION['user_id'] ?? 0]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    die('Ошибка обновления статуса');
}

header('Location: pharmacist_orders.php');
exit;
