<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../_helpers.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

logActivity($pdo, 'info', 'Пошук клієнтів: ' . ($_GET['q'] ?? ''), 'search-customers');

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 1) {
    echo json_encode([]);
    exit;
}

try {
    $s = '%' . $q . '%';
    $stmt = $pdo->prepare("
        SELECT customer_id, firstname, lastname, email, telephone,
               total_orders, total_spent
        FROM erp_customers
        WHERE CONCAT(COALESCE(firstname,''), ' ', COALESCE(lastname,'')) LIKE ?
           OR email LIKE ? OR telephone LIKE ?
           OR CAST(customer_id AS CHAR) LIKE ?
        ORDER BY total_orders DESC
        LIMIT 15
    ");
    $stmt->execute([$s, $s, $s, $s]);
    echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    logActivity($pdo, 'error', 'Помилка пошуку клієнтів: ' . $e->getMessage(), 'search-customers', null, null);
    echo json_encode(['error' => $e->getMessage()]);
}
