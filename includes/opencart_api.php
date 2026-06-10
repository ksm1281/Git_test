<?php

class OpenCartApiClient {
    private $apiUrl;
    private $apiKey;
    private $token;

    public function __construct() {
        $this->apiUrl = rtrim(OC_API_URL, '/') . '/';
        $this->apiKey = OC_API_KEY;

        if (strpos($this->apiUrl, 'your-opencart-store') !== false || $this->apiUrl === '/' || empty($this->apiUrl)) {
            throw new Exception('OC_API_URL не змінено! Поточне значення: "' . OC_API_URL . '". Відредагуйте config.local.php');
        }
        if (strpos($this->apiKey, 'your-api-key') !== false || empty($this->apiKey)) {
            throw new Exception('OC_API_KEY не змінено! Поточне значення: "' . substr(OC_API_KEY, 0, 10) . '...". Відредагуйте config.local.php');
        }
    }

    public function login() {
        $url = $this->apiUrl . 'login';
        $data = http_build_query(['api_key' => $this->apiKey]);
        $result = $this->httpPost($url, $data);

        if (isset($result['_error'])) {
            if (strpos($result['_error'], 'HTTP 3') === 0) {
                $this->token = 'direct';
                return $this->token;
            }
            throw new Exception('OpenCart API login failed: ' . $result['_error']);
        }
        if (!$result) {
            $this->token = 'direct';
            return $this->token;
        }
        if (!empty($result['error'])) {
            throw new Exception('OpenCart API login error: ' . $result['error']);
        }
        $this->token = $result['api_token'] ?? $result['token'] ?? '';
        if (!$this->token) {
            $this->token = 'direct';
            return $this->token;
        }
        return $this->token;
    }

    public function getProducts($page = 1, $limit = 100) {
        $this->ensureLogin();
        $url = $this->apiAuth($this->apiUrl . 'product/products')
            . '&limit=' . (int)$limit . '&page=' . (int)$page;
        $result = $this->httpGet($url);
        if (isset($result['_error'])) {
            throw new Exception('Failed to fetch products: ' . $result['_error']);
        }
        if (isset($result['error'])) {
            throw new Exception('Failed to fetch products: ' . $result['error']);
        }
        return $result;
    }

    public function getProduct($id) {
        $this->ensureLogin();
        $url = $this->apiAuth($this->apiUrl . 'product/product')
            . '&id=' . (int)$id;
        $result = $this->httpGet($url);
        if (isset($result['_error'])) {
            throw new Exception('Failed to fetch product ' . $id . ': ' . $result['_error']);
        }
        if (isset($result['error'])) {
            throw new Exception('Failed to fetch product ' . $id . ': ' . $result['error']);
        }
        return $result;
    }

    public function syncProductByCode($pdo, $code) {
        if (is_numeric($code)) {
            try {
                return $this->syncProduct($pdo, (int)$code);
            } catch (Exception $e) {
            }
        }

        $this->ensureLogin();
        $page = 1;
        $limit = 100;

        do {
            $data = $this->getProducts($page, $limit);
            $products = $data['products'] ?? [];
            $total = (int)($data['product_total'] ?? 0);
            $totalPages = max(1, (int)ceil($total / $limit));

            foreach ($products as $p) {
                $searchIn = strtolower(($p['model'] ?? '') . ' ' . ($p['sku'] ?? '') . ' ' . ($p['name'] ?? '') . ' ' . ($p['product_id'] ?? ''));
                if (strpos($searchIn, strtolower($code)) !== false) {
                    return $this->syncProduct($pdo, (int)$p['product_id']);
                }
            }
            $page++;
        } while ($page <= $totalPages);

        throw new Exception('Товар з кодом "' . $code . '" не знайдено');
    }

