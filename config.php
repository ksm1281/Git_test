<?php
session_start();

// =====================================================
// ERP/CRM Configuration
// =====================================================

// Load local config first (overrides defaults below)
if (file_exists(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}

// Database defaults
if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
if (!defined('DB_USER')) define('DB_USER', 'your_erp_db_user');
if (!defined('DB_PASS')) define('DB_PASS', 'your_erp_db_pass');
if (!defined('DB_NAME')) define('DB_NAME', 'your_erp_db_name');

// OpenCart API defaults
if (!defined('OC_API_URL')) define('OC_API_URL', 'https://your-opencart-store.com/index.php?route=api/');
if (!defined('OC_API_KEY')) define('OC_API_KEY', 'your-api-key-here');

// OpenCart Database defaults (альтернатива HTTP API)
if (!defined('OC_DB_HOST')) define('OC_DB_HOST', 'localhost');
if (!defined('OC_DB_USER')) define('OC_DB_USER', 'your_oc_db_user');
if (!defined('OC_DB_PASS')) define('OC_DB_PASS', 'your_oc_db_pass');
if (!defined('OC_DB_NAME')) define('OC_DB_NAME', 'your_oc_db_name');
if (!defined('OC_DB_PREFIX')) define('OC_DB_PREFIX', 'oc_');

// App Configuration
define('APP_NAME', 'ERP/CRM');
define('APP_VERSION', '1.8.0');
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
            `price_purchase` DECIMAL(15,4) DEFAULT 0,
            `quantity` INT DEFAULT 0,
            `status` TINYINT DEFAULT 1,
            `date_synced` DATETIME,
            `date_added` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `date_modified` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `erp_categories` (
            `category_id` INT AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(128) NOT NULL,
            `parent_id` INT DEFAULT 0,
            `sort_order` INT DEFAULT 0,
            `date_added` DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `erp_product_categories` (
            `product_id` INT NOT NULL,
            `category_id` INT NOT NULL,
            PRIMARY KEY (`product_id`, `category_id`)
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
            `patronymic` VARCHAR(64),
            `email` VARCHAR(128),
            `telephone` VARCHAR(32),
            `group_name` VARCHAR(64),
            `total_orders` INT DEFAULT 0,
            `total_spent` DECIMAL(15,4) DEFAULT 0,
            `status` TINYINT DEFAULT 1,
            `date_synced` DATETIME,
            `date_start` DATE,
            `date_end` DATE,
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
        CREATE TABLE IF NOT EXISTS `erp_activity_log` (
            `log_id` INT AUTO_INCREMENT PRIMARY KEY,
            `type` VARCHAR(32) NOT NULL DEFAULT 'info',
            `source` VARCHAR(64),
            `message` TEXT NOT NULL,
            `data` TEXT,
            `user_id` INT,
            `date_added` DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX `type` (`type`),
            INDEX `date_added` (`date_added`)
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
            `invoice_id` INT NOT NULL,
            `amount` DECIMAL(15,4) NOT NULL,
            `method` ENUM('cash','card','fop','invoice') NOT NULL DEFAULT 'cash',
            `date` DATE NOT NULL,
            `notes` TEXT,
            `user_id` INT,
            `date_added` DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `erp_cash_accounts` (
            `account_id` INT AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(128) NOT NULL,
            `type` ENUM('cash','bank','fop') NOT NULL DEFAULT 'cash',
            `currency` VARCHAR(8) DEFAULT 'UAH',
            `initial_balance` DECIMAL(15,4) DEFAULT 0,
            `status` TINYINT DEFAULT 1,
            `date_added` DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `erp_transactions` (
            `transaction_id` INT AUTO_INCREMENT PRIMARY KEY,
            `account_id` INT NOT NULL,
            `type` ENUM('in','out','transfer') NOT NULL,
            `amount` DECIMAL(15,4) NOT NULL,
            `category` VARCHAR(64),
            `method` ENUM('cash','card','fop','invoice','transfer') DEFAULT 'cash',
            `date` DATE NOT NULL,
            `description` TEXT,
            `reference_type` VARCHAR(32),
            `reference_id` INT,
            `user_id` INT,
            `date_added` DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    try { $pdo->exec("ALTER TABLE erp_payments CHANGE COLUMN order_id invoice_id INT NOT NULL"); } catch (PDOException $e) { /* ignore */ }

    try { $pdo->exec("ALTER TABLE erp_orders MODIFY order_id INT AUTO_INCREMENT"); } catch (PDOException $e) { /* ignore */ }
    try { $pdo->exec("ALTER TABLE erp_order_products MODIFY order_product_id INT AUTO_INCREMENT"); } catch (PDOException $e) { /* ignore */ }
    try { $pdo->exec("ALTER TABLE erp_payments ADD COLUMN order_id INT DEFAULT NULL AFTER invoice_id"); } catch (PDOException $e) { /* ignore */ }
    try { $pdo->exec("ALTER TABLE erp_payments MODIFY COLUMN method ENUM('cash','card','fop','invoice','transfer','nova_poshta') NOT NULL DEFAULT 'cash'"); } catch (PDOException $e) { /* ignore */ }
    try { $pdo->exec("ALTER TABLE erp_transactions MODIFY COLUMN method ENUM('cash','card','fop','invoice','transfer','nova_poshta') DEFAULT 'cash'"); } catch (PDOException $e) { /* ignore */ }
    try { $pdo->exec("ALTER TABLE erp_orders ADD COLUMN IF NOT EXISTS payment_method VARCHAR(32) DEFAULT NULL AFTER total"); } catch (PDOException $e) {
        try { $pdo->exec("ALTER TABLE erp_orders ADD COLUMN payment_method VARCHAR(32) DEFAULT NULL AFTER erp_notes"); } catch (PDOException $e2) { /* ignore */ }
    }
    try { $pdo->exec("ALTER TABLE erp_orders ADD COLUMN IF NOT EXISTS delivery_method VARCHAR(32) DEFAULT NULL AFTER payment_method"); } catch (PDOException $e) {
        try { $pdo->exec("ALTER TABLE erp_orders ADD COLUMN delivery_method VARCHAR(32) DEFAULT NULL AFTER payment_method"); } catch (PDOException $e2) { /* ignore */ }
    }
    try { $pdo->exec("ALTER TABLE erp_orders ADD COLUMN IF NOT EXISTS delivery_address TEXT DEFAULT NULL AFTER delivery_method"); } catch (PDOException $e) {
        try { $pdo->exec("ALTER TABLE erp_orders ADD COLUMN delivery_address TEXT DEFAULT NULL AFTER delivery_method"); } catch (PDOException $e2) { /* ignore */ }
    }
    try { $pdo->exec("ALTER TABLE erp_customers ADD COLUMN IF NOT EXISTS company_name VARCHAR(255) DEFAULT NULL AFTER group_name"); } catch (PDOException $e) {
        try { $pdo->exec("ALTER TABLE erp_customers ADD COLUMN company_name VARCHAR(255) DEFAULT NULL AFTER group_name"); } catch (PDOException $e2) { /* ignore */ }
    }
    try { $pdo->exec("ALTER TABLE erp_customers ADD COLUMN IF NOT EXISTS edrpou VARCHAR(32) DEFAULT NULL AFTER company_name"); } catch (PDOException $e) {
        try { $pdo->exec("ALTER TABLE erp_customers ADD COLUMN edrpou VARCHAR(32) DEFAULT NULL AFTER company_name"); } catch (PDOException $e2) { /* ignore */ }
    }
    try { $pdo->exec("ALTER TABLE erp_customers ADD COLUMN IF NOT EXISTS legal_address TEXT DEFAULT NULL AFTER edrpou"); } catch (PDOException $e) {
        try { $pdo->exec("ALTER TABLE erp_customers ADD COLUMN legal_address TEXT DEFAULT NULL AFTER edrpou"); } catch (PDOException $e2) { /* ignore */ }
    }
    try { $pdo->exec("ALTER TABLE erp_customers ADD COLUMN IF NOT EXISTS iban VARCHAR(64) DEFAULT NULL AFTER legal_address"); } catch (PDOException $e) {
        try { $pdo->exec("ALTER TABLE erp_customers ADD COLUMN iban VARCHAR(64) DEFAULT NULL AFTER legal_address"); } catch (PDOException $e2) { /* ignore */ }
    }
    try { $pdo->exec("ALTER TABLE erp_customers ADD COLUMN IF NOT EXISTS mfo VARCHAR(16) DEFAULT NULL AFTER iban"); } catch (PDOException $e) {
        try { $pdo->exec("ALTER TABLE erp_customers ADD COLUMN mfo VARCHAR(16) DEFAULT NULL AFTER iban"); } catch (PDOException $e2) { /* ignore */ }
    }
    try { $pdo->exec("ALTER TABLE erp_customers ADD COLUMN IF NOT EXISTS patronymic VARCHAR(64) DEFAULT NULL AFTER lastname"); } catch (PDOException $e) {
        try { $pdo->exec("ALTER TABLE erp_customers ADD COLUMN patronymic VARCHAR(64) DEFAULT NULL AFTER lastname"); } catch (PDOException $e2) { /* ignore */ }
    }
    try { $pdo->exec("ALTER TABLE erp_customers ADD COLUMN IF NOT EXISTS date_start DATE DEFAULT NULL AFTER date_synced"); } catch (PDOException $e) {
        try { $pdo->exec("ALTER TABLE erp_customers ADD COLUMN date_start DATE DEFAULT NULL AFTER date_synced"); } catch (PDOException $e2) { /* ignore */ }
    }
    try { $pdo->exec("ALTER TABLE erp_customers ADD COLUMN IF NOT EXISTS date_end DATE DEFAULT NULL AFTER date_start"); } catch (PDOException $e) {
        try { $pdo->exec("ALTER TABLE erp_customers ADD COLUMN date_end DATE DEFAULT NULL AFTER date_start"); } catch (PDOException $e2) { /* ignore */ }
    }

    try { $pdo->exec("ALTER TABLE erp_orders ADD COLUMN IF NOT EXISTS order_date DATE DEFAULT NULL AFTER delivery_address"); } catch (PDOException $e) {
        try { $pdo->exec("ALTER TABLE erp_orders ADD COLUMN order_date DATE DEFAULT NULL AFTER delivery_address"); } catch (PDOException $e2) { /* ignore */ }
    }
    try { $pdo->exec("ALTER TABLE erp_orders ADD COLUMN IF NOT EXISTS delivery_city VARCHAR(128) DEFAULT NULL AFTER delivery_address"); } catch (PDOException $e) {
        try { $pdo->exec("ALTER TABLE erp_orders ADD COLUMN delivery_city VARCHAR(128) DEFAULT NULL AFTER delivery_address"); } catch (PDOException $e2) { /* ignore */ }
    }
    try { $pdo->exec("ALTER TABLE erp_orders ADD COLUMN IF NOT EXISTS delivery_street VARCHAR(255) DEFAULT NULL AFTER delivery_city"); } catch (PDOException $e) {
        try { $pdo->exec("ALTER TABLE erp_orders ADD COLUMN delivery_street VARCHAR(255) DEFAULT NULL AFTER delivery_city"); } catch (PDOException $e2) { /* ignore */ }
    }
    try { $pdo->exec("ALTER TABLE erp_orders ADD COLUMN IF NOT EXISTS delivery_building VARCHAR(32) DEFAULT NULL AFTER delivery_street"); } catch (PDOException $e) {
        try { $pdo->exec("ALTER TABLE erp_orders ADD COLUMN delivery_building VARCHAR(32) DEFAULT NULL AFTER delivery_street"); } catch (PDOException $e2) { /* ignore */ }
    }
    try { $pdo->exec("ALTER TABLE erp_orders ADD COLUMN IF NOT EXISTS delivery_apartment VARCHAR(64) DEFAULT NULL AFTER delivery_building"); } catch (PDOException $e) {
        try { $pdo->exec("ALTER TABLE erp_orders ADD COLUMN delivery_apartment VARCHAR(64) DEFAULT NULL AFTER delivery_building"); } catch (PDOException $e2) { /* ignore */ }
    }
    try { $pdo->exec("ALTER TABLE erp_orders ADD COLUMN IF NOT EXISTS delivery_office VARCHAR(255) DEFAULT NULL AFTER delivery_apartment"); } catch (PDOException $e) {
        try { $pdo->exec("ALTER TABLE erp_orders ADD COLUMN delivery_office VARCHAR(255) DEFAULT NULL AFTER delivery_apartment"); } catch (PDOException $e2) { /* ignore */ }
    }
    try { $pdo->exec("ALTER TABLE erp_orders ADD COLUMN IF NOT EXISTS ttn_number VARCHAR(64) DEFAULT NULL AFTER delivery_office"); } catch (PDOException $e) {
        try { $pdo->exec("ALTER TABLE erp_orders ADD COLUMN ttn_number VARCHAR(64) DEFAULT NULL AFTER delivery_office"); } catch (PDOException $e2) { /* ignore */ }
    }
    try { $pdo->exec("ALTER TABLE erp_orders ADD COLUMN IF NOT EXISTS delivery_status VARCHAR(32) DEFAULT 'new' AFTER ttn_number"); } catch (PDOException $e) {
        try { $pdo->exec("ALTER TABLE erp_orders ADD COLUMN delivery_status VARCHAR(32) DEFAULT 'new' AFTER ttn_number"); } catch (PDOException $e2) { /* ignore */ }
    }
    try { $pdo->exec("ALTER TABLE erp_products ADD COLUMN IF NOT EXISTS price_purchase DECIMAL(15,4) DEFAULT 0 AFTER price_retail"); } catch (PDOException $e) {
        try { $pdo->exec("ALTER TABLE erp_products ADD COLUMN price_purchase DECIMAL(15,4) DEFAULT 0 AFTER price_retail"); } catch (PDOException $e2) { /* ignore */ }
    }

    $stmt = $pdo->query("SELECT COUNT(*) FROM erp_cash_accounts");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO erp_cash_accounts (name, type, currency) VALUES
            ('Основна каса', 'cash', 'UAH'),
            ('Розрахунковий рахунок', 'bank', 'UAH'),
            ('ФОП', 'fop', 'UAH')
        ");
    }

    $stmt = $pdo->query("SELECT COUNT(*) FROM erp_users");
    if ($stmt->fetchColumn() == 0) {
        $hash = password_hash('admin', PASSWORD_DEFAULT);
        $pdo->exec("INSERT INTO erp_users (username, password, role) VALUES ('admin', '$hash', 'admin')");
    }

    $stmt = $pdo->query("SELECT COUNT(*) FROM erp_exchange_rates WHERE currency_from = 'USD' AND currency_to = 'UAH'");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO erp_exchange_rates (currency_from, currency_to, rate, source) VALUES ('USD', 'UAH', 41.50, 'manual')");
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `erp_delivery_methods` (
            `method_id` INT AUTO_INCREMENT PRIMARY KEY,
            `code` VARCHAR(32) NOT NULL UNIQUE,
            `name` VARCHAR(128) NOT NULL,
            `sort_order` INT DEFAULT 0,
            `status` TINYINT DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `erp_payment_methods` (
            `method_id` INT AUTO_INCREMENT PRIMARY KEY,
            `code` VARCHAR(32) NOT NULL UNIQUE,
            `name` VARCHAR(128) NOT NULL,
            `sort_order` INT DEFAULT 0,
            `status` TINYINT DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $stmt = $pdo->query("SELECT COUNT(*) FROM erp_delivery_methods");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO erp_delivery_methods (code, name, sort_order) VALUES
            ('pickup', 'Самовивіз', 1),
            ('courier', 'Кур\'єр', 2),
            ('nova_poshta', 'Нова Пошта', 3),
            ('delivery', 'Делівері', 4),
            ('ukrposhta', 'Укрпошта', 5)
        ");
    }

    $stmt = $pdo->query("SELECT COUNT(*) FROM erp_payment_methods");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO erp_payment_methods (code, name, sort_order) VALUES
            ('cash', 'Готівка', 1),
            ('card', 'Картка', 2),
            ('fop', 'ФОП', 3),
            ('invoice', 'Рахунок', 4),
            ('transfer', 'Переказ', 5),
            ('nova_poshta', 'Нова Пошта (зворотня доставка)', 6)
        ");
    }

    $stmt = $pdo->query("SELECT COUNT(*) FROM erp_settings WHERE `key` = 'default_markup_wholesale'");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO erp_settings (`key`, `value`) VALUES
            ('default_markup_wholesale', '10'),
            ('default_markup_semi_wholesale', '25'),
            ('default_markup_retail', '50'),
            ('auto_sync_enabled', '0'),
            ('sync_interval_minutes', '60'),
            ('app_name', 'ERP/CRM'),
            ('currency_symbol', '&#8372;'),
            ('currency_code', 'UAH'),
            ('supplier_name', 'ФОП Прізвище Ім\'я П.'),
            ('supplier_edrpou', ''),
            ('supplier_phone', ''),
            ('supplier_iban', ''),
            ('supplier_bank', 'АТ «ПУМБ»'),
            ('supplier_mfo', ''),
            ('supplier_certificate', ''),
            ('supplier_cert_date', ''),
            ('supplier_address', '')
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
    return CURRENCY_SYMBOL . number_format((float)$amount, 2, '.', ' ');
}

function formatMoneyForeign($amount, $currency = 'USD') {
    return $currency . ' ' . number_format($amount, 2, '.', ' ');
}

function formatDate($date) {
    if (empty($date)) return '-';
    if ($date === '0000-00-00' || $date === '0000-00-00 00:00:00') return '-';
    $ts = strtotime($date);
    if ($ts === false || (int)date('Y', $ts) < 1000) return '-';
    return date('d.m.Y H:i', $ts);
}

function formatDateShort($date) {
    if (empty($date)) return '-';
    if ($date === '0000-00-00' || $date === '0000-00-00 00:00:00') return '-';
    $ts = strtotime($date);
    if ($ts === false || (int)date('Y', $ts) < 1000) return '-';
    return date('d.m.Y', $ts);
}

function convertUaDate($date) {
    if (empty($date)) return null;
    $months = [
        'січ' => '01', 'лют' => '02', 'бер' => '03', 'кві' => '04',
        'тра' => '05', 'чер' => '06', 'лип' => '07', 'серп' => '08',
        'вер' => '09', 'жов' => '10', 'лис' => '11', 'груд' => '12',
    ];
    $d = trim($date);
    $d = str_replace('.', '', $d);
    if (!preg_match('/^(\d{1,2})-([а-яіїєґ]{3,5})-(\d{2,4})$/iu', $d, $m)) return $date;
    $day = str_pad($m[1], 2, '0', STR_PAD_LEFT);
    $month = $months[mb_strtolower($m[2])] ?? null;
    if (!$month) return $date;
    $year = (int)$m[3];
    if ($year < 100) $year += $year < 30 ? 2000 : 1900;
    return "$year-$month-$day";
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

function logActivity($pdo, $type, $message, $source = null, $data = null, $userId = null) {
    if ($userId === null && isset($_SESSION['erp_user_id'])) {
        $userId = (int)$_SESSION['erp_user_id'];
    }
    $dataJson = is_string($data) ? $data : ($data ? json_encode($data, JSON_UNESCAPED_UNICODE) : null);
    $stmt = $pdo->prepare("INSERT INTO erp_activity_log (`type`, `source`, `message`, `data`, `user_id`) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$type, $source, $message, $dataJson, $userId]);
}

function getProductCostPrice($pdo, $productId) {
    $stmt = $pdo->prepare("
        SELECT CASE WHEN SUM(sm.quantity) > 0
            THEN SUM(sm.cost_price * sm.quantity) / SUM(sm.quantity)
            ELSE 0 END as avg_cost
        FROM erp_stock_moves sm
        WHERE sm.product_id = ? AND sm.type IN ('in', 'return_in') AND sm.cost_price > 0
    ");
    $stmt->execute([$productId]);
    $result = $stmt->fetch();
    $cost = (float)$result['avg_cost'];
    if ($cost > 0) return $cost;
    $stmt = $pdo->prepare("SELECT price_purchase FROM erp_products WHERE product_id = ?");
    $stmt->execute([$productId]);
    $row = $stmt->fetch();
    return $row ? (float)$row['price_purchase'] : 0;
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
    $result = ['USD' => 1, 'EUR' => 1, 'UAH' => 1];
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
            COALESCE(AVG(CASE WHEN sm.type IN ('in','return_in','adjustment') THEN sm.cost_price ELSE NULL END), 0) as avg_cost,
            COALESCE(SUM(CASE WHEN sm.type IN ('in','return_in','adjustment') THEN sm.quantity ELSE 0 END) - SUM(CASE WHEN sm.type IN ('out','return_out') THEN sm.quantity ELSE 0 END), 0) as stock,
            COALESCE((SELECT currency FROM erp_incoming_invoices ii JOIN erp_invoice_items iit ON ii.invoice_id = iit.invoice_id WHERE iit.product_id = p.product_id ORDER BY ii.date_added DESC LIMIT 1), 'EUR') as purchase_currency
        FROM erp_products p
        LEFT JOIN erp_stock_moves sm ON p.product_id = sm.product_id
        GROUP BY p.product_id
    ");
    $products = $stmt->fetchAll();
    $updated = 0;
    foreach ($products as $p) {
        if ((float)$p['stock'] > 0 && (float)$p['avg_cost'] > 0) continue;
        $purchaseCur = $p['purchase_currency'] ?: 'EUR';
        $rate = $rates[$purchaseCur] ?? $rates['EUR'];
        $costForeign = (float)$p['avg_cost'];
        $costUah = $costForeign * $rate;
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

if (!function_exists('getActiveNav')) {
function getActiveNav($page) {
    return basename($_SERVER['PHP_SELF']) === $page ? 'active' : '';
}
}

if (!function_exists('getStatusBadge')) {
function getStatusBadge($status) {
    $status = strtolower($status);
    $classes = [
        'pending' => 'bg-warning text-dark',
        'approved' => 'bg-info',
        'processed' => 'bg-primary',
        'shipped' => 'bg-secondary',
        'delivered' => 'bg-success',
        'draft' => 'bg-secondary',
        'confirmed' => 'bg-success',
        'cancelled' => 'bg-danger',
    ];
    $labels = [
        'pending' => 'Очікує',
        'approved' => 'Підтверджено',
        'processed' => 'В обробці',
        'shipped' => 'Відправлено',
        'delivered' => 'Доставлено',
        'draft' => 'Чернетка',
        'confirmed' => 'Підтверджено',
        'cancelled' => 'Скасовано',
    ];
    $class = $classes[$status] ?? 'bg-secondary';
    $label = $labels[$status] ?? $status;
    return '<span class="badge ' . $class . '">' . escape($label) . '</span>';
}
}

if (!function_exists('getStockTypeLabel')) {
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
}

if (!function_exists('paginate')) {
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
}

if (!function_exists('renderPagination')) {
function renderPagination($baseUrl, $pagination, $pageParam = 'page') {
    if ($pagination['total_pages'] <= 1) return '';
    $current = $pagination['current_page'];
    $total = $pagination['total_pages'];
    $range = 3;
    $html = '<nav><ul class="pagination justify-content-center flex-wrap">';
    $html .= '<li class="page-item ' . ($current <= 1 ? 'disabled' : '') . '">';
    $html .= '<a class="page-link" href="' . $baseUrl . '&' . $pageParam . '=' . ($current - 1) . '">&laquo;</a></li>';
    $pages = [];
    $pages[] = 1;
    for ($i = max(2, $current - $range); $i <= min($total - 1, $current + $range); $i++) {
        $pages[] = $i;
    }
    if ($total > 1) $pages[] = $total;
    $pages = array_unique($pages);
    sort($pages);
    $last = 0;
    foreach ($pages as $p) {
        if ($p - $last > 1) {
            $html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
        $html .= '<li class="page-item ' . ($p === $current ? 'active' : '') . '">';
        $html .= '<a class="page-link" href="' . $baseUrl . '&' . $pageParam . '=' . $p . '">' . $p . '</a></li>';
        $last = $p;
    }
    $html .= '<li class="page-item ' . ($current >= $total ? 'disabled' : '') . '">';
    $html .= '<a class="page-link" href="' . $baseUrl . '&' . $pageParam . '=' . ($current + 1) . '">&raquo;</a></li>';
    $html .= '</ul></nav>';
    return $html;
}
}

if (!function_exists('monthName')) {
function monthName($m) {
    $months = ['', 'січня', 'лютого', 'березня', 'квітня', 'травня', 'червня', 'липня', 'серпня', 'вересня', 'жовтня', 'листопада', 'грудня'];
    return $months[(int)$m] ?? '';
}
}

if (!function_exists('num2str')) {
function num2str($num) {
    $num = round($num, 2);
    $hryvnia = floor($num);
    $kopiyky = round(($num - $hryvnia) * 100);

    $units = ['', 'один', 'два', 'три', 'чотири', 'п\'ять', 'шість', 'сім', 'вісім', 'дев\'ять'];
    $unitsF = ['', 'одна', 'дві', 'три', 'чотири', 'п\'ять', 'шість', 'сім', 'вісім', 'дев\'ять'];
    $teens = ['десять', 'одинадцять', 'дванадцять', 'тринадцять', 'чотирнадцять', 'п\'ятнадцять', 'шістнадцять', 'сімнадцять', 'вісімнадцять', 'дев\'ятнадцять'];
    $tens = ['', '', 'двадцять', 'тридцять', 'сорок', 'п\'ятдесят', 'шістдесят', 'сімдесят', 'вісімдесят', 'дев\'яносто'];
    $hundreds = ['', 'сто', 'двісті', 'триста', 'чотириста', 'п\'ятсот', 'шістсот', 'сімсот', 'вісімсот', 'дев\'ятсот'];

    $hryvniaForms = ['гривня', 'гривні', 'гривень'];
    $kopiykyForms = ['копійка', 'копійки', 'копійок'];

    $pluralForm = function($n, $forms) {
        $n = abs($n) % 100;
        $n1 = $n % 10;
        if ($n > 10 && $n < 20) return $forms[2];
        if ($n1 > 1 && $n1 < 5) return $forms[1];
        if ($n1 == 1) return $forms[0];
        return $forms[2];
    };

    $numToWords = function($n, $units) use ($hundreds, $tens, $teens) {
        if ($n == 0) return 'нуль';
        $result = '';
        if ($n >= 100) {
            $result .= $hundreds[floor($n / 100)] . ' ';
            $n %= 100;
        }
        if ($n >= 20) {
            $result .= $tens[floor($n / 10)] . ' ';
            $n %= 10;
        } elseif ($n >= 10) {
            $result .= $teens[$n - 10] . ' ';
            $n = 0;
        }
        if ($n > 0) {
            $result .= $units[$n] . ' ';
        }
        return trim($result);
    };

    $words = $numToWords($hryvnia, $unitsF);
    $words .= ' ' . $pluralForm($hryvnia, $hryvniaForms);
    if ($kopiyky > 0) {
        $words .= ' ' . $kopiyky . ' ' . $pluralForm($kopiyky, $kopiykyForms);
    }
    return $words;
}

if (!function_exists('getCategories')) {
function getCategories($pdo) {
    $stmt = $pdo->query("SELECT * FROM erp_categories ORDER BY sort_order ASC, name ASC");
    return $stmt->fetchAll();
}
}

if (!function_exists('getProductCategoryIds')) {
function getProductCategoryIds($pdo, $productId) {
    $stmt = $pdo->prepare("SELECT category_id FROM erp_product_categories WHERE product_id = ?");
    $stmt->execute([$productId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}
}

if (!function_exists('getCategoryFilter')) {
function getCategoryFilter($pdo, $selectedCategoryId = 0) {
    $categories = getCategories($pdo);
    $html = '<select name="category_id" class="form-select form-select-sm category-filter" style="max-width:200px;" data-selected="' . (int)$selectedCategoryId . '">';
    $html .= '<option value="">Всі категорії</option>';
    foreach ($categories as $c) {
        $sel = (int)$selectedCategoryId === (int)$c['category_id'] ? ' selected' : '';
        $html .= '<option value="' . (int)$c['category_id'] . '"' . $sel . '>' . escape($c['name']) . '</option>';
    }
    $html .= '</select>';
    return $html;
}
}

if (!function_exists('getCategoryName')) {
function getCategoryName($pdo, $categoryId) {
    if (!$categoryId) return '-';
    $stmt = $pdo->prepare("SELECT name FROM erp_categories WHERE category_id = ?");
    $stmt->execute([$categoryId]);
    $row = $stmt->fetch();
    return $row ? $row['name'] : '-';
}
}
}
