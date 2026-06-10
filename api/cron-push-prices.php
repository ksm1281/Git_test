<?php
/**
 * Cron endpoint for automated price push to OpenCart.
 * 
 * Usage (crontab):
 *   0 3 * * * curl -s "https://example.com/ERP/api/cron-push-prices.php?key=YOUR_CRON_SECRET"
 *   Or every hour:
 *   0 * * * * curl -s "https://example.com/ERP/api/cron-push-prices.php?key=YOUR_CRON_SECRET"
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../includes/opencart_api.php';

header('Content-Type: text/plain; charset=utf-8');

// Auth: secret key via GET or via config (for CLI mode)
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

$startTime = microtime(true);

try {
    try {
        $pdo->exec("ALTER TABLE erp_pricing_rules ADD COLUMN price_eur DECIMAL(12,2) NOT NULL DEFAULT 0");
    } catch (Exception $e) {}

    $rates = getCurrentRates($pdo);
    $markups = getDefaultMarkups($pdo);

    $sql = "SELECT p.product_id, p.name,
        COALESCE(AVG(CASE WHEN sm.type IN ('in','return_in','adjustment') THEN sm.cost_price ELSE NULL END), 0) as avg_cost,
        COALESCE((SELECT currency FROM erp_incoming_invoices ii JOIN erp_invoice_items iit ON ii.invoice_id = iit.invoice_id WHERE iit.product_id = p.product_id ORDER BY ii.date_added DESC LIMIT 1), 'EUR') as purchase_currency,
        pr.price_eur
        FROM erp_products p
        LEFT JOIN erp_pricing_rules pr ON p.product_id = pr.product_id
        LEFT JOIN erp_stock_moves sm ON p.product_id = sm.product_id
        GROUP BY p.product_id
        HAVING avg_cost > 0 OR price_eur > 0
        ORDER BY p.name ASC";
    $products = $pdo->query($sql)->fetchAll();

    if (empty($products)) {
        echo "OK: No products with cost or EUR price\n";
        exit(0);
    }

    $pricingRules = [];
    $rulesStmt = $pdo->query("SELECT * FROM erp_pricing_rules");
    while ($r = $rulesStmt->fetch()) {
        $pricingRules[$r['product_id']] = $r;
    }

    $updateStmt = $pdo->prepare("UPDATE erp_products SET price_wholesale = ?, price_semi_wholesale = ?, price_retail = ? WHERE product_id = ?");
    $toUpdate = [];
    $erpUpdated = 0;

    foreach ($products as $p) {
        $pid = (int)$p['product_id'];
        $priceEur = (float)$p['price_eur'];
        $costForeign = $priceEur > 0 ? $priceEur : (float)$p['avg_cost'];
        $purchaseCur = $priceEur > 0 ? 'EUR' : ($p['purchase_currency'] ?: 'EUR');
        $rate = $rates[$purchaseCur] ?? $rates['EUR'];
        $costUah = round($costForeign * $rate);
        if ($costUah <= 0) continue;

        $rule = $pricingRules[$pid] ?? null;

        if ($rule && $rule['use_custom']) {
            $pw = (float)$rule['custom_price_wholesale'];
            $ps = (float)$rule['custom_price_semi_wholesale'];
            $pr = (float)$rule['custom_price_retail'];
        } else {
            $mw = $rule ? (float)$rule['markup_wholesale'] : $markups['default_markup_wholesale'];
            $ms = $rule ? (float)$rule['markup_semi_wholesale'] : $markups['default_markup_semi_wholesale'];
            $mr = $rule ? (float)$rule['markup_retail'] : $markups['default_markup_retail'];
            $pw = calculatePrice($costUah, $mw);
            $ps = calculatePrice($costUah, $ms);
            $pr = calculatePrice($costUah, $mr);
        }

        $updateStmt->execute([$pw, $ps, $pr, $pid]);
        $erpUpdated++;

        $toUpdate[] = [
            'product_id' => $pid,
            'name' => $p['name'],
            'price_wholesale' => round($pw, 2),
            'price_semi_wholesale' => round($ps, 2),
            'price_retail' => round($pr, 2),
        ];
    }

    $api = new OpenCartDbClient();
    $result = $api->pushPrices($pdo, $toUpdate);

    $elapsed = round(microtime(true) - $startTime, 2);

    echo "OK: ERP updated: {$erpUpdated}, OC pushed: {$result['updated']}, time: {$elapsed}s\n";

    if (!empty($result['errors'])) {
        echo "WARN: OC errors: " . implode('; ', array_slice($result['errors'], 0, 5)) . "\n";
    }

    echo "Rates: USD {$rates['USD']}, EUR {$rates['EUR']}\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
