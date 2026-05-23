<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../includes/opencart_api.php';

requireLogin();
header('Content-Type: application/json');

if (!isAdmin()) {
    echo json_encode(['error' => 'Недостатньо прав']);
    exit;
}

try {
    $rates = getCurrentRates($pdo);
    $markups = getDefaultMarkups($pdo);

    $sql = "SELECT p.product_id, p.name,
        COALESCE(AVG(CASE WHEN sm.type IN ('in','return_in') THEN sm.cost_price ELSE NULL END), 0) as avg_cost,
        COALESCE((SELECT currency FROM erp_incoming_invoices ii JOIN erp_invoice_items iit ON ii.invoice_id = iit.invoice_id WHERE iit.product_id = p.product_id ORDER BY ii.date_added DESC LIMIT 1), 'EUR') as purchase_currency
        FROM erp_products p
        LEFT JOIN erp_stock_moves sm ON p.product_id = sm.product_id
        GROUP BY p.product_id
        HAVING avg_cost > 0
        ORDER BY p.name ASC";
    $products = $pdo->query($sql)->fetchAll();

    if (empty($products)) {
        echo json_encode(['error' => 'Немає товарів із собівартістю. Спочатку додайте надходження.']);
        exit;
    }

    $pricingRules = [];
    $rulesStmt = $pdo->query("SELECT * FROM erp_pricing_rules");
    while ($r = $rulesStmt->fetch()) {
        $pricingRules[$r['product_id']] = $r;
    }

    $toUpdate = [];
    $erpUpdated = 0;

    $updateStmt = $pdo->prepare("UPDATE erp_products SET price_wholesale = ?, price_semi_wholesale = ?, price_retail = ? WHERE product_id = ?");

    foreach ($products as $p) {
        $pid = (int)$p['product_id'];
        $purchaseCur = $p['purchase_currency'] ?: 'EUR';
        $rate = $rates[$purchaseCur] ?? $rates['EUR'];
        $costForeign = (float)$p['avg_cost'];
        $costUah = $costForeign * $rate;
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

    $result['erp_updated'] = $erpUpdated;
    $result['rates_used'] = $rates;

    echo json_encode($result);

} catch (Exception $e) {
    echo json_encode(['error' => 'Помилка: ' . $e->getMessage()]);
}
