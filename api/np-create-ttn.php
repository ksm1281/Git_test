<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../_helpers.php';

header('Content-Type: application/json; charset=utf-8');
requireLogin();

if (!isAdmin() && !isManager()) {
    echo json_encode(['error' => 'Недостатньо прав']);
    exit;
}

$orderId = (int)($_POST['order_id'] ?? 0);
$weight = (float)($_POST['weight'] ?? 1);
$seats = (int)($_POST['seats'] ?? 1);

if (!$orderId) {
    echo json_encode(['error' => 'Невірний ID замовлення']);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM erp_orders WHERE order_id = ?");
$stmt->execute([$orderId]);
$order = $stmt->fetch();

if (!$order) {
    echo json_encode(['error' => 'Замовлення не знайдено']);
    exit;
}

$stmt = $pdo->prepare("SELECT `key`, `value` FROM erp_settings WHERE `key` IN ('np_api_key', 'np_sender_city_ref', 'np_sender_warehouse_ref', 'np_sender_phone')");
$set = [];
foreach ($stmt as $row) {
    $set[$row['key']] = $row['value'];
}

$apiKey = $set['np_api_key'] ?? '';
$senderCityRef = $set['np_sender_city_ref'] ?? '';
$senderWarehouseRef = $set['np_sender_warehouse_ref'] ?? '';
$senderPhone = $set['np_sender_phone'] ?? '';

if (!$apiKey || !$senderCityRef || !$senderWarehouseRef || !$senderPhone) {
    echo json_encode(['error' => 'Налаштуйте відправника НП у Налаштуваннях (API ключ, місто, відділення, телефон)']);
    exit;
}

$cityRef = $order['np_city_ref'] ?? '';
$warehouseRef = $order['np_warehouse_ref'] ?? '';
$phone = $order['telephone'] ?? '';
$recipientName = $order['customer_name'] ?? '';

if (!$cityRef || !$warehouseRef) {
    echo json_encode(['error' => 'Не вказано місто або відділення отримувача. Оберіть через автопошук у формі замовлення.']);
    exit;
}
if (!$phone) {
    echo json_encode(['error' => 'Не вказано телефон отримувача']);
    exit;
}
if (!$recipientName) {
    echo json_encode(['error' => 'Не вказано ім\'я отримувача']);
    exit;
}

$apiUrl = 'https://api.novaposhta.ua/v2.0/json/';

function npApiCall($apiUrl, $apiKey, $model, $method, $props) {
    $body = json_encode([
        'apiKey' => $apiKey,
        'modelName' => $model,
        'calledMethod' => $method,
        'methodProperties' => $props,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $res = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http !== 200) return ['error' => "HTTP $http"];
    $data = json_decode($res, true);
    if (!isset($data['success']) || !$data['success']) {
        return ['error' => $data['errors'][0] ?? 'Невідома помилка API'];
    }
    return ['data' => $data['data']];
}

$itemsList = '';
$stmt = $pdo->prepare("SELECT p.name, op.quantity FROM erp_order_products op LEFT JOIN erp_products p ON op.product_id = p.product_id WHERE op.order_id = ?");
$stmt->execute([$orderId]);
$items = $stmt->fetchAll();
foreach ($items as $i) {
    $itemsList .= ($itemsList ? ', ' : '') . ($i['name'] ?: 'Товар') . ' × ' . number_format((float)$i['quantity'], 0);
}
$description = mb_substr($itemsList ?: 'Товар згідно замовлення #' . $orderId, 0, 100);

$cost = max(1, (int)round((float)$order['total']));

// 1. Find or create recipient counterparty
$result = npApiCall($apiUrl, $apiKey, 'Counterparty', 'getCounterparties', [
    'CounterpartyProperty' => 'Recipient',
    'Page' => '1',
]);
if (isset($result['error'])) {
    echo json_encode(['error' => 'Помилка пошуку отримувача: ' . $result['error']]);
    exit;
}

$recipientRef = null;
$contactRef = null;
foreach ($result['data'] as $cp) {
    if ($cp['Phones'] === $phone && strpos($cp['Description'] ?? '', $recipientName) !== false) {
        $recipientRef = $cp['Ref'];
        $contactRef = $cp['ContactPerson']['data'][0]['Ref'] ?? null;
        break;
    }
}

if (!$recipientRef) {
    $result = npApiCall($apiUrl, $apiKey, 'Counterparty', 'save', [
        'CounterpartyProperty' => 'Recipient',
        'Name' => $recipientName,
        'Phone' => $phone,
    ]);
    if (isset($result['error'])) {
        echo json_encode(['error' => 'Помилка створення отримувача: ' . $result['error']]);
        exit;
    }
    $recipientRef = $result['data'][0]['Ref'] ?? null;
    $contactRef = $result['data'][0]['ContactPerson']['data'][0]['Ref'] ?? $recipientRef;
    if (!$recipientRef) {
        echo json_encode(['error' => 'Не вдалося створити отримувача']);
        exit;
    }
}

// 2. Create internet document (TTN)
$props = [
    'PayerType' => 'Recipient',
    'PaymentMethod' => 'Cash',
    'DateTime' => date('d.m.Y'),
    'CargoType' => 'Cargo',
    'Weight' => number_format($weight, 1),
    'ServiceType' => 'WarehouseWarehouse',
    'SeatsAmount' => (string)$seats,
    'Description' => $description,
    'Cost' => (string)$cost,
    'CitySender' => $senderCityRef,
    'Sender' => '',
    'SenderAddress' => $senderWarehouseRef,
    'ContactSender' => '',
    'SendersPhone' => $senderPhone,
    'CityRecipient' => $cityRef,
    'Recipient' => $recipientRef,
    'RecipientAddress' => $warehouseRef,
    'ContactRecipient' => $contactRef,
    'RecipientsPhone' => $phone,
];

// Get sender counterparty + contact ref dynamically
$result = npApiCall($apiUrl, $apiKey, 'Counterparty', 'getCounterparties', [
    'CounterpartyProperty' => 'Sender',
    'Page' => '1',
]);
if (isset($result['error'])) {
    echo json_encode(['error' => 'Помилка отримання відправника: ' . $result['error']]);
    exit;
}
if (empty($result['data'])) {
    echo json_encode(['error' => 'Не знайдено відправника в системі НП. Додайте в кабінеті НП.']);
    exit;
}
$props['Sender'] = $result['data'][0]['Ref'];
$props['ContactSender'] = $result['data'][0]['ContactPerson']['data'][0]['Ref'] ?? '';

if ($order['payment_method'] === 'nova_poshta') {
    $props['BackwardDeliveryData'] = [
        [
            'PayerType' => 'Recipient',
            'CargoType' => 'Money',
            'RedeliveryString' => (string)$cost,
        ],
    ];
}

$result = npApiCall($apiUrl, $apiKey, 'InternetDocument', 'save', $props);
if (isset($result['error'])) {
    echo json_encode(['error' => 'Помилка створення ТТН: ' . $result['error']]);
    exit;
}

$ttn = $result['data'][0]['IntDocNumber'] ?? '';
if (!$ttn) {
    echo json_encode(['error' => 'Не отримано номер ТТН від API НП']);
    exit;
}

$pdo->prepare("UPDATE erp_orders SET ttn_number = ?, delivery_status = 'sending' WHERE order_id = ?")
    ->execute([$ttn, $orderId]);

echo json_encode([
    'success' => true,
    'ttn' => $ttn,
    'message' => 'ТТН ' . $ttn . ' створено',
], JSON_UNESCAPED_UNICODE);