    public function syncProduct($pdo, $id) {
        $data = $this->getProduct($id);
        $p = $data['product'] ?? null;
        if (!$p) throw new Exception('Product not found: ' . $id);

        $stmt = $pdo->prepare("
            INSERT INTO erp_products (product_id, model, sku, name, image, quantity, status, date_synced)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                image = VALUES(image),
                quantity = VALUES(quantity),
                status = VALUES(status),
                date_synced = NOW()
        ");
        $stmt->execute([
            (int)$p['product_id'],
            $p['model'] ?? '',
            $p['sku'] ?? '',
            $p['name'] ?? '',
            $p['image'] ?? '',
            (int)($p['quantity'] ?? 0),
            (int)($p['status'] ?? 1),
        ]);

        $stmt = $pdo->prepare("INSERT INTO erp_sync_log (type, status, records_synced, message) VALUES ('products', 'success', 1, ?)");
        $stmt->execute(['Синхронізовано товар ID ' . $id . ': ' . ($p['name'] ?? '')]);

        return ['synced' => 1, 'product_id' => (int)$p['product_id'], 'name' => $p['name'] ?? ''];
    }

    public function getTotalProducts() {
        $data = $this->getProducts(1, 1);
        return (int)($data['product_total'] ?? 0);
    }

    public function syncProducts($pdo) {
        $total = $this->getTotalProducts();
        $limit = 100;
        $pages = max(1, ceil($total / $limit));
        $synced = 0;
        $errors = [];

        for ($page = 1; $page <= $pages; $page++) {
            try {
                $data = $this->getProducts($page, $limit);
                $products = $data['products'] ?? [];
                if (empty($products)) continue;

                $stmt = $pdo->prepare("
                    INSERT INTO erp_products (product_id, model, sku, name, image, quantity, status, date_synced)
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE
                        model = VALUES(model),
                        sku = VALUES(sku),
                        name = VALUES(name),
                        image = VALUES(image),
                        quantity = VALUES(quantity),
                        status = VALUES(status),
                        date_synced = NOW()
                ");

                foreach ($products as $p) {
                    $stmt->execute([
                        (int)$p['product_id'],
                        $p['model'] ?? '',
                        $p['sku'] ?? '',
                        $p['name'] ?? '',
                        $p['image'] ?? '',
                        (int)($p['quantity'] ?? 0),
                        (int)($p['status'] ?? 1),
                    ]);
                    $synced++;
                }
            } catch (Exception $e) {
                $errors[] = "Page $page: " . $e->getMessage();
            }
        }

        $status = empty($errors) ? 'success' : 'partial';
        $msg = "Синхронізовано $synced з $total товарів";
        if ($errors) $msg .= '. Помилки: ' . implode('; ', array_slice($errors, 0, 5));

        $stmt = $pdo->prepare("INSERT INTO erp_sync_log (type, status, records_synced, message) VALUES ('products', ?, ?, ?)");
        $stmt->execute([$status, $synced, $msg]);

        return ['synced' => $synced, 'total' => $total, 'status' => $status, 'message' => $msg];
    }

    private function ensureLogin() {
        if (!$this->token) {
            try {
                $this->login();
            } catch (Exception $e) {
                $this->token = 'direct';
            }
        }
    }

    private function apiAuth($url) {
        if ($this->token === 'direct') {
            $sep = (strpos($url, '?') === false) ? '?' : '&';
            return $url . $sep . 'api_key=' . urlencode($this->apiKey);
        }
        return $url . '&api_token=' . urlencode($this->token);
    }

    private function httpPost($url, $data) {
        try {
            if (function_exists('curl_init')) {
                return $this->curlPost($url, $data);
            }
            return $this->fopenPost($url, $data);
        } catch (\Throwable $e) {
            return ['_error' => 'Exception: ' . $e->getMessage()];
        }
    }

    private function httpGet($url) {
        try {
            if (function_exists('curl_init')) {
                return $this->curlGet($url);
            }
            return $this->fopenGet($url);
        } catch (\Throwable $e) {
            return ['_error' => 'Exception: ' . $e->getMessage()];
        }
    }

    private function fopenPost($url, $data) {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\nUser-Agent: ERP-CRM/1.0\r\n",
                'content' => $data,
                'timeout' => 30,
                'ignore_errors' => true,
            ],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);
        $response = @file_get_contents($url, false, $ctx);
        if ($response === false) {
            $error = error_get_last();
            return ['_error' => $error['message'] ?? 'file_get_contents returned false (URL: ' . $url . ')'];
        }
        $decoded = json_decode($response, true);
        if ($decoded === null) {
            return ['_error' => 'Invalid JSON: ' . substr(strip_tags($response), 0, 200)];
        }
        return $decoded;
    }

