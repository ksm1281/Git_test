<?php
/**
 * Cron endpoint for tracking Nova Poshta delivery status.
 * Uses NP API getStatusDocuments to check TTN statuses.
 * 
 * Usage (crontab):
 *   0 */6 * * * curl -s "https://example.com/ERP/api/cron-track-np.php?key=YOUR_CRON_SECRET"
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../_helpers.php';

header('Content-Type: text/plain; charset=utf-8');

$key = trim($_GET['key'] ?? '');
$allowedKey = defined('CRON_SECRET') ? CRON_SECRET : '';
$isCli = (PHP_SAPI === 'cli');

if (!$isCli) {
    if (!$allowedKey || $key !== $allowedKey) {
        if (empty($allowedKey)) {
            echo "ERROR: CRON_SECRET not configured in config.local.php\n";
        } else {
            echo "ERROR: Invalid or missing key parameter\n";
        }
        exit(1);
    }
}

$stmt = $pdo->prepare("SELECT `value` FROM erp_settings WHERE `key` = 'np_api_key'");
$stmt->execute();
$apiKey = $stmt->fetchColumn();

if (!$apiKey) {
    echo "ERROR: NP API key not configured. Add in Settings.\n";
    exit(1);
}

$apiUrl = 'https://api.novaposhta.ua/v2.0/json/';

$stmt = $pdo->query("
    SELECT order_id, ttn_number, delivery_status, payment_method 
    FROM erp_orders 
    WHERE delivery_method = 'nova_poshta' 
    AND ttn_number IS NOT NULL 
    AND ttn_number != '' 
    AND delivery_status NOT IN ('delivered', 'returned')
");
$orders = $stmt->fetchAll();

if (empty($orders)) {
    echo "No active NP shipments to track.\n";
    exit(0);
}

$ttnData = [];
$orderMap = [];
foreach ($orders as $o) {
    $ttn = trim($o['ttn_number']);
    if (!$ttn) continue;
    $ttnData[] = ['DocumentNumber' => $ttn];
    $orderMap[$ttn] = $o;
}

$body = json_encode([
    'apiKey' => $apiKey,
    'modelName' => 'TrackingDocument',
    'calledMethod' => 'getStatusDocuments',
    'methodProperties' => [
        'Documents' => $ttnData,
    ],
], JSON_UNESCAPED_UNICODE);

$ch = curl_init($apiUrl);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    echo "ERROR: NP API returned HTTP $httpCode\n";
    exit(1);
}

$data = json_decode($response, true);

if (!isset($data['success']) || !$data['success']) {
    echo "ERROR: NP API error: " . ($data['errors'][0] ?? 'unknown') . "\n";
    exit(1);
}

$npStatusMap = [
    1 => 'sending',
    2 => 'sending',
    3 => 'in_transit',
    4 => 'in_transit',
    5 => 'arrived',
    6 => 'arrived',
    7 => 'arrived',
    8 => 'delivered',
    9 => 'returned',
    10 => 'returned',
    11 => 'returned',
];

$updated = 0;
$paymentsCreated = 0;

foreach ($data['data'] as $doc) {
    $ttn = $doc['Number'];
    $statusCode = (int)($doc['StatusCode'] ?? 0);

    $order = $orderMap[$ttn] ?? null;
    if (!$order) continue;

    $newStatus = $npStatusMap[$statusCode] ?? null;
    if (!$newStatus) continue;
    if ($newStatus === $order['delivery_status']) continue;

    $orderId = (int)$order['order_id'];

    $pdo->beginTransaction();

    $pdo->prepare("UPDATE erp_orders SET delivery_status = ? WHERE order_id = ?")
        ->execute([$newStatus, $orderId]);

    if ($newStatus === 'delivered') {
        $oldStmt = $pdo->prepare("SELECT status_name FROM erp_orders WHERE order_id = ?");
        $oldStmt->execute([$orderId]);
        $oldStatus = $oldStmt->fetchColumn();

        if ($oldStatus !== 'delivered' && $oldStatus !== 'cancelled') {
            $pdo->prepare("UPDATE erp_orders SET status_name = 'delivered' WHERE order_id = ?")->execute([$orderId]);

            if (!in_array($oldStatus, ['processed', 'shipped', 'delivered'])) {
                $pStmt = $pdo->prepare("SELECT product_id, quantity FROM erp_order_products WHERE order_id = ?");
                $pStmt->execute([$orderId]);
                foreach ($pStmt as $op) {
                    $costPrice = getProductCostPrice($pdo, $op['product_id']);
                    $pdo->prepare("INSERT INTO erp_stock_moves (product_id, type, quantity, cost_price, reference_type, reference_id, user_id, notes) VALUES (?, 'out', ?, ?, 'order', ?, 1, ?)")
                        ->execute([$op['product_id'], $op['quantity'], $costPrice, $orderId, 'Автоматичне відстеження НП, замовлення #' . $orderId]);
                }
            }
        }

        $npDate = substr($doc['RecipientDateTime'] ?? $doc['ScheduledDeliveryDate'] ?? date('Y-m-d'), 0, 10);
        $npAmount = (float)($doc['AmountToPay'] ?? 0);
        if (autoRegisterNpPayment($pdo, $orderId, $npDate, $npAmount > 0 ? $npAmount : null)) {
            $paymentsCreated++;
        }
    }

    $pdo->commit();
    $updated++;
}

echo "Tracked " . count($ttnData) . " TTNs. Updated: {$updated}, payments created: {$paymentsCreated}\n";
