<?php
session_start();

// =====================================================
// ERP/CRM Configuration
// =====================================================

// ERP Database Configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'your_erp_db_user');
define('DB_PASS', 'your_erp_db_pass');
define('DB_NAME', 'your_erp_db_name');

// OpenCart API Configuration
define('OC_API_URL', 'https://your-opencart-store.com/index.php?route=api/');
define('OC_API_KEY', 'your-api-key-here');

// App Configuration
define('APP_NAME', 'ERP/CRM');
define('APP_VERSION', '1.0.0');
define('CURRENCY_SYMBOL', '&#8372;');
define('CURRENCY_CODE', 'UAH');
define('BASE_CURRENCY', 'UAH');

// Paths
define('BASE_PATH', dirname(__FILE__));
define('BASE_URL', '/ERP');

// =====================================================
// ERP Database Connection
// =====================================================
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// =====================================================
// Initialize ERP Tables
// =====================================================
function initErpTables($pdo) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `erp_users` (
            `user_id` INT AUTO_INCREMENT PRIMARY KEY,
            `username` VARCHAR(64) NOT NULL UNIQUE,
            `password` VARCHAR(255) NOT NULL,
            `email` VARCHAR(128),
            `role` ENUM('admin','manager','staff') DEFAULT 'staff',
            `status` TINYINT DEFAULT 1,
            `date_added` DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `erp_suppliers` (
            `supplier_id` INT AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(128) NOT NULL,
            `contact_person` VARCHAR(128),
            `phone` VARCHAR(32),
            `email` VARCHAR(128),
            `address` TEXT,
            `currency` VARCHAR(8) DEFAULT 'USD',
            `notes` TEXT,
            `status` TINYINT DEFAULT 1,
            `date_added` DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `erp_products` (
            `product_id` INT PRIMARY KEY,
            `model` VARCHAR(64),
            `sku` VARCHAR(64),
            `name` VARCHAR(255),
            `image` VARCHAR(255),
            `price_wholesale` DECIMAL(15,4) DEFAULT 0,
            `price_semi_wholesale` DECIMAL(15,4) DEFAULT 0,
            `price_retail` DECIMAL(15,4) DEFAULT 0,
            `quantity` INT DEFAULT 0,
            `status` TINYINT DEFAULT 1,
            `date_synced` DATETIME,
            `date_added` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `date_modified` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `erp_incoming_invoices` (
            `invoice_id` INT AUTO_INCREMENT PRIMARY KEY,
            `invoice_number` VARCHAR(64) NOT NULL,
            `supplier_id` INT NOT NULL,
            `date` DATE NOT NULL,
            `currency` VARCHAR(8) DEFAULT 'USD',
            `exchange_rate` DECIMAL(10,4) DEFAULT 1,
            `total_foreign` DECIMAL(15,4) DEFAULT 0,
            `total_local` DECIMAL(15,4) DEFAULT 0,
            `notes` TEXT,
            `user_id` INT,
            `status` ENUM('draft','confirmed','cancelled') DEFAULT 'draft',
            `date_added` DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `erp_invoice_items` (
            `item_id` INT AUTO_INCREMENT PRIMARY KEY,
            `invoice_id` INT NOT NULL,
            `product_id` INT NOT NULL,
            `quantity` DECIMAL(10,2) NOT NULL,
            `price_foreign` DECIMAL(15,4) NOT NULL,
            `price_local` DECIMAL(15,4) NOT NULL,
            `total_foreign` DECIMAL(15,4) NOT NULL,
            `total_local` DECIMAL(15,4) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `erp_stock_moves` (
            `move_id` INT AUTO_INCREMENT PRIMARY KEY,
            `product_id` INT NOT NULL,
            `type` ENUM('in','out','return_in','return_out','adjustment') NOT NULL,
            `quantity` DECIMAL(10,2) NOT NULL,
            `reference_type` VARCHAR(32),
            `reference_id` INT,
            `cost_price` DECIMAL(15,4),
            `notes` TEXT,
            `user_id` INT,
            `date_added` DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `erp_customers` (
            `customer_id` INT PRIMARY KEY,
            `firstname` VARCHAR(64),
            `lastname` VARCHAR(64),
            `email` VARCHAR(128),
            `telephone` VARCHAR(32),
            `group_name` VARCHAR(64),
            `total_orders` INT DEFAULT 0,
            `total_spent` DECIMAL(15,4) DEFAULT 0,
            `status` TINYINT DEFAULT 1,
            `date_synced` DATETIME,
            `date_added` DATETIME,
            `date_modified` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `erp_customer_notes` (
            `note_id` INT AUTO_INCREMENT PRIMARY KEY,
            `customer_id` INT NOT NULL,
            `user_id` INT NOT NULL,
            `note` TEXT NOT NULL,
            `date_added` DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `erp_orders` (
            `order_id` INT PRIMARY KEY,
            `customer_id` INT,
            `customer_name` VARCHAR(128),
            `email` VARCHAR(128),
            `telephone` VARCHAR(32),
            `status_id` INT,
            `status_name` VARCHAR(64),
            `total` DECIMAL(15,4),
            `currency_code` VARCHAR(8),
            `currency_value` DECIMAL(15,4),
            `date_added` DATETIME,
            `date_modified` DATETIME,
            `date_synced` DATETIME,
            `erp_notes` TEXT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `erp_order_products` (
            `order_product_id` INT PRIMARY KEY,
            `order_id` INT NOT NULL,
            `product_id` INT NOT NULL,
            `name` VARCHAR(255),
            `model` VARCHAR(64),
            `quantity` INT,
            `price` DECIMAL(15,4),
            `total` DECIMAL(15,4),
            `cost_price` DECIMAL(15,4) DEFAULT 0,
            `profit` DECIMAL(15,4) DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `erp_returns` (
            `return_id` INT AUTO_INCREMENT PRIMARY KEY,
            `order_id` INT NOT NULL,
            `product_id` INT NOT NULL,
            `product_name` VARCHAR(255),
            `quantity` INT NOT NULL,
            `reason` TEXT,
            `action` ENUM('refund','credit','replacement','none') DEFAULT 'refund',
            `restock` TINYINT DEFAULT 0,
            `status` ENUM('pending','approved','rejected','processed') DEFAULT 'pending',
            `user_id` INT,
            `date_added` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `date_processed` DATETIME
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `erp_pricing_rules` (
            `rule_id` INT AUTO_INCREMENT PRIMARY KEY,
            `product_id` INT,
            `markup_wholesale` DECIMAL(10,2) DEFAULT 0,
            `markup_semi_wholesale` DECIMAL(10,2) DEFAULT 0,
            `markup_retail` DECIMAL(10,2) DEFAULT 0,
            `custom_price_wholesale` DECIMAL(15,4),
            `custom_price_semi_wholesale` DECIMAL(15,4),
            `custom_price_retail` DECIMAL(15,4),
            `use_custom` TINYINT DEFAULT 0,
            `date_modified` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `erp_exchange_rates` (
            `rate_id` INT AUTO_INCREMENT PRIMARY KEY,
            `currency_from` VARCHAR(8) NOT NULL,
            `currency_to` VARCHAR(8) NOT NULL,
            `rate` DECIMAL(10,4) NOT NULL,
            `source` VARCHAR(32) DEFAULT 'manual',
            `date_added` DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `erp_settings` (
            `setting_id` INT AUTO_INCREMENT PRIMARY KEY,
            `key` VARCHAR(64) NOT NULL UNIQUE,
            `value` TEXT,
            `date_modified` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `erp_sync_log` (
            `log_id` INT AUTO_INCREMENT PRIMARY KEY,
            `type` VARCHAR(32),
            `status` VARCHAR(16),
            `records_synced` INT DEFAULT 0,
            `message` TEXT,
            `date_added` DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `erp_payments` (
            `payment_id` INT AUTO_INCREMENT PRIMARY KEY,
            `order_id` INT NOT NULL,
            `amount` DECIMAL(15,4) NOT NULL,
            `method` ENUM('cash','card','fop','invoice') NOT NULL DEFAULT 'cash',
            `date` DATE NOT NULL,
            `notes` TEXT,
            `user_id` INT,
            `date_added` DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $stmt = $pdo->query("SELECT COUNT(*) FROM erp_users");
    if ($stmt->fetchColumn() == 0) {
        $hash = password_hash('admin', PASSWORD_DEFAULT);
        $pdo->exec("INSERT INTO erp_users (username, password, role) VALUES ('admin', '$hash', 'admin')");
    }

    $stmt = $pdo->query("SELECT COUNT(*) FROM erp_exchange_rates WHERE currency_from = 'USD' AND currency_to = 'UAH'");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO erp_exchange_rates (currency_from, currency_to, rate, source) VALUES ('USD', 'UAH', 41.50, 'manual')");
    }

    $stmt = $pdo->query("SELECT COUNT(*) FROM erp_settings WHERE `key` = 'default_markup_wholesale'");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO erp_settings (`key`, `value`) VALUES
            ('default_markup_wholesale', '10'),
            ('default_markup_semi_wholesale', '25'),
            ('default_markup_retail', '50'),
            ('auto_sync_enabled', '0'),
            ('sync_interval_minutes', '60')
        ");
    }
}

initErpTables($pdo);

// =====================================================
// Helper Functions
// =====================================================
function redirect($url) {
    header("Location: " . $url);
    exit;
}

function isLoggedIn() {
    return isset($_SESSION['erp_user_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        redirect(BASE_URL . '/login.php');
    }
}

function getUserData() {
    return [
        'user_id' => $_SESSION['erp_user_id'] ?? null,
        'username' => $_SESSION['erp_username'] ?? '',
        'role' => $_SESSION['erp_role'] ?? 'staff',
    ];
}

function isAdmin() {
    return getUserData()['role'] === 'admin';
}

function isManager() {
    $role = getUserData()['role'];
    return $role === 'admin' || $role === 'manager';
}

function formatMoney($amount) {
    return CURRENCY_SYMBOL . number_format($amount, 2, '.', ' ');
}

function formatMoneyForeign($amount, $currency = 'USD') {
    return $currency . ' ' . number_format($amount, 2, '.', ' ');
}

function formatDate($date) {
    if (empty($date)) return '-';
    return date('d.m.Y H:i', strtotime($date));
}

function formatDateShort($date) {
    if (empty($date)) return '-';
    return date('d.m.Y', strtotime($date));
}

function escape($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

function flashMessage($type, $message) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlashMessage() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

function getCurrentRate($pdo, $from = 'USD', $to = 'UAH') {
    $stmt = $pdo->prepare("SELECT rate FROM erp_exchange_rates WHERE currency_from = ? AND currency_to = ? ORDER BY date_added DESC LIMIT 1");
    $stmt->execute([$from, $to]);
    $result = $stmt->fetch();
    return $result ? (float)$result['rate'] : 1;
}

function getDefaultMarkups($pdo) {
    $settings = ['default_markup_wholesale', 'default_markup_semi_wholesale', 'default_markup_retail'];
    $result = [];
    foreach ($settings as $key) {
        $stmt = $pdo->prepare("SELECT value FROM erp_settings WHERE `key` = ?");
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        $result[$key] = $row ? (float)$row['value'] : 0;
    }
    return $result;
}

function calculatePrice($costUah, $markupPercent) {
    return $costUah * (1 + $markupPercent / 100);
}

function getProductCostPrice($pdo, $productId) {
    $stmt = $pdo->prepare("
        SELECT COALESCE(AVG(sm.cost_price), 0) as avg_cost
        FROM erp_stock_moves sm
        WHERE sm.product_id = ? AND sm.type IN ('in', 'return_in') AND sm.cost_price > 0
    ");
    $stmt->execute([$productId]);
    $result = $stmt->fetch();
    return (float)$result['avg_cost'];
}

function getProductStock($pdo, $productId) {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(
            CASE WHEN type IN ('in', 'return_in') THEN quantity
                 WHEN type IN ('out', 'return_out') THEN -quantity
                 ELSE 0 END
        ), 0) as stock
        FROM erp_stock_moves WHERE product_id = ?
    ");
    $stmt->execute([$productId]);
    $result = $stmt->fetch();
    return (float)$result['stock'];
}

function getCurrentRates($pdo) {
    $result = ['USD' => 1, 'EUR' => 1];
    foreach (['USD', 'EUR'] as $cur) {
        $stmt = $pdo->prepare("SELECT rate FROM erp_exchange_rates WHERE currency_from = ? AND currency_to = 'UAH' ORDER BY date_added DESC LIMIT 1");
        $stmt->execute([$cur]);
        $row = $stmt->fetch();
        if ($row) $result[$cur] = (float)$row['rate'];
    }
    return $result;
}

function updateOutOfStockPrices($pdo) {
    $rates = getCurrentRates($pdo);
    $markups = getDefaultMarkups($pdo);
    $stmt = $pdo->query("
        SELECT p.product_id,
            COALESCE(AVG(CASE WHEN sm.type IN ('in','return_in') THEN sm.cost_price ELSE NULL END), 0) as avg_cost,
            COALESCE(SUM(CASE WHEN sm.type IN ('in','return_in') THEN sm.quantity ELSE 0 END) - SUM(CASE WHEN sm.type IN ('out','return_out') THEN sm.quantity ELSE 0 END), 0) as stock,
            COALESCE((SELECT currency FROM erp_incoming_invoices ii JOIN erp_invoice_items iit ON ii.invoice_id = iit.invoice_id WHERE iit.product_id = p.product_id ORDER BY ii.date_added DESC LIMIT 1), 'USD') as purchase_currency
        FROM erp_products p
        LEFT JOIN erp_stock_moves sm ON p.product_id = sm.product_id
        GROUP BY p.product_id
    ");
    $products = $stmt->fetchAll();
    $updated = 0;
    foreach ($products as $p) {
        if ((float)$p['stock'] > 0 && (float)$p['avg_cost'] > 0) continue;
        $rate = $rates[$p['purchase_currency']] ?? $rates['USD'];
        $costUsd = (float)$p['avg_cost'];
        $costUah = $costUsd * $rate;
        if ($costUah <= 0) continue;
        $pw = calculatePrice($costUah, $markups['default_markup_wholesale']);
        $ps = calculatePrice($costUah, $markups['default_markup_semi_wholesale']);
        $pr = calculatePrice($costUah, $markups['default_markup_retail']);
        $stmt2 = $pdo->prepare("UPDATE erp_products SET price_wholesale = ?, price_semi_wholesale = ?, price_retail = ? WHERE product_id = ?");
        $stmt2->execute([$pw, $ps, $pr, $p['product_id']]);
        $updated++;
    }
    return $updated;
}

function fetchPrivatBankRate($pdo) {
    $url = 'https://api.privatbank.ua/p24api/pubinfo?json&exchange&coursid=5';
    $context = stream_context_create(['http' => ['timeout' => 10, 'header' => 'User-Agent: ERP-CRM/1.0']]);
    $response = @file_get_contents($url, false, $context);
    if ($response === false) return false;

    $data = json_decode($response, true);
    if (!$data) return false;

    $found = false;
    foreach ($data as $row) {
        if ($row['base_ccy'] === 'UAH' && in_array($row['ccy'], ['USD', 'EUR'])) {
            $rate = (float)$row['buy'];
            $stmt = $pdo->prepare("INSERT INTO erp_exchange_rates (currency_from, currency_to, rate, source) VALUES (?, 'UAH', ?, 'privatbank')");
            $stmt->execute([$row['ccy'], $rate]);
            $found = true;
        }
    }
    return $found;
}

function getActiveNav($page) {
    return basename($_SERVER['PHP_SELF']) === $page ? 'active' : '';
}

function getStatusBadge($status) {
    $classes = [
        'pending' => 'bg-warning text-dark',
        'approved' => 'bg-info',
        'rejected' => 'bg-danger',
        'processed' => 'bg-success',
        'draft' => 'bg-secondary',
        'confirmed' => 'bg-success',
        'cancelled' => 'bg-danger',
    ];
    $class = $classes[$status] ?? 'bg-secondary';
    return '<span class="badge ' . $class . '">' . escape(ucfirst($status)) . '</span>';
}

function getStockTypeLabel($type) {
    $labels = [
        'in' => '<span class="badge bg-success">Прихід</span>',
        'out' => '<span class="badge bg-danger">Витрата</span>',
        'return_in' => '<span class="badge bg-info">Повернення на склад</span>',
        'return_out' => '<span class="badge bg-warning text-dark">Повернення постачальнику</span>',
        'adjustment' => '<span class="badge bg-secondary">Корекція</span>',
    ];
    return $labels[$type] ?? escape($type);
}

function paginate($total, $perPage, $currentPage) {
    $totalPages = ceil($total / $perPage);
    $offset = ($currentPage - 1) * $perPage;
    return [
        'total' => $total,
        'per_page' => $perPage,
        'current_page' => $currentPage,
        'total_pages' => max(1, $totalPages),
        'offset' => $offset,
    ];
}

function renderPagination($baseUrl, $pagination) {
    if ($pagination['total_pages'] <= 1) return '';
    $html = '<nav><ul class="pagination justify-content-center">';
    $html .= '<li class="page-item ' . ($pagination['current_page'] <= 1 ? 'disabled' : '') . '">';
    $html .= '<a class="page-link" href="' . $baseUrl . '&page=' . ($pagination['current_page'] - 1) . '">&laquo;</a></li>';
    for ($i = 1; $i <= $pagination['total_pages']; $i++) {
        $html .= '<li class="page-item ' . ($i === $pagination['current_page'] ? 'active' : '') . '">';
        $html .= '<a class="page-link" href="' . $baseUrl . '&page=' . $i . '">' . $i . '</a></li>';
    }
    $html .= '<li class="page-item ' . ($pagination['current_page'] >= $pagination['total_pages'] ? 'disabled' : '') . '">';
    $html .= '<a class="page-link" href="' . $baseUrl . '&page=' . ($pagination['current_page'] + 1) . '">&raquo;</a></li>';
    $html .= '</ul></nav>';
    return $html;
}
