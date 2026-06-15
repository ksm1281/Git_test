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

$npTtnRef = $order['np_ttn_ref'] ?? '';
$ttnNumber = $order['ttn_number'] ?? '';

if (!$npTtnRef && !$ttnNumber) {
    echo json_encode(['error' => 'Немає ТТН для видалення']);
    exit;
}

$stmt = $pdo->prepare("SELECT `value` FROM erp_settings WHERE `key`='np_api_key'");
$stmt->execute();
$apiKey = $stmt->fetchColumn();

if (!$apiKey) {
    echo json_encode(['error' => 'Не налаштовано API ключ Нової Пошти']);
    exit;
}

$apiUrl = 'https://api.novaposhta.ua/v2.0/json/';

function npDeleteApiCall($apiUrl, $apiKey, $model, $method, $props) {
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
        return ['error' => 'Некоректна відповідь JSON від API НП'];
    }
    if (!isset($data['success']) || !$data['success']) {
        return ['error' => $data['errors'][0] ?? 'Невідома помилка API НП'];
    }
    return ['data' => $data['data']];
}

// Try to get document Ref from TTN number if we don't have it stored
$docRef = $npTtnRef;
if (!$docRef && $ttnNumber) {
    $result = npDeleteApiCall($apiUrl, $apiKey, 'InternetDocument', 'getDocumentList', [
        'Page' => '1',
        'Limit' => '50',
    ]);
    if (!isset($result['error'])) {
        foreach ($result['data'] as $doc) {
            if (($doc['IntDocNumber'] ?? '') === $ttnNumber) {
                $docRef = $doc['Ref'] ?? '';
                break;
            }
        }
    }
    if (!$docRef) {
        echo json_encode(['error' => 'Не знайдено документ з номером ' . $ttnNumber . ' в системі НП. Можливо, він вже видалений.']);
        exit;
    }
}

$result = npDeleteApiCall($apiUrl, $apiKey, 'InternetDocument', 'delete', [
    'DocumentRefs' => $docRef,
]);

if (isset($result['error'])) {
    echo json_encode(['error' => 'Помилка видалення ТТН: ' . $result['error']]);
    exit;
}

$pdo->prepare("UPDATE erp_orders SET ttn_number = NULL, np_ttn_ref = NULL, delivery_status = 'new' WHERE order_id = ?")
    ->execute([$orderId]);

echo json_encode([
    'success' => true,
    'message' => 'ТТН видалено',
], JSON_UNESCAPED_UNICODE);
