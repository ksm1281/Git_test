<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../_helpers.php';

requireLogin();
header('Content-Type: application/json');

$stmt = $pdo->query("SELECT type, status, records_synced, message, date_added FROM erp_sync_log WHERE type = 'products' ORDER BY date_added DESC LIMIT 1");
$last = $stmt->fetch();

if ($last) {
    echo json_encode([
        'has_sync' => true,
        'status' => $last['status'],
        'records_synced' => (int)$last['records_synced'],
        'message' => $last['message'],
        'date_added' => $last['date_added'],
    ]);
} else {
    echo json_encode(['has_sync' => false]);
}
