<?php
require_once __DIR__ . '/../config.php';
requireLogin();

$action = $_GET['action'] ?? 'list';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;
$search = trim($_GET['search'] ?? '');
$user = getUserData();

$markups = getDefaultMarkups($pdo);
$rate = getCurrentRate($pdo);

if ($action === 'save_defaults' && $_SERVER['REQUEST_METHOD'] === 'POST' && isAdmin()) {
    $stmt = $pdo->prepare("UPDATE erp_settings SET value = ? WHERE `key` = ?");
    $stmt->execute([(float)$_POST['default_markup_wholesale'], 'default_markup_wholesale']);
    $stmt->execute([(float)$_POST['default_markup_semi_wholesale'], 'default_markup_semi_wholesale']);
    $stmt->execute([(float)$_POST['default_markup_retail'], 'default_markup_retail']);
    flashMessage('success', 'Налаштування цін збережено');
    redirect(BASE_URL . '/modules/pricing.php');
}

if ($action === 'save_rule' && $_SERVER['REQUEST_METHOD'] === 'POST' && isManager()) {
    $productId = (int)($_POST['product_id'] ?? 0);
    $mw = (float)($_POST['markup_wholesale'] ?? 0);
    $ms = (float)($_POST['markup_semi_wholesale'] ?? 0);
    $mr = (float)($_POST['markup_retail'] ?? 0);
    $useCustom = (int)($_POST['use_custom'] ?? 0);
    $cpw = (float)($_POST['custom_price_wholesale'] ?? 0);
    $cps = (float)($_POST['custom_price_semi_wholesale'] ?? 0);
    $cpr = (float)($_POST['custom_price_retail'] ?? 0);

    if ($productId) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM erp_pricing_rules WHERE product_id = ?");
        $stmt->execute([$productId]);
        if ($stmt->fetchColumn() > 0) {
            $stmt = $pdo->prepare("UPDATE erp_pricing_rules SET markup_wholesale=?, markup_semi_wholesale=?, markup_retail=?, use_custom=?, custom_price_wholesale=?, custom_price_semi_wholesale=?, custom_price_retail=? WHERE product_id=?");
            $stmt->execute([$mw, $ms, $mr, $useCustom, $cpw, $cps, $cpr, $productId]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO erp_pricing_rules (product_id, markup_wholesale, markup_semi_wholesale, markup_retail, use_custom, custom_price_wholesale, custom_price_semi_wholesale, custom_price_retail) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$productId, $mw, $ms, $mr, $useCustom, $cpw, $cps, $cpr]);
        }
        flashMessage('success', 'Правило ціноутворення збережено');
    }
    redirect(BASE_URL . '/modules/pricing.php');
}

if ($action === 'edit' && $page) {
    $pid = (int)($_GET['product_id'] ?? 0);
    if ($pid) {
        $stmt = $pdo->prepare("SELECT p.*, pr.* FROM erp_products p LEFT JOIN erp_pricing_rules pr ON p.product_id = pr.product_id WHERE p.product_id = ?");
        $stmt->execute([$pid]);
        $product = $stmt->fetch();
        if ($product) include __DIR__ . '/edit_pricing.php';
    }
    exit;
}

$where = '';
$params = [];
if ($search) {
    $where = "WHERE (p.name LIKE ? OR p.model LIKE ? OR p.sku LIKE ? OR CAST(p.product_id AS CHAR) LIKE ?)";
    $s = "%$search%";
    $params = [$s, $s, $s, $s];
}

$countSql = "SELECT COUNT(*) FROM erp_products p $where";
$stmt = $pdo->prepare($countSql);
$stmt->execute($params);
$total = $stmt->fetchColumn();
$pagination = paginate($total, $perPage, $page);

