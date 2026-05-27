<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../_helpers.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? '';
$q = trim($_GET['q'] ?? '');
$cityRef = trim($_GET['city_ref'] ?? '');

$stmt = $pdo->prepare("SELECT `value` FROM erp_settings WHERE `key`='np_api_key'");
$stmt->execute();
$apiKey = $stmt->fetchColumn();

if (!$apiKey) {
    echo json_encode(['error' => 'API ключ Нової Пошти не налаштовано. Додайте у Налаштуваннях.']);
    exit;
}

$apiUrl = 'https://api.novaposhta.ua/v2.0/json/';

try {
    if ($action === 'cities' && strlen($q) >= 2) {
        $body = json_encode([
            'apiKey' => $apiKey,
            'modelName' => 'Address',
            'calledMethod' => 'searchSettlements',
            'methodProperties' => [
                'CityName' => $q,
                'Limit' => 15,
            ],
        ]);
    } elseif ($action === 'warehouses' && $cityRef) {
        $props = ['CityRef' => $cityRef, 'Limit' => 50];
        if (strlen($q) >= 1) {
            $props['FindByString'] = $q;
        }
        $body = json_encode([
            'apiKey' => $apiKey,
            'modelName' => 'AddressGeneral',
            'calledMethod' => 'getWarehouses',
            'methodProperties' => $props,
        ]);
    } else {
        echo json_encode(['error' => 'Invalid parameters']);
        exit;
    }

    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        echo json_encode(['error' => 'HTTP ' . $httpCode . ' від API НП']);
        exit;
    }

    $data = json_decode($response, true);

    if (!isset($data['success']) || !$data['success']) {
        echo json_encode(['error' => $data['errors'][0] ?? 'Помилка API НП', 'data' => $data]);
        exit;
    }

    if ($action === 'cities') {
        $cities = [];
        foreach ($data['data'][0]['Addresses'] ?? [] as $item) {
            $cities[] = [
                'ref' => $item['DeliveryCity'],
                'name' => $item['Present'],
            ];
        }
        echo json_encode($cities, JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'warehouses') {
        $warehouses = [];
        foreach ($data['data'] ?? [] as $item) {
            $warehouses[] = [
                'ref' => $item['Ref'],
                'name' => $item['Description'],
            ];
        }
        echo json_encode($warehouses, JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
