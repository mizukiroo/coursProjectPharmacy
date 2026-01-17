<?php
require_once 'auth.php';
$user = require_role('pharmacist');
global $pdo;

$pharmacistId = (int)$user['role_id'];

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
    <h1>Накладные</h1>

    <form method="get">
        <label>Аптека:</label>
        <select name="clinic_id">
            <?php foreach ($clinics as $c): ?>
                <option value="<?= $c['id'] ?>" <?= $c['id']==$clinicId?'selected':'' ?>>
                    <?= htmlspecialchars($c['full_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button class="herb-btn herb-btn-outline">Выбрать</button>
    </form>

    <div class="card" style="margin-top:15px;">
        <div class="cardHeader">Новая накладная</div>
        <form action="receipt_create.php" method="post">
            <input type="hidden" name="clinic_id" value="<?= $clinicId ?>">

            <label>Лекарство</label>
            <select name="drug_id"></select>

            <label>Форма</label>
            <select name="form_id"></select>

            <label>Количество</label>
            <input type="number" name="quantity" min="1">

            <button class="btn btn-primary">Добавить</button>
        </form>
    </div>

    <?php
    $stmt = $pdo->prepare("
  SELECT * FROM receipts
  WHERE clinic_id = ?
  ORDER BY received_at DESC
");
    $stmt->execute([$clinicId]);
    $receipts = $stmt->fetchAll();
    ?>

    <?php foreach ($receipts as $r): ?>
        <div class="card">
            <div class="cardHeader">
                Накладная №<?= $r['id'] ?> — <?= $r['received_at'] ?>
            </div>
        </div>
    <?php endforeach; ?>

</div>
<?php include 'footer.php'; ?>