$sql = "SELECT p.*, pr.markup_wholesale, pr.markup_semi_wholesale, pr.markup_retail, pr.use_custom, pr.custom_price_wholesale, pr.custom_price_semi_wholesale, pr.custom_price_retail,
    COALESCE(AVG(CASE WHEN sm.type IN ('in','return_in') THEN sm.cost_price ELSE NULL END), 0) as avg_cost
    FROM erp_products p
    LEFT JOIN erp_pricing_rules pr ON p.product_id = pr.product_id
    LEFT JOIN erp_stock_moves sm ON p.product_id = sm.product_id
    $where
    GROUP BY p.product_id
    ORDER BY p.name ASC
    LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

function calcMarkupPrice($cost, $markupPercent) {
    return $cost * (1 + $markupPercent / 100);
}

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="bi bi-currency-exchange"></i> Ціноутворення</h4>
    <div class="d-flex gap-2">
        <form method="get" class="d-flex">
            <input type="search" name="search" class="form-control form-control-sm search-box me-2" placeholder="Пошук товару..." value="<?php echo escape($search); ?>">
            <button class="btn btn-sm btn-outline-primary"><i class="bi bi-search"></i></button>
        </form>
        <?php if (isAdmin()): ?>
        <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#defaultsModal"><i class="bi bi-gear"></i> Налаштування</button>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card">
            <div class="card-body text-center">
                <small class="text-muted">Націнка опт</small>
                <div class="fw-bold fs-5"><?php echo $markups['default_markup_wholesale']; ?>%</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card">
            <div class="card-body text-center">
                <small class="text-muted">Націнка дрібний опт</small>
                <div class="fw-bold fs-5"><?php echo $markups['default_markup_semi_wholesale']; ?>%</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card">
            <div class="card-body text-center">
                <small class="text-muted">Націнка роздріб</small>
                <div class="fw-bold fs-5"><?php echo $markups['default_markup_retail']; ?>%</div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">Товари та ціни</div>
    <div class="card-body p-0">
        <?php if (count($products) > 0): ?>
        <div class="table-container">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Товар</th>
                        <th class="text-end">Собівартість (USD)</th>
                        <th class="text-end">Собівартість (UAH)</th>
                        <th class="text-end">Опт</th>
                        <th class="text-end">Дріб. опт</th>
                        <th class="text-end">Роздріб</th>
                        <th class="text-center">Дії</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $p): ?>
                    <?php
                        $costUsd = (float)$p['avg_cost'];
                        $costUah = $costUsd * $rate;
                        $mw = $p['markup_wholesale'] ?? $markups['default_markup_wholesale'];
                        $ms = $p['markup_semi_wholesale'] ?? $markups['default_markup_semi_wholesale'];
                        $mr = $p['markup_retail'] ?? $markups['default_markup_retail'];
                        $pw = $p['use_custom'] ? $p['custom_price_wholesale'] : calcMarkupPrice($costUah, $mw);
                        $ps = $p['use_custom'] ? $p['custom_price_semi_wholesale'] : calcMarkupPrice($costUah, $ms);
                        $pr = $p['use_custom'] ? $p['custom_price_retail'] : calcMarkupPrice($costUah, $mr);
                    ?>
                    <tr>
                        <td class="text-truncate" style="max-width:200px;"><?php echo escape($p['name'] ?: 'ID: ' . $p['product_id']); ?></td>
                        <td class="text-end"><?php echo $costUsd > 0 ? formatMoneyForeign($costUsd) : '-'; ?></td>
                        <td class="text-end"><?php echo $costUah > 0 ? formatMoney($costUah) : '-'; ?></td>
                        <td class="text-end fw-bold"><?php echo formatMoney($pw); ?></td>
                        <td class="text-end fw-bold"><?php echo formatMoney($ps); ?></td>
                        <td class="text-end fw-bold"><?php echo formatMoney($pr); ?></td>
                        <td class="text-center">
                            <button class="btn btn-sm btn-outline-primary edit-pricing" data-product-id="<?php echo $p['product_id']; ?>" data-name="<?php echo escape($p['name'] ?: 'ID: ' . $p['product_id']); ?>" data-mw="<?php echo $mw; ?>" data-ms="<?php echo $ms; ?>" data-mr="<?php echo $mr; ?>" data-use-custom="<?php echo (int)$p['use_custom']; ?>" data-cpw="<?php echo (float)$p['custom_price_wholesale']; ?>" data-cps="<?php echo (float)$p['custom_price_semi_wholesale']; ?>" data-cpr="<?php echo (float)$p['custom_price_retail']; ?>" title="Налаштувати ціни">
                                <i class="bi bi-sliders"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="text-center py-5 text-muted">
            <i class="bi bi-currency-exchange" style="font-size:3rem;"></i>
            <p class="mt-3 mb-0"><?php echo $search ? 'Нічого не знайдено' : 'Немає товарів для ціноутворення'; ?></p>
        </div>
        <?php endif; ?>
    </div>
    <?php if ($pagination['total_pages'] > 1): ?>
    <div class="card-footer"><?php echo renderPagination(BASE_URL . '/modules/pricing.php?', $pagination); ?></div>
    <?php endif; ?>
