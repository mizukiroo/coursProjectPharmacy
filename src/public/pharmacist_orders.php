<?php
require_once 'auth.php';
$user = require_role('pharmacist');
global $pdo;

$pharmacistId = (int)$user['role_id'];

/* клиники фармацевта */
$stmt = $pdo->prepare("
  SELECT c.id, c.full_name
  FROM pharmacist_clinics pc
  JOIN clinics c ON c.id = pc.clinic_id
  WHERE pc.pharmacist_id = ?
");
$stmt->execute([$pharmacistId]);
$clinics = $stmt->fetchAll();

$clinicId = (int)($_GET['clinic_id'] ?? ($clinics[0]['id'] ?? 0));

include 'header.php';
?>

<div class="container">
    <h1>Заказы</h1>

    <form method="get" style="margin-bottom:15px;">
        <label>Аптека:</label>
        <select name="clinic_id">
            <?php foreach ($clinics as $c): ?>
                <option value="<?= $c['id'] ?>" <?= $c['id']==$clinicId?'selected':'' ?>>
                    <?= htmlspecialchars($c['full_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button class="herb-btn herb-btn-outline">Показать</button>
    </form>

    <?php
    $stmt = $pdo->prepare("
  SELECT o.*
  FROM orders o
  WHERE o.clinic_id = ?
  ORDER BY o.order_date DESC
");
    $stmt->execute([$clinicId]);
    $orders = $stmt->fetchAll();
    ?>

    <?php foreach ($orders as $o): ?>
        <div class="card">
            <div class="cardHeader">
                Заказ №<?= $o['id'] ?> — <?= $o['order_date'] ?>
            </div>

            <div>Статус: <b><?= $o['status'] ?></b></div>

            <div class="inlineForm" style="margin-top:10px;">
                <?php if ($o['status']=='new'): ?>
                    <form action="order_update_status.php" method="post">
                        <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
                        <input type="hidden" name="status" value="picked">
                        <button class="btn btn-secondary">Собран</button>
                    </form>
                <?php endif; ?>

                <?php if ($o['status']=='picked'): ?>
                    <form action="order_update_status.php" method="post">
                        <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
                        <input type="hidden" name="status" value="dispensed">
                        <button class="btn btn-primary">Выдан</button>
                    </form>
                <?php endif; ?>

                <form action="order_delete.php" method="post"
                      onsubmit="return confirm('Удалить заказ?');">
                    <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
                    <button class="btn btn-danger">Удалить</button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>

</div>
<?php include 'footer.php'; ?>
