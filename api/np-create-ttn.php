<?php
while (ob_get_level()) ob_end_clean();
error_reporting(E_ALL);
ini_set('display_errors', 0);

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
$length = (float)($_POST['length'] ?? 0);
$width = (float)($_POST['width'] ?? 0);
$height = (float)($_POST['height'] ?? 0);
$payerType = $_POST['payer_type'] ?? 'Recipient';
$paymentMethod = $_POST['payment_method'] ?? 'Cash';
$customDescription = trim($_POST['description'] ?? '');
$declaredCost = (float)($_POST['declared_cost'] ?? 0);
$codAmount = (float)($_POST['cod_amount'] ?? 0);
$updateRef = trim($_POST['ref'] ?? '');

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
$stmt->execute();
$set = [];
foreach ($stmt->fetchAll() as $row) {
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
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $res = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($res === false) return ['error' => "Помилка з'єднання до API НП: [$errno] $error"];
    if ($http !== 200) return ['error' => "HTTP $http від API НП"];
    $data = json_decode($res, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['error' => 'Некоректна відповідь JSON від API НП (' . json_last_error_msg() . ')', 'raw' => mb_substr($res, 0, 200)];
    }
    if (!isset($data['success']) || !$data['success']) {
        return ['error' => $data['errors'][0] ?? 'Невідома помилка API НП'];
    }
    return ['data' => $data['data']];
}

$itemsList = [];
$stmt = $pdo->prepare("SELECT p.name, op.quantity FROM erp_order_products op LEFT JOIN erp_products p ON op.product_id = p.product_id WHERE op.order_id = ?");
$stmt->execute([$orderId]);
$items = $stmt->fetchAll();
foreach ($items as $i) {
    $name = $i['name'] ?: 'Товар';
    $qty = number_format((float)$i['quantity'], 0);
    $itemsList[] = "$name ($qty шт.)";
}
$description = mb_substr($customDescription ?: implode(', ', $itemsList) ?: 'Замовлення #' . $orderId, 0, 100);

$cost = $declaredCost > 0 ? max(1, (int)round($declaredCost)) : max(1, (int)round((float)$order['total']));

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
    $nameParts = explode(' ', trim($recipientName), 2);
    $result = npApiCall($apiUrl, $apiKey, 'Counterparty', 'save', [
        'CounterpartyProperty' => 'Recipient',
        'CounterpartyType' => 'PrivatePerson',
        'FirstName' => $nameParts[0] ?: $recipientName,
        'LastName' => $nameParts[1] ?? '',
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

// 2. Create/update internet document (TTN)
$props = [
    'PayerType' => $payerType,
    'PaymentMethod' => $paymentMethod,
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

if ($length > 0 && $width > 0 && $height > 0) {
    $props['VolumetricLengthMeter'] = number_format($length / 100, 3, '.', '');
    $props['VolumetricWidthMeter'] = number_format($width / 100, 3, '.', '');
    $props['VolumetricHeightMeter'] = number_format($height / 100, 3, '.', '');
}

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
$senderRef = $props['Sender'];
$contactResult = npApiCall($apiUrl, $apiKey, 'Counterparty', 'getCounterpartyContactPersons', [
    'Ref' => $senderRef,
]);
if (!isset($contactResult['error']) && !empty($contactResult['data'])) {
    $props['ContactSender'] = $contactResult['data'][0]['Ref'];
} else {
    $contactResult = npApiCall($apiUrl, $apiKey, 'Counterparty', 'save', [
        'CounterpartyProperty' => 'Sender',
        'CounterpartyType' => 'PrivatePerson',
        'FirstName' => $result['data'][0]['Description'] ?: 'Відправник',
        'Phone' => $senderPhone,
    ]);
    if (!isset($contactResult['error']) && !empty($contactResult['data'])) {
        $props['ContactSender'] = $contactResult['data'][0]['Ref'];
    } else {
        echo json_encode(['error' => 'Не знайдено контактну особу відправника. Додайте в кабінеті НП або налаштуйте відправника як PrivatePerson.']);
        exit;
    }
}

if ($codAmount > 0) {
    $props['BackwardDeliveryData'] = [
        [
            'PayerType' => 'Recipient',
            'CargoType' => 'Money',
            'RedeliveryString' => (string)max(1, (int)round($codAmount)),
        ],
    ];
}

if ($updateRef) {
    $props['Ref'] = $updateRef;
    $result = npApiCall($apiUrl, $apiKey, 'InternetDocument', 'update', $props);
} else {
    $result = npApiCall($apiUrl, $apiKey, 'InternetDocument', 'save', $props);
}
if (isset($result['error'])) {
    echo json_encode(['error' => 'Помилка створення ТТН: ' . $result['error']]);
    exit;
}

$ttn = $result['data'][0]['IntDocNumber'] ?? '';
$docRef = $result['data'][0]['Ref'] ?? '';
if (!$ttn) {
    echo json_encode(['error' => 'Не отримано номер ТТН від API НП']);
    exit;
}

if ($updateRef) {
    $pdo->prepare("UPDATE erp_orders SET ttn_number = ?, np_ttn_ref = ? WHERE order_id = ?")
        ->execute([$ttn, $docRef, $orderId]);
    echo json_encode([
        'success' => true,
        'ttn' => $ttn,
        'message' => 'ТТН ' . $ttn . ' оновлено',
    ], JSON_UNESCAPED_UNICODE);
} else {
    $pdo->prepare("UPDATE erp_orders SET ttn_number = ?, np_ttn_ref = ?, delivery_status = 'awaiting' WHERE order_id = ?")
        ->execute([$ttn, $docRef, $orderId]);
    echo json_encode([
        'success' => true,
        'ttn' => $ttn,
        'message' => 'ТТН ' . $ttn . ' створено',
    ], JSON_UNESCAPED_UNICODE);
}