</div>

<div class="modal fade" id="pricingModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" action="?action=save_rule">
                <div class="modal-header">
                    <h5 class="modal-title">Ціноутворення: <span id="modalProductName"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="product_id" id="modalProductId">
                    <div class="mb-3">
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" name="use_custom" id="useCustom" value="1">
                            <label class="form-check-label" for="useCustom">Використовувати власні ціни</label>
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Націнка опт (%)</label>
                            <input type="number" name="markup_wholesale" id="mw" class="form-control" step="0.01" min="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Націнка дріб. опт (%)</label>
                            <input type="number" name="markup_semi_wholesale" id="ms" class="form-control" step="0.01" min="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Націнка роздріб (%)</label>
                            <input type="number" name="markup_retail" id="mr" class="form-control" step="0.01" min="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Власна ціна опт (UAH)</label>
                            <input type="number" name="custom_price_wholesale" id="cpw" class="form-control" step="0.01" min="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Власна ціна дріб. опт (UAH)</label>
                            <input type="number" name="custom_price_semi_wholesale" id="cps" class="form-control" step="0.01" min="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Власна ціна роздріб (UAH)</label>
                            <input type="number" name="custom_price_retail" id="cpr" class="form-control" step="0.01" min="0">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Скасувати</button>
                    <button type="submit" class="btn btn-primary">Зберегти</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if (isAdmin()): ?>
<div class="modal fade" id="defaultsModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" action="?action=save_defaults">
                <div class="modal-header">
                    <h5 class="modal-title">Налаштування націнок</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Опт (%)</label>
                            <input type="number" name="default_markup_wholesale" class="form-control" step="0.01" value="<?php echo $markups['default_markup_wholesale']; ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Дріб. опт (%)</label>
                            <input type="number" name="default_markup_semi_wholesale" class="form-control" step="0.01" value="<?php echo $markups['default_markup_semi_wholesale']; ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Роздріб (%)</label>
                            <input type="number" name="default_markup_retail" class="form-control" step="0.01" value="<?php echo $markups['default_markup_retail']; ?>">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Скасувати</button>
                    <button type="submit" class="btn btn-primary">Зберегти</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
document.querySelectorAll('.edit-pricing').forEach(function(btn) {
    btn.addEventListener('click', function() {
        document.getElementById('modalProductId').value = this.dataset.productId;
        document.getElementById('modalProductName').textContent = this.dataset.name;
        document.getElementById('mw').value = this.dataset.mw;
        document.getElementById('ms').value = this.dataset.ms;
        document.getElementById('mr').value = this.dataset.mr;
        document.getElementById('useCustom').checked = this.dataset.useCustom === '1';
        document.getElementById('cpw').value = this.dataset.cpw;
        document.getElementById('cps').value = this.dataset.cps;
        document.getElementById('cpr').value = this.dataset.cpr;
        new bootstrap.Modal(document.getElementById('pricingModal')).show();
    });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