    private function fopenGet($url) {
        $ctx = stream_context_create([
            'http' => ['timeout' => 30, 'ignore_errors' => true, 'header' => "User-Agent: ERP-CRM/1.0\r\n"],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);
        $response = @file_get_contents($url, false, $ctx);
        if ($response === false) {
            $error = error_get_last();
            return ['_error' => $error['message'] ?? 'file_get_contents returned false (URL: ' . $url . ')'];
        }
        $decoded = json_decode($response, true);
        if ($decoded === null) {
            return ['_error' => 'Invalid JSON: ' . substr(strip_tags($response), 0, 200)];
        }
        return $decoded;
    }

    private function curlPost($url, $data) {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['_error' => 'curl_init failed for: ' . $url];
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT => 'ERP-CRM/1.0',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_POSTREDIR => 3,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $response === '') {
            $msg = $curlError ?: 'Empty response';
            return ['_error' => 'cURL error: ' . $msg . ' (HTTP ' . $httpCode . ', URL: ' . $url . ')'];
        }
        if ($httpCode >= 400) {
            return ['_error' => 'HTTP ' . $httpCode . ': ' . substr(strip_tags($response), 0, 500)];
        }
        $decoded = json_decode($response, true);
        if ($decoded === null && strtolower(trim($response)) !== 'null') {
            return ['_error' => 'Invalid JSON (HTTP ' . $httpCode . '): ' . substr(strip_tags($response), 0, 500)];
        }
        if ($decoded === null) $decoded = [];
        return $decoded;
    }

    private function curlGet($url) {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['_error' => 'curl_init failed for: ' . $url];
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT => 'ERP-CRM/1.0',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $response === '') {
            $msg = $curlError ?: 'Empty response';
            return ['_error' => 'cURL error: ' . $msg . ' (HTTP ' . $httpCode . ', URL: ' . $url . ')'];
        }
        if ($httpCode >= 400) {
            return ['_error' => 'HTTP ' . $httpCode . ': ' . substr(strip_tags($response), 0, 500)];
        }
        $decoded = json_decode($response, true);
        if ($decoded === null && strtolower(trim($response)) !== 'null') {
            return ['_error' => 'Invalid JSON (HTTP ' . $httpCode . '): ' . substr(strip_tags($response), 0, 500)];
        }
        if ($decoded === null) $decoded = [];
        return $decoded;
    }
}

class OpenCartDbClient {
    private $pdo;

    public function __construct() {
        try {
            $this->pdo = new PDO(
                "mysql:host=" . OC_DB_HOST . ";dbname=" . OC_DB_NAME . ";charset=utf8mb4",
                OC_DB_USER,
                OC_DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );
        } catch (PDOException $e) {
            throw new Exception('OpenCart DB connection failed: ' . $e->getMessage());
        }
    }

