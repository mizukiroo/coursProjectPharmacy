<?php
require_once __DIR__ . '/auth.php';
$user = require_role('pharmacist');
global $pdo;

header('Content-Type: application/json; charset=utf-8');

$kind = $_GET['kind'] ?? '';

if ($kind === 'drugs') {
    $stmt = $pdo->query("SELECT id, name FROM drugs ORDER BY name");
    echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($kind === 'forms') {
    $drugId = (int)($_GET['drug_id'] ?? 0);
    $stmt = $pdo->prepare("
    SELECT f.id, f.form_name
    FROM drug_forms df
    JOIN forms f ON f.id = df.form_id
    WHERE df.drug_id = ?
    ORDER BY f.form_name
  ");
    $stmt->execute([$drugId]);
    echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['error' => 'bad request'], JSON_UNESCAPED_UNICODE);
