<?php
require_once __DIR__ . '/auth.php';
$user = require_role('pharmacist');
global $pdo;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: pharmacist_orders.php');
    exit;
}

$pharmacistId = (int)($user['role_id'] ?? 0);
$orderId      = (int)($_POST['order_id'] ?? 0);
$newStatus    = (string)($_POST['status'] ?? '');

$allowed = ['picked', 'dispensed', 'cancelled'];
if ($pharmacistId <= 0 || $orderId <= 0 || !in_array($newStatus, $allowed, true)) {
    die('Неверные данные');
}

// Берём заказ + проверяем, что фармацевт реально привязан к аптеке этого заказа
$st = $pdo->prepare("
    SELECT o.id, o.status, o.clinic_id
    FROM orders o
    JOIN pharmacist_clinics pc ON pc.clinic_id = o.clinic_id
    WHERE o.id = ? AND pc.pharmacist_id = ?
");
$st->execute([$orderId, $pharmacistId]);
$order = $st->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    die('Заказ не найден или нет доступа к аптеке');
}

$oldStatus = (string)($order['status'] ?? 'new');
$clinicId  = (int)($order['clinic_id'] ?? 0);

// Простые правила переходов (чтобы не ломать логику)
// new -> picked -> dispensed
// new/picked -> cancelled
// dispensed/cancelled уже не трогаем
if ($oldStatus === 'dispensed' || $oldStatus === 'cancelled') {
    header('Location: pharmacist_orders.php?clinic_id=' . $clinicId);
    exit;
}

if ($newStatus === 'dispensed' && $oldStatus !== 'picked') {
    die('Нельзя выдать заказ, который не собран');
}

if ($newStatus === 'picked' && $oldStatus !== 'new') {
    header('Location: pharmacist_orders.php?clinic_id=' . $clinicId);
    exit;
}

try {
    $st = $pdo->prepare("UPDATE orders SET status = ? WHERE id = ?");
    $st->execute([$newStatus, $orderId]);
} catch (Throwable $e) {
    die('Ошибка обновления статуса: ' . htmlspecialchars($e->getMessage()));
}

header('Location: pharmacist_orders.php?clinic_id=' . $clinicId);
exit;