    public function getProduct($id) {
        $prefix = OC_DB_PREFIX;
        $stmt = $this->pdo->prepare("
            SELECT p.product_id, p.model, p.sku, pd.name, p.image, p.quantity, p.status, p.price
            FROM {$prefix}product p
            LEFT JOIN {$prefix}product_description pd ON p.product_id = pd.product_id
                AND pd.language_id = COALESCE(
                    (SELECT language_id FROM {$prefix}language WHERE code = 'uk' LIMIT 1),
                    (SELECT language_id FROM {$prefix}language WHERE code = 'uk-ua' LIMIT 1),
                    (SELECT language_id FROM {$prefix}language WHERE code = 'ru' LIMIT 1),
                    (SELECT language_id FROM {$prefix}language WHERE code = 'en' LIMIT 1),
                    (SELECT MIN(language_id) FROM {$prefix}language LIMIT 1)
                )
            WHERE p.product_id = ?
        ");
        $stmt->execute([(int)$id]);
        $product = $stmt->fetch();
        if (!$product) throw new Exception('Товар з ID ' . $id . ' не знайдено в OpenCart');

        $discStmt = $this->pdo->prepare("
            SELECT price, quantity FROM {$prefix}product_discount
            WHERE product_id = ? AND quantity > 0
            ORDER BY quantity ASC LIMIT 2
        ");
        $discStmt->execute([(int)$id]);
        $product['discounts'] = $discStmt->fetchAll();

        return ['product' => $product];
    }

    public function getProducts($page = 1, $limit = 100) {
        $prefix = OC_DB_PREFIX;
        $offset = ($page - 1) * $limit;
        $stmt = $this->pdo->prepare("
            SELECT p.product_id, p.model, p.sku, pd.name, p.image, p.quantity, p.status, p.price
            FROM {$prefix}product p
            LEFT JOIN {$prefix}product_description pd ON p.product_id = pd.product_id
                AND pd.language_id = COALESCE(
                    (SELECT language_id FROM {$prefix}language WHERE code = 'uk' LIMIT 1),
                    (SELECT language_id FROM {$prefix}language WHERE code = 'uk-ua' LIMIT 1),
                    (SELECT language_id FROM {$prefix}language WHERE code = 'ru' LIMIT 1),
                    (SELECT language_id FROM {$prefix}language WHERE code = 'en' LIMIT 1),
                    (SELECT MIN(language_id) FROM {$prefix}language LIMIT 1)
                )
            ORDER BY p.product_id ASC
            LIMIT ? OFFSET ?
        ");
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $products = $stmt->fetchAll();

        $countStmt = $this->pdo->query("SELECT COUNT(*) FROM {$prefix}product");
        $total = (int)$countStmt->fetchColumn();

        return ['products' => $products, 'product_total' => $total];
    }

    public function getTotalProducts() {
        $prefix = OC_DB_PREFIX;
        return (int)$this->pdo->query("SELECT COUNT(*) FROM {$prefix}product")->fetchColumn();
    }

    public function syncProductByCode($pdo, $code) {
        $prefix = OC_DB_PREFIX;

        if (is_numeric($code)) {
            try {
                return $this->syncProduct($pdo, (int)$code);
            } catch (Exception $e) {
                $stmt = $this->pdo->prepare("
                    SELECT p.product_id FROM {$prefix}product p WHERE p.model = ? OR p.sku = ? LIMIT 1
                ");
                $stmt->execute([$code, $code]);
                $row = $stmt->fetch();
                if ($row) {
                    return $this->syncProduct($pdo, (int)$row['product_id']);
                }
            }
        }

        $like = '%' . $code . '%';
        $stmt = $this->pdo->prepare("
            SELECT p.product_id FROM {$prefix}product p
            LEFT JOIN {$prefix}product_description pd ON p.product_id = pd.product_id
            WHERE p.model LIKE ? OR p.sku LIKE ? OR pd.name LIKE ?
            LIMIT 1
        ");
        $stmt->execute([$like, $like, $like]);
        $row = $stmt->fetch();
        if ($row) {
            return $this->syncProduct($pdo, (int)$row['product_id']);
        }

        throw new Exception('Товар з кодом "' . $code . '" не знайдено');
    }

    public function syncProduct($pdo, $id) {
        $data = $this->getProduct($id);
        $p = $data['product'] ?? null;
        if (!$p) throw new Exception('Product not found: ' . $id);

        $stmt = $pdo->prepare("
            INSERT INTO erp_products (product_id, model, sku, name, image, quantity, status, date_synced)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                image = VALUES(image),
                quantity = VALUES(quantity),
                status = VALUES(status),
                date_synced = NOW()
        ");
        $stmt->execute([
            (int)$p['product_id'],
            $p['model'] ?? '',
            $p['sku'] ?? '',
            $p['name'] ?? '',
            $p['image'] ?? '',
            (int)($p['quantity'] ?? 0),
            (int)($p['status'] ?? 1),
        ]);

        $stmt = $pdo->prepare("INSERT INTO erp_sync_log (type, status, records_synced, message) VALUES ('products', 'success', 1, ?)");
        $stmt->execute(['Синхронізовано товар ID ' . $id . ': ' . ($p['name'] ?? '')]);

        return ['synced' => 1, 'product_id' => (int)$p['product_id'], 'name' => $p['name'] ?? ''];
    }

    public function syncProducts($pdo) {
        $prefix = OC_DB_PREFIX;
        $total = $this->getTotalProducts();
        $limit = 100;
        $pages = max(1, ceil($total / $limit));
        $synced = 0;

        $upsert = $pdo->prepare("
            INSERT INTO erp_products (product_id, model, sku, name, image, quantity, status, date_synced)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                image = VALUES(image),
                quantity = VALUES(quantity),
                status = VALUES(status),
                date_synced = NOW()
        ");

        $langId = $this->pdo->query("
            SELECT COALESCE(
                (SELECT language_id FROM {$prefix}language WHERE code = 'uk' LIMIT 1),
                (SELECT language_id FROM {$prefix}language WHERE code = 'uk-ua' LIMIT 1),
                (SELECT language_id FROM {$prefix}language WHERE code = 'ru' LIMIT 1),
                (SELECT language_id FROM {$prefix}language WHERE code = 'en' LIMIT 1),
                (SELECT MIN(language_id) FROM {$prefix}language LIMIT 1)
            ) as lid
        ")->fetchColumn();

        for ($page = 1; $page <= $pages; $page++) {
            $offset = ($page - 1) * $limit;
            $rows = $this->pdo->prepare("
                SELECT p.product_id, p.model, p.sku, pd.name, p.image, p.quantity, p.status
                FROM {$prefix}product p
                LEFT JOIN {$prefix}product_description pd ON p.product_id = pd.product_id AND pd.language_id = ?
                ORDER BY p.product_id ASC
                LIMIT ? OFFSET ?
            ");
            $rows->bindValue(1, $langId, PDO::PARAM_INT);
            $rows->bindValue(2, $limit, PDO::PARAM_INT);
            $rows->bindValue(3, $offset, PDO::PARAM_INT);
            $rows->execute();
            $products = $rows->fetchAll();
            if (empty($products)) continue;

            foreach ($products as $p) {
                $pid = (int)$p['product_id'];

                $upsert->execute([
                    $pid,
                    $p['model'] ?? '',
                    $p['sku'] ?? '',
                    $p['name'] ?? '',
                    $p['image'] ?? '',
                    (int)($p['quantity'] ?? 0),
                    (int)($p['status'] ?? 1),
                ]);
                $synced++;
            }
        }

        $msg = "Синхронізовано $synced з $total товарів";
        $status = $synced > 0 ? 'success' : 'error';
        $stmt = $pdo->prepare("INSERT INTO erp_sync_log (type, status, records_synced, message) VALUES ('products', ?, ?, ?)");
        $stmt->execute([$status, $synced, $msg]);

        return ['synced' => $synced, 'total' => $total, 'status' => $status, 'message' => $msg];
    }

    public function updateProductPrice($productId, $price) {
        $prefix = OC_DB_PREFIX;
        $stmt = $this->pdo->prepare("UPDATE {$prefix}product SET price = ? WHERE product_id = ?");
        $stmt->execute([$price, $productId]);
        return $stmt->rowCount();
    }

    private function getLanguageId() {
        $prefix = OC_DB_PREFIX;
        $stmt = $this->pdo->query("
            SELECT COALESCE(
                (SELECT language_id FROM {$prefix}language WHERE code = 'uk' LIMIT 1),
                (SELECT language_id FROM {$prefix}language WHERE code = 'uk-ua' LIMIT 1),
                (SELECT language_id FROM {$prefix}language WHERE code = 'ru' LIMIT 1),
                (SELECT language_id FROM {$prefix}language WHERE code = 'en' LIMIT 1),
                (SELECT MIN(language_id) FROM {$prefix}language LIMIT 1)
            ) as lid
        ");
        return (int)$stmt->fetchColumn();
    }

    public function syncCategories($pdo) {
        $prefix = OC_DB_PREFIX;
        $langId = $this->getLanguageId();

        $rows = $this->pdo->query("
            SELECT c.category_id, c.parent_id, c.sort_order, cd.name
            FROM {$prefix}category c
            LEFT JOIN {$prefix}category_description cd ON c.category_id = cd.category_id AND cd.language_id = $langId
            WHERE c.status = 1
            ORDER BY c.sort_order ASC, cd.name ASC
        ")->fetchAll();

        $synced = 0;
        $upsert = $pdo->prepare("
            INSERT INTO erp_categories (category_id, name, parent_id, sort_order)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                parent_id = VALUES(parent_id),
                sort_order = VALUES(sort_order)
        ");

        foreach ($rows as $r) {
            $upsert->execute([
                (int)$r['category_id'],
                $r['name'] ?? 'Category #' . $r['category_id'],
                (int)$r['parent_id'],
                (int)$r['sort_order'],
            ]);
            $synced++;
        }

        $linkStmt = $pdo->prepare("INSERT IGNORE INTO erp_product_categories (product_id, category_id) VALUES (?, ?)");
        $linkRows = $this->pdo->query("SELECT product_id, category_id FROM {$prefix}product_to_category")->fetchAll();
        foreach ($linkRows as $r) {
            $linkStmt->execute([(int)$r['product_id'], (int)$r['category_id']]);
        }

        $msg = "Синхронізовано $synced категорій";
        $stmt = $pdo->prepare("INSERT INTO erp_sync_log (type, status, records_synced, message) VALUES ('categories', 'success', ?, ?)");
        $stmt->execute([$synced, $msg]);

        return ['synced' => $synced, 'message' => $msg];
    }

    public function pushPrices($pdo, $products) {
        $prefix = OC_DB_PREFIX;
        $updated = 0;
        $errors = [];

        $this->pdo->beginTransaction();
        try {
            $priceStmt = $this->pdo->prepare("UPDATE {$prefix}product SET price = ? WHERE product_id = ?");
            $delDiscStmt = $this->pdo->prepare("DELETE FROM {$prefix}product_discount WHERE product_id = ?");
            $getCgStmt = $this->pdo->prepare("SELECT MIN(customer_group_id) FROM {$prefix}product_discount WHERE product_id = ?");
            $insDiscStmt = $this->pdo->prepare("INSERT INTO {$prefix}product_discount (product_id, customer_group_id, quantity, priority, price, date_start, date_end) VALUES (?, ?, ?, 0, ?, '0000-00-00', '0000-00-00')");

            foreach ($products as $p) {
                $pid = $p['product_id'];
                try {
                    $priceStmt->execute([$p['price_retail'], $pid]);

                    $getCgStmt->execute([$pid]);
                    $cgId = (int)$getCgStmt->fetchColumn();
                    if ($cgId <= 0) $cgId = 1;

                    $delDiscStmt->execute([$pid]);

                    if ((float)$p['price_semi_wholesale'] > 0) {
                        $insDiscStmt->execute([$pid, $cgId, 6, $p['price_semi_wholesale']]);
                    }
                    if ((float)$p['price_wholesale'] > 0) {
                        $insDiscStmt->execute([$pid, $cgId, 12, $p['price_wholesale']]);
                    }

                    $updated++;
                } catch (Exception $e) {
                    $errors[] = "ID {$pid}: " . $e->getMessage();
                }
            }
            $this->pdo->commit();
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $msg = "Оновлено цін на сайті: $updated";
        if ($errors) $msg .= '. Помилки: ' . implode('; ', array_slice($errors, 0, 5));

        $stmt = $pdo->prepare("INSERT INTO erp_sync_log (type, status, records_synced, message) VALUES ('prices', ?, ?, ?)");
        $stmt->execute([empty($errors) ? 'success' : 'partial', $updated, $msg]);

        return ['updated' => $updated, 'errors' => $errors, 'message' => $msg];
    }
}
