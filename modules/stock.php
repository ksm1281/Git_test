<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../_helpers.php';
requireLogin();

$action = $_GET['action'] ?? 'list';
$productId = (int)($_GET['id'] ?? 0);
$search = trim($_GET['search'] ?? '');

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;

if ($action === 'moves' && $productId) {
    $stmt = $pdo->prepare("SELECT * FROM erp_stock_moves WHERE product_id = ? ORDER BY date_added DESC LIMIT 100");
    $stmt->execute([$productId]);
    $moves = $stmt->fetchAll();
    $stmt = $pdo->prepare("SELECT * FROM erp_products WHERE product_id = ?");
    $stmt->execute([$productId]);
    $product = $stmt->fetch();
    include __DIR__ . '/../includes/header.php';
    ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">
            <i class="bi bi-arrow-left-right"></i> Рух товару
            <small class="text-muted"><?php echo escape($product['name'] ?? 'ID: ' . $productId); ?></small>
        </h4>
        <div>
            <a href="<?php echo BASE_URL; ?>/modules/stock.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left"></i> Назад
            </a>
        </div>
    </div>
    <div class="card">
        <div class="card-header">Історія руху</div>
        <div class="card-body p-0">
            <?php if (count($moves) > 0): ?>
            <div class="table-container">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Тип</th>
                            <th>Кількість</th>
                            <th>Собівартість</th>
                            <th>Примітки</th>
                            <th>Дата</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($moves as $m): ?>
                        <tr>
                            <td><?php echo (int)$m['move_id']; ?></td>
                            <td><?php echo getStockTypeLabel($m['type']); ?></td>
                            <td class="fw-bold"><?php echo (float)$m['quantity']; ?></td>
                            <td><?php echo $m['cost_price'] ? formatMoneyForeign($m['cost_price']) : '-'; ?></td>
                            <td><?php echo escape($m['notes'] ?: '-'); ?></td>
                            <td><?php echo formatDate($m['date_added']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="text-center py-4 text-muted"><p class="mb-0">Немає записів руху</p></div>
            <?php endif; ?>
        </div>
    </div>
    <?php
    include __DIR__ . '/../includes/footer.php';
    exit;
}

if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST' && isManager()) {
    $name = trim($_POST['name'] ?? '');
    $model = trim($_POST['model'] ?? '');
    if ($name) {
        $maxId = $pdo->query("SELECT COALESCE(MAX(product_id), 0) FROM erp_products")->fetchColumn();
        $newId = $maxId + 1;
        $stmt = $pdo->prepare("INSERT INTO erp_products (product_id, name, model, price_wholesale, price_semi_wholesale, price_retail, price_purchase, quantity, status) VALUES (?, ?, ?, 0, 0, 0, 0, 0, 1)");
        $stmt->execute([$newId, $name, $model]);
        flashMessage('success', 'Товар "' . escape($name) . '" створено (ID: ' . $newId . ')');
    } else {
        flashMessage('error', 'Введіть назву товару');
    }
    redirect(BASE_URL . '/modules/stock.php');
}

if ($action === 'adjust' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isManager()) { flashMessage('error', 'Недостатньо прав'); redirect(BASE_URL . '/modules/stock.php'); }
    $pid = (int)($_POST['product_id'] ?? 0);
    $qty = (float)($_POST['quantity'] ?? 0);
    $costPrice = (float)($_POST['cost_price'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    $user = getUserData();
    if ($pid && $qty != 0) {
        $stmt = $pdo->prepare("INSERT INTO erp_stock_moves (product_id, type, quantity, cost_price, notes, user_id) VALUES (?, 'adjustment', ?, ?, ?, ?)");
        $stmt->execute([$pid, $qty, $costPrice ?: null, $notes, $user['user_id']]);
        flashMessage('success', 'Корекцію застосовано');
    } else {
        flashMessage('error', 'Некоректні дані');
    }
    redirect(BASE_URL . '/modules/stock.php?action=corrections');
}

if ($action === 'edit_adjust' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isManager()) { flashMessage('error', 'Недостатньо прав'); redirect(BASE_URL . '/modules/stock.php'); }
    $moveId = (int)($_POST['move_id'] ?? 0);
    $qty = (float)($_POST['quantity'] ?? 0);
    $costPrice = (float)($_POST['cost_price'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    if ($moveId && $qty != 0) {
        $stmt = $pdo->prepare("UPDATE erp_stock_moves SET quantity = ?, cost_price = ?, notes = ? WHERE move_id = ? AND type = 'adjustment'");
        $stmt->execute([$qty, $costPrice ?: null, $notes, $moveId]);
        flashMessage('success', 'Корекцію оновлено');
    } else {
        flashMessage('error', 'Некоректні дані');
    }
    redirect(BASE_URL . '/modules/stock.php?action=corrections');
}

if ($action === 'delete_adjust' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isManager()) { flashMessage('error', 'Недостатньо прав'); redirect(BASE_URL . '/modules/stock.php'); }
    $moveId = (int)($_POST['move_id'] ?? 0);
    if ($moveId) {
        $stmt = $pdo->prepare("DELETE FROM erp_stock_moves WHERE move_id = ? AND type = 'adjustment'");
        $stmt->execute([$moveId]);
        flashMessage('success', 'Корекцію видалено');
    }
    redirect(BASE_URL . '/modules/stock.php?action=corrections');
}

if ($action === 'edit_product' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isManager()) { flashMessage('error', 'Недостатньо прав'); redirect(BASE_URL . '/modules/stock.php'); }
    $pid = (int)($_POST['product_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $model = trim($_POST['model'] ?? '');
    $sku = trim($_POST['sku'] ?? '');
    $priceWholesale = (float)($_POST['price_wholesale'] ?? 0);
    $priceSemi = (float)($_POST['price_semi_wholesale'] ?? 0);
    $priceRetail = (float)($_POST['price_retail'] ?? 0);
    $pricePurchase = (float)($_POST['price_purchase'] ?? 0);
    $catIds = array_map('intval', $_POST['category_ids'] ?? []);
    if ($pid && $name) {
        $stmt = $pdo->prepare("UPDATE erp_products SET name = ?, model = ?, sku = ?, price_wholesale = ?, price_semi_wholesale = ?, price_retail = ?, price_purchase = ? WHERE product_id = ?");
        $stmt->execute([$name, $model, $sku, $priceWholesale, $priceSemi, $priceRetail, $pricePurchase, $pid]);
        $pdo->prepare("DELETE FROM erp_product_categories WHERE product_id = ?")->execute([$pid]);
        $insertStmt = $pdo->prepare("INSERT INTO erp_product_categories (product_id, category_id) VALUES (?, ?)");
        foreach ($catIds as $cid) {
            if ($cid > 0) $insertStmt->execute([$pid, $cid]);
        }
        flashMessage('success', 'Товар "' . escape($name) . '" оновлено');
    } else {
        flashMessage('error', 'Назва товару обов\'язкова');
    }
    redirect(BASE_URL . '/modules/stock.php');
}

if ($action === 'save_defaults' && $_SERVER['REQUEST_METHOD'] === 'POST' && isAdmin()) {
    $stmt = $pdo->prepare("UPDATE erp_settings SET value = ? WHERE `key` = ?");
    $stmt->execute([(float)$_POST['default_markup_wholesale'], 'default_markup_wholesale']);
    $stmt->execute([(float)$_POST['default_markup_semi_wholesale'], 'default_markup_semi_wholesale']);
    $stmt->execute([(float)$_POST['default_markup_retail'], 'default_markup_retail']);
    flashMessage('success', 'Налаштування цін збережено');
    redirect(BASE_URL . '/modules/stock.php?action=pricing');
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
    redirect(BASE_URL . '/modules/stock.php?action=pricing');
}

if ($action === 'save_category' && $_SERVER['REQUEST_METHOD'] === 'POST' && isAdmin()) {
    $name = trim($_POST['name'] ?? '');
    $catId = (int)($_POST['category_id'] ?? 0);
    $sortOrder = (int)($_POST['sort_order'] ?? 0);
    if ($name) {
        if ($catId) {
            $stmt = $pdo->prepare("UPDATE erp_categories SET name = ?, sort_order = ? WHERE category_id = ?");
            $stmt->execute([$name, $sortOrder, $catId]);
            flashMessage('success', 'Категорію оновлено');
        } else {
            $stmt = $pdo->prepare("INSERT INTO erp_categories (name, sort_order) VALUES (?, ?)");
            $stmt->execute([$name, $sortOrder]);
            flashMessage('success', 'Категорію створено');
        }
    }
    redirect(BASE_URL . '/modules/stock.php?action=categories');
}

if ($action === 'delete_category' && $_SERVER['REQUEST_METHOD'] === 'POST' && isAdmin()) {
    $catId = (int)($_POST['category_id'] ?? 0);
    if ($catId) {
        $pdo->prepare("DELETE FROM erp_product_categories WHERE category_id = ?")->execute([$catId]);
        $pdo->prepare("DELETE FROM erp_categories WHERE category_id = ?")->execute([$catId]);
        flashMessage('success', 'Категорію видалено');
    }
    redirect(BASE_URL . '/modules/stock.php?action=categories');
}

if ($action === 'save_product_categories' && $_SERVER['REQUEST_METHOD'] === 'POST' && isManager()) {
    $pid = (int)($_POST['product_id'] ?? 0);
    $catIds = array_map('intval', $_POST['category_ids'] ?? []);
    if ($pid) {
        $pdo->prepare("DELETE FROM erp_product_categories WHERE product_id = ?")->execute([$pid]);
        $insertStmt = $pdo->prepare("INSERT INTO erp_product_categories (product_id, category_id) VALUES (?, ?)");
        foreach ($catIds as $cid) {
            if ($cid > 0) $insertStmt->execute([$pid, $cid]);
        }
        flashMessage('success', 'Категорії товару збережено');
    }
    redirect(BASE_URL . '/modules/stock.php');
}

if ($action === 'categories') {
    $categories = getCategories($pdo);
    include __DIR__ . '/../includes/header.php';
    ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0"><i class="bi bi-tags"></i> Категорії товарів</h4>
        <div>
            <a href="<?php echo BASE_URL; ?>/modules/stock.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> На склад</a>
            <?php if (isAdmin()): ?>
            <button class="btn btn-sm btn-outline-info" id="syncCatsBtn" onclick="syncCategories()"><i class="bi bi-arrow-repeat"></i> Синхр. OC</button>
            <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#categoryModal" onclick="openCategoryModal(0, '', 0)"><i class="bi bi-plus-lg"></i> Нова категорія</button>
            <?php endif; ?>
        </div>
    </div>
    <div class="card">
        <div class="card-body p-0">
            <?php if (count($categories) > 0): ?>
            <div class="table-container">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Назва</th>
                            <th>Порядок</th>
                            <th class="text-center">Товарів</th>
                            <th class="text-center">Дії</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($categories as $c):
                            $cnt = $pdo->prepare("SELECT COUNT(*) FROM erp_product_categories WHERE category_id = ?");
                            $cnt->execute([$c['category_id']]);
                            $productCount = $cnt->fetchColumn();
                        ?>
                        <tr>
                            <td><?php echo (int)$c['category_id']; ?></td>
                            <td><?php echo escape($c['name']); ?></td>
                            <td><?php echo (int)$c['sort_order']; ?></td>
                            <td class="text-center"><?php echo $productCount; ?></td>
                            <td class="text-center">
                                <button class="btn btn-sm btn-outline-primary" onclick="openCategoryModal(<?php echo (int)$c['category_id']; ?>, '<?php echo escape($c['name'], "'"); ?>', <?php echo (int)$c['sort_order']; ?>)"><i class="bi bi-pencil"></i></button>
                                <form method="post" action="?action=delete_category" class="d-inline" onsubmit="return confirm('Видалити категорію «<?php echo escape($c['name']); ?>»?')">
                                    <input type="hidden" name="category_id" value="<?php echo (int)$c['category_id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="text-center py-5 text-muted">
                <i class="bi bi-tags" style="font-size:3rem;"></i>
                <p class="mt-3 mb-0">Немає категорій. Створіть першу категорію!</p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="modal fade" id="categoryModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post" action="?action=save_category">
                    <div class="modal-header">
                        <h5 class="modal-title" id="categoryModalTitle">Нова категорія</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="category_id" id="catId">
                        <div class="mb-3">
                            <label class="form-label required">Назва</label>
                            <input type="text" name="name" id="catName" class="form-control" required maxlength="128">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Порядок сортування</label>
                            <input type="number" name="sort_order" id="catSort" class="form-control" min="0" value="0">
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

    <script>
    function openCategoryModal(id, name, sort) {
        document.getElementById('catId').value = id;
        document.getElementById('catName').value = name;
        document.getElementById('catSort').value = sort;
        document.getElementById('categoryModalTitle').textContent = id ? 'Редагувати категорію' : 'Нова категорія';
        new bootstrap.Modal(document.getElementById('categoryModal')).show();
    }

    function syncCategories() {
        var btn = document.getElementById('syncCatsBtn');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Синхр...';
        fetch('<?php echo BASE_URL; ?>/api/sync-categories.php')
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (d.error) {
                    alert('Помилка: ' + d.error);
                } else {
                    alert('OK! Синхронізовано ' + d.synced + ' категорій');
                    location.reload();
                }
            })
            .catch(function(e) {
                alert('Помилка: ' + e.message);
            })
            .finally(function() {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-arrow-repeat"></i> Синхр. OC';
            });
    }
    </script>
    <?php include __DIR__ . '/../includes/footer.php'; ?>
    <?php exit;
}

if ($action === 'pricing') {
    $markups = getDefaultMarkups($pdo);
    $rates = getCurrentRates($pdo);

    $pricingSearch = trim($_GET['search'] ?? '');
    $pricingCategoryId = (int)($_GET['category_id'] ?? 0);
    $pricingPage = max(1, (int)($_GET['pricing_page'] ?? 1));
    $pricingPerPage = 30;

    $where = '';
    $params = [];
    $joinCategory = '';
    if ($pricingCategoryId) {
        $joinCategory = "INNER JOIN erp_product_categories pc ON p.product_id = pc.product_id AND pc.category_id = ?";
    }
    if ($pricingSearch) {
        $where = "WHERE (p.name LIKE ? OR p.model LIKE ? OR p.sku LIKE ? OR CAST(p.product_id AS CHAR) LIKE ?)";
        $s = "%$pricingSearch%";
        $params = [$s, $s, $s, $s];
    }

    $countSql = "SELECT COUNT(DISTINCT p.product_id) FROM erp_products p $joinCategory $where";
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($pricingCategoryId ? array_merge([$pricingCategoryId], $params) : $params);
    $pricingTotal = $stmt->fetchColumn();
    $pricingPagination = paginate($pricingTotal, $pricingPerPage, $pricingPage);

    $pricingSql = "SELECT p.*, pr.markup_wholesale, pr.markup_semi_wholesale, pr.markup_retail, pr.use_custom, pr.custom_price_wholesale, pr.custom_price_semi_wholesale, pr.custom_price_retail,
        COALESCE(AVG(CASE WHEN sm.type IN ('in','return_in') THEN sm.cost_price ELSE NULL END), 0) as avg_cost,
        COALESCE(SUM(CASE WHEN sm.type IN ('in','return_in') THEN sm.quantity ELSE 0 END) - SUM(CASE WHEN sm.type IN ('out','return_out') THEN sm.quantity ELSE 0 END), 0) as stock_qty,
        COALESCE((SELECT currency FROM erp_incoming_invoices ii JOIN erp_invoice_items iit ON ii.invoice_id = iit.invoice_id WHERE iit.product_id = p.product_id ORDER BY ii.date_added DESC LIMIT 1), 'EUR') as purchase_currency
        FROM erp_products p
        LEFT JOIN erp_pricing_rules pr ON p.product_id = pr.product_id
        LEFT JOIN erp_stock_moves sm ON p.product_id = sm.product_id
        $joinCategory
        $where
        GROUP BY p.product_id
        ORDER BY p.name ASC
        LIMIT {$pricingPagination['per_page']} OFFSET {$pricingPagination['offset']}";
    $stmt = $pdo->prepare($pricingSql);
    $stmt->execute($pricingCategoryId ? array_merge([$pricingCategoryId], $params) : $params);
    $pricingProducts = $stmt->fetchAll();

    $pricingProductCategories = [];
    if (count($pricingProducts) > 0) {
        $ids = array_map(function($p) { return (int)$p['product_id']; }, $pricingProducts);
        $idPlaceholders = implode(',', $ids);
        $catStmt = $pdo->query("SELECT pc.product_id, c.name FROM erp_product_categories pc JOIN erp_categories c ON pc.category_id = c.category_id WHERE pc.product_id IN ($idPlaceholders) ORDER BY c.name");
        while ($row = $catStmt->fetch()) {
            $pricingProductCategories[$row['product_id']][] = $row['name'];
        }
    }

    include __DIR__ . '/../includes/header.php';
    ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0"><i class="bi bi-currency-exchange"></i> Ціноутворення</h4>
        <div class="d-flex gap-2">
            <a href="<?php echo BASE_URL; ?>/modules/stock.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-boxes"></i> На склад</a>
            <?php if (isAdmin()): ?>
            <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#defaultsModal"><i class="bi bi-gear"></i> Налаштування</button>
            <button class="btn btn-sm btn-success" id="pushPricesBtn" onclick="pushPrices()" title="Розрахувати ціни для всіх товарів і відправити на сайт"><i class="bi bi-send"></i> Застосувати курси → Сайт</button>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-3">
            <div class="card">
                <div class="card-body text-center">
                    <small class="text-muted">Націнка опт</small>
                    <div class="fw-bold fs-5"><?php echo $markups['default_markup_wholesale']; ?>%</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card">
                <div class="card-body text-center">
                    <small class="text-muted">Націнка дрібний опт</small>
                    <div class="fw-bold fs-5"><?php echo $markups['default_markup_semi_wholesale']; ?>%</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card">
                <div class="card-body text-center">
                    <small class="text-muted">Націнка роздріб</small>
                    <div class="fw-bold fs-5"><?php echo $markups['default_markup_retail']; ?>%</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card">
                <div class="card-body text-center">
                    <small class="text-muted">Курс USD / EUR</small>
                    <div class="fw-bold fs-6"><?php echo number_format($rates['USD'], 2, '.', ' '); ?> / <?php echo number_format($rates['EUR'], 2, '.', ' '); ?> ₴</div>
                </div>
            </div>
        </div>
    </div>

    <form method="get" class="mb-3 d-flex gap-2 align-items-center">
        <input type="hidden" name="action" value="pricing">
        <?php echo getCategoryFilter($pdo, $pricingCategoryId); ?>
        <div class="input-group" style="max-width:350px;">
            <input type="search" name="search" class="form-control" placeholder="Пошук товару..." value="<?php echo escape($pricingSearch); ?>">
            <button class="btn btn-outline-primary"><i class="bi bi-search"></i></button>
        </div>
    </form>

    <div class="card">
        <div class="card-header">Товари та ціни</div>
        <div class="card-body p-0">
            <?php if (count($pricingProducts) > 0): ?>
            <div class="table-container">
                <table class="table table-hover mb-0 table-product">
                    <thead>
                        <tr>
                            <th style="width:36px;"><input type="checkbox" id="selectAllPricing" onchange="toggleAllPricing(this.checked)"></th>
                            <th>Товар</th>
                            <th>Категорія</th>
                            <th class="text-center">Залишок</th>
                            <th class="text-center">Валюта</th>
                            <th class="text-end">Собівартість</th>
                            <th class="text-end">Собівартість (UAH)</th>
                            <th class="text-end">Опт</th>
                            <th class="text-end">Дріб. опт</th>
                            <th class="text-end">Роздріб</th>
                            <th class="text-center">Дії</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pricingProducts as $p): ?>
                        <?php
                            $stockQty = (float)$p['stock_qty'];
                            $pCats = $pricingProductCategories[$p['product_id']] ?? [];
                            $purchaseCur = $p['purchase_currency'] ?: 'EUR';
                            $rate = $rates[$purchaseCur] ?? $rates['EUR'];
                            $costForeign = (float)$p['avg_cost'];
                            $costUah = $costForeign * $rate;
                            $mw = $p['markup_wholesale'] ?? $markups['default_markup_wholesale'];
                            $ms = $p['markup_semi_wholesale'] ?? $markups['default_markup_semi_wholesale'];
                            $mr = $p['markup_retail'] ?? $markups['default_markup_retail'];
                            $pw = $p['use_custom'] ? $p['custom_price_wholesale'] : calculatePrice($costUah, $mw);
                            $ps = $p['use_custom'] ? $p['custom_price_semi_wholesale'] : calculatePrice($costUah, $ms);
                            $pr = $p['use_custom'] ? $p['custom_price_retail'] : calculatePrice($costUah, $mr);
                            $noStock = $stockQty <= 0;
                        ?>
                        <tr class="<?php echo $noStock ? 'table-warning' : ''; ?>">
                            <td><input type="checkbox" class="pricing-checkbox" value="<?php echo (int)$p['product_id']; ?>"></td>
                            <td>
                                <?php echo escape($p['name'] ?: 'ID: ' . $p['product_id']); ?>
                                <?php if ($noStock): ?>
                                <span class="badge bg-warning text-dark">Немає в наявності</span>
                                <?php endif; ?>
                            </td>
                            <td class="td-cat"><span class="badge bg-secondary bg-opacity-25 text-dark"><?php echo $pCats ? escape(implode(', ', $pCats)) : '-'; ?></span></td>
                            <td class="text-center fw-bold"><?php echo (int)$stockQty; ?></td>
                            <td class="text-center"><?php echo $purchaseCur; ?></td>
                            <td class="text-end"><?php echo $costForeign > 0 ? formatMoneyForeign($costForeign, $purchaseCur) : '-'; ?></td>
                            <td class="text-end"><?php echo $costUah > 0 ? formatMoney($costUah) : '-'; ?></td>
                            <td class="text-end fw-bold"><?php echo formatMoney($pw); ?></td>
                            <td class="text-end fw-bold"><?php echo formatMoney($ps); ?></td>
                            <td class="text-end fw-bold"><?php echo formatMoney($pr); ?></td>
                            <td class="text-center">
                                <button class="btn btn-sm btn-outline-primary edit-pricing"
                                    data-product-id="<?php echo $p['product_id']; ?>"
                                    data-name="<?php echo escape($p['name'] ?: 'ID: ' . $p['product_id']); ?>"
                                    data-mw="<?php echo $mw; ?>"
                                    data-ms="<?php echo $ms; ?>"
                                    data-mr="<?php echo $mr; ?>"
                                    data-use-custom="<?php echo (int)$p['use_custom']; ?>"
                                    data-cpw="<?php echo (float)$p['custom_price_wholesale']; ?>"
                                    data-cps="<?php echo (float)$p['custom_price_semi_wholesale']; ?>"
                                    data-cpr="<?php echo (float)$p['custom_price_retail']; ?>"
                                    title="Налаштувати ціни">
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
                <p class="mt-3 mb-0"><?php echo $pricingSearch ? 'Нічого не знайдено' : 'Немає товарів'; ?></p>
            </div>
            <?php endif; ?>
        </div>
        <?php if ($pricingPagination['total_pages'] > 1): ?>
        <div class="card-footer"><?php echo renderPagination(BASE_URL . '/modules/stock.php?action=pricing', $pricingPagination, 'pricing_page'); ?></div>
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

    <style>
    .table-product th, .table-product td { white-space: nowrap; padding: 0.4rem 0.5rem; }
    .table-product .cell-text { white-space: normal; overflow: hidden; text-overflow: ellipsis; }
    .table-product .td-cat { max-width:130px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    </style>
    <script>
    function toggleAllPricing(checked) {
        document.querySelectorAll('.pricing-checkbox').forEach(function(cb) {
            cb.checked = checked;
        });
    }
    function pushPrices() {
        var btn = document.getElementById('pushPricesBtn');
        var checked = document.querySelectorAll('.pricing-checkbox:checked');
        var url = '<?php echo BASE_URL; ?>/api/push-prices.php';
        var options = { method: 'POST', headers: { 'Content-Type': 'application/json' } };
        if (checked.length > 0) {
            var ids = Array.from(checked).map(function(cb) { return parseInt(cb.value); });
            options.body = JSON.stringify({ product_ids: ids });
            url += '?selected=1';
        }
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>...';
        fetch(url, options)
            .then(function(r) { return r.json(); })
            .then(function(d) {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-send"></i> Застосувати курси → Сайт';
                if (d.error) {
                    alert('Помилка: ' + d.error);
                } else {
                    var msg = '✅ Оновлено в ERP: ' + d.erp_updated + ' товарів';
                    msg += '\n✅ Відправлено на сайт: ' + d.updated + ' товарів';
                    if (d.errors && d.errors.length) msg += '\n⚠️ Помилки: ' + d.errors.slice(0, 3).join(', ');
                    msg += '\n\nКурси: USD ' + d.rates_used.USD + ', EUR ' + d.rates_used.EUR;
                    alert(msg);
                }
            })
            .catch(function(e) {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-send"></i> Застосувати курси → Сайт';
                alert('Помилка запиту: ' + e.message);
            });
    }

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
    <?php exit;
}

if ($action === 'corrections') {
    $corrPage = max(1, (int)($_GET['corr_page'] ?? 1));
    $corrPerPage = 50;
    $corrTotal = $pdo->query("SELECT COUNT(*) FROM erp_stock_moves WHERE type = 'adjustment'")->fetchColumn();
    $corrPagination = paginate($corrTotal, $corrPerPage, $corrPage);
    $corrections = $pdo->prepare("
        SELECT m.*, p.name as product_name
        FROM erp_stock_moves m
        LEFT JOIN erp_products p ON m.product_id = p.product_id
        WHERE m.type = 'adjustment'
        ORDER BY m.date_added DESC
        LIMIT ? OFFSET ?
    ");
    $corrections->bindValue(1, $corrPagination['per_page'], PDO::PARAM_INT);
    $corrections->bindValue(2, $corrPagination['offset'], PDO::PARAM_INT);
    $corrections->execute();
    $corrections = $corrections->fetchAll();

    $products = $pdo->query("SELECT product_id, name, model FROM erp_products ORDER BY name ASC")->fetchAll();

    include __DIR__ . '/../includes/header.php';
    ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0"><i class="bi bi-sliders"></i> Корекції залишків</h4>
        <div>
            <a href="<?php echo BASE_URL; ?>/modules/stock.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> На склад</a>
            <button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#adjustModal"><i class="bi bi-plus-lg"></i> Нова корекція</button>
        </div>
    </div>
    <div class="card">
        <div class="card-header">Історія корекцій</div>
        <div class="card-body p-0">
            <?php if (count($corrections) > 0): ?>
            <div class="table-container">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Товар</th>
                            <th>Кількість</th>
                            <th>Собівартість</th>
                            <th>Примітки</th>
                            <th>Дата</th>
                            <th class="text-center">Дії</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($corrections as $c): ?>
                        <tr>
                            <td><?php echo (int)$c['move_id']; ?></td>
                            <td>
                                <a href="<?php echo BASE_URL; ?>/modules/stock.php?action=moves&id=<?php echo (int)$c['product_id']; ?>" class="text-decoration-none">
                                    <?php echo escape($c['product_name'] ?: 'ID: ' . $c['product_id']); ?>
                                </a>
                            </td>
                            <td class="fw-bold <?php echo $c['quantity'] > 0 ? 'text-success' : 'text-danger'; ?>"><?php echo (float)$c['quantity'] > 0 ? '+' . (float)$c['quantity'] : (float)$c['quantity']; ?></td>
                            <td><?php echo $c['cost_price'] ? formatMoneyForeign($c['cost_price']) : '-'; ?></td>
                            <td><?php echo escape($c['notes'] ?: '-'); ?></td>
                            <td><?php echo formatDate($c['date_added']); ?></td>
                            <td class="text-center">
                                <button class="btn btn-sm btn-outline-primary" onclick="editCorrection(<?php echo htmlspecialchars(json_encode($c)); ?>)" title="Редагувати">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <form method="post" action="?action=delete_adjust" class="d-inline" onsubmit="return confirm('Видалити корекцію #<?php echo (int)$c['move_id']; ?>?')">
                                    <input type="hidden" name="move_id" value="<?php echo (int)$c['move_id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Видалити"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="text-center py-4 text-muted"><p class="mb-0">Немає корекцій</p></div>
            <?php endif; ?>
        </div>
        <?php if ($corrPagination['total_pages'] > 1): ?>
        <div class="card-footer">
            <?php echo renderPagination(BASE_URL . '/modules/stock.php?action=corrections', $corrPagination, 'corr_page'); ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="modal fade" id="editCorrectionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post" action="?action=edit_adjust">
                    <div class="modal-header">
                        <h5 class="modal-title">Редагувати корекцію</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="move_id" id="edit_move_id">
                        <div class="mb-3">
                            <label class="form-label">ID товару</label>
                            <input type="number" name="product_id" id="edit_product_id" class="form-control" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label required">Кількість (+, -)</label>
                            <input type="number" name="quantity" id="edit_quantity" class="form-control" step="0.01" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Собівартість (валюта)</label>
                            <input type="number" name="cost_price" id="edit_cost_price" class="form-control" step="0.01" min="0" placeholder="0.00">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Примітка</label>
                            <textarea name="notes" id="edit_notes" class="form-control" rows="2"></textarea>
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

<div class="modal fade" id="editProductModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" action="?action=edit_product">
                <div class="modal-header">
                    <h5 class="modal-title">Редагувати товар</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="product_id" id="edit_product_id">
                    <div class="mb-3">
                        <label class="form-label required">Назва</label>
                        <input type="text" name="name" id="edit_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Модель</label>
                        <input type="text" name="model" id="edit_model" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">SKU</label>
                        <input type="text" name="sku" id="edit_sku" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Категорії</label>
                        <div id="edit_categories" class="d-flex flex-wrap gap-2" style="max-height:160px;overflow-y:auto;">
                            <?php $allCats = getCategories($pdo); foreach ($allCats as $cat): ?>
                            <label class="form-check form-check-inline mb-1">
                                <input type="checkbox" name="category_ids[]" value="<?php echo (int)$cat['category_id']; ?>" class="form-check-input">
                                <span class="form-check-label small"><?php echo escape($cat['name']); ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Опт</label>
                            <input type="number" name="price_wholesale" id="edit_price_wholesale" class="form-control" step="0.01" min="0">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Дрібний опт</label>
                            <input type="number" name="price_semi_wholesale" id="edit_price_semi" class="form-control" step="0.01" min="0">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Роздріб</label>
                            <input type="number" name="price_retail" id="edit_price_retail" class="form-control" step="0.01" min="0">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Закупівля</label>
                        <input type="number" name="price_purchase" id="edit_price_purchase" class="form-control" step="0.01" min="0">
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

<?php include __DIR__ . '/../includes/footer.php'; ?>
    <?php exit;
}

$categoryId = (int)($_GET['category_id'] ?? 0);
$joinCategory = '';
if ($categoryId) {
    $joinCategory = "INNER JOIN erp_product_categories pc ON p.product_id = pc.product_id AND pc.category_id = ?";
}
$where = '';
$params = [];
if ($search) {
    $where = "WHERE (p.name LIKE ? OR p.model LIKE ? OR p.sku LIKE ? OR CAST(p.product_id AS CHAR) LIKE ?)";
    $s = "%$search%";
    $params = [$s, $s, $s, $s];
}

$countSql = "SELECT COUNT(DISTINCT p.product_id) FROM erp_products p $joinCategory $where";
$stmt = $pdo->prepare($countSql);
$stmt->execute($categoryId ? array_merge([$categoryId], $params) : $params);
$total = $stmt->fetchColumn();

$pagination = paginate($total, $perPage, $page);
$sql = "SELECT p.*,
    COALESCE(SUM(CASE WHEN sm.type IN ('in','return_in') THEN sm.quantity ELSE 0 END) - SUM(CASE WHEN sm.type IN ('out','return_out') THEN sm.quantity ELSE 0 END), 0) as stock_qty
    FROM erp_products p
    LEFT JOIN erp_stock_moves sm ON p.product_id = sm.product_id
    $joinCategory
    $where
    GROUP BY p.product_id
    ORDER BY p.name ASC
    LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}";
$stmt = $pdo->prepare($sql);
$stmt->execute($categoryId ? array_merge([$categoryId], $params) : $params);
$products = $stmt->fetchAll();

// Load categories for all displayed products
$productCategories = [];
$productCategoryIds = [];
if (count($products) > 0) {
    $ids = array_map(function($p) { return (int)$p['product_id']; }, $products);
    $idPlaceholders = implode(',', $ids);
    $catStmt = $pdo->query("SELECT pc.product_id, pc.category_id, c.name FROM erp_product_categories pc JOIN erp_categories c ON pc.category_id = c.category_id WHERE pc.product_id IN ($idPlaceholders) ORDER BY c.name");
    while ($row = $catStmt->fetch()) {
        $productCategories[$row['product_id']][] = $row['name'];
        $productCategoryIds[$row['product_id']][] = (int)$row['category_id'];
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="bi bi-boxes"></i> Склад</h4>
    <div class="d-flex gap-2">
        <form method="get" class="d-flex align-items-center gap-2">
            <?php echo getCategoryFilter($pdo, $categoryId); ?>
            <input type="search" name="search" class="form-control form-control-sm search-box" placeholder="Пошук товару..." value="<?php echo escape($search); ?>">
            <button class="btn btn-sm btn-outline-primary"><i class="bi bi-search"></i></button>
        </form>
        <a href="<?php echo BASE_URL; ?>/modules/stock.php?action=categories" class="btn btn-sm btn-outline-secondary"><i class="bi bi-tags"></i> Категорії</a>
        <?php if (isManager()): ?>
        <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#createProductModal"><i class="bi bi-plus-lg"></i> Додати товар</button>
        <?php endif; ?>
        <?php if (isAdmin()): ?>
        <button class="btn btn-sm btn-outline-info" id="syncBtn" onclick="syncProducts()"><i class="bi bi-arrow-repeat"></i> Синхр. OC</button>
        <button class="btn btn-sm btn-outline-info" onclick="document.getElementById('syncOneWrap').classList.toggle('d-none')"><i class="bi bi-search"></i> OC по ID</button>
        <?php endif; ?>
    </div>
</div>

<div class="d-none mb-3" id="syncOneWrap">
    <div class="input-group" style="max-width:400px;">
        <span class="input-group-text">API ID</span>
        <input type="text" id="syncOneId" class="form-control" placeholder="Введіть ID товару" inputmode="numeric">
        <button class="btn btn-outline-info" onclick="syncOneProduct()">Синхронізувати</button>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <ul class="nav nav-tabs card-header-tabs">
            <li class="nav-item"><a class="nav-link <?php echo $action === 'list' ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>/modules/stock.php"><i class="bi bi-box"></i> Товари</a></li>
            <li class="nav-item"><a class="nav-link <?php echo $action === 'corrections' ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>/modules/stock.php?action=corrections"><i class="bi bi-sliders"></i> Корекція</a></li>
            <li class="nav-item"><a class="nav-link <?php echo $action === 'pricing' ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>/modules/stock.php?action=pricing"><i class="bi bi-currency-exchange"></i> Ціни</a></li>
        </ul>
    </div>
    <div class="card-body p-0">
        <?php if (count($products) > 0): ?>
        <div class="table-container">
            <table class="table table-hover mb-0 table-product">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Назва</th>
                        <th>Модель</th>
                        <th>SKU</th>
                        <th>Категорія</th>
                        <th class="text-center">На складі</th>
                        <th class="text-end">Опт</th>
                        <th class="text-end">Дрібний опт</th>
                        <th class="text-end">Роздріб</th>
                        <th class="text-end">Закупівля</th>
                        <th class="text-center">Дії</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $p): ?>
                    <?php $stockQty = (float)$p['stock_qty']; ?>
                    <?php $cats = $productCategories[$p['product_id']] ?? []; ?>
                    <tr class="<?php echo $stockQty <= 0 ? 'table-danger' : ($stockQty <= 5 ? 'table-warning' : ''); ?>">
                        <td><?php echo (int)$p['product_id']; ?></td>
                        <td class="text-truncate" style="max-width:180px;">
                            <a href="<?php echo BASE_URL; ?>/modules/stock.php?action=moves&id=<?php echo (int)$p['product_id']; ?>" class="text-decoration-none">
                                <?php echo escape($p['name'] ?: '—'); ?>
                            </a>
                        </td>
                        <td><?php echo escape($p['model'] ?: '-'); ?></td>
                        <td><?php echo escape($p['sku'] ?: '-'); ?></td>
                        <td class="td-cat"><span class="badge bg-secondary bg-opacity-25 text-dark"><?php echo $cats ? escape(implode(', ', $cats)) : '-'; ?></span></td>
                        <td class="text-center fw-bold"><?php echo (int)$stockQty; ?></td>
                        <td class="text-end"><?php echo $p['price_wholesale'] ? formatMoney($p['price_wholesale']) : '-'; ?></td>
                        <td class="text-end"><?php echo $p['price_semi_wholesale'] ? formatMoney($p['price_semi_wholesale']) : '-'; ?></td>
                        <td class="text-end"><?php echo $p['price_retail'] ? formatMoney($p['price_retail']) : '-'; ?></td>
                        <td class="text-end"><?php echo $p['price_purchase'] ? formatMoney($p['price_purchase']) : '-'; ?></td>
                        <td class="text-center">
                            <button class="btn btn-sm btn-outline-primary" onclick='editProduct(<?php echo json_encode(array_merge($p, ['category_ids' => $productCategoryIds[$p['product_id']] ?? []]), JSON_UNESCAPED_UNICODE); ?>)' title="Редагувати товар">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <a href="<?php echo BASE_URL; ?>/modules/stock.php?action=moves&id=<?php echo (int)$p['product_id']; ?>" class="btn btn-sm btn-outline-info" title="Рух товару">
                                <i class="bi bi-arrow-left-right"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="text-center py-5 text-muted">
            <i class="bi bi-inbox" style="font-size:3rem;"></i>
            <p class="mt-3 mb-0"><?php echo $search ? 'Нічого не знайдено' : 'Склад порожній'; ?></p>
        </div>
        <?php endif; ?>
    </div>
    <?php if ($pagination['total_pages'] > 1): ?>
    <div class="card-footer">
        <?php echo renderPagination(BASE_URL . '/modules/stock.php?', $pagination); ?>
    </div>
    <?php endif; ?>
</div>

<div class="modal fade" id="adjustModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" action="?action=adjust">
                <div class="modal-header">
                    <h5 class="modal-title">Нова корекція залишків</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3 position-relative">
                        <label class="form-label required">Товар</label>
                        <input type="text" id="adjustProductSearch" class="form-control" placeholder="Почніть вводити назву товару..." autocomplete="off" required>
                        <input type="hidden" name="product_id" id="adjustProductId">
                        <div id="adjustProductDropdown" class="dropdown-menu w-100" style="max-height:300px;overflow-y:auto;"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label required">Кількість (+, -)</label>
                        <input type="number" name="quantity" class="form-control" step="0.01" required placeholder="10 або -5">
                        <div class="form-text">Додатнє число для оприбуткування, від'ємне для списання</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Собівартість (валюта)</label>
                        <input type="number" name="cost_price" class="form-control" step="0.01" min="0" placeholder="0.00">
                        <div class="form-text">Вкажіть собівартість у валюті постачальника (необов'язково)</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Примітка</label>
                        <textarea name="notes" class="form-control" rows="2"></textarea>
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

<div class="modal fade" id="editProductModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" action="?action=edit_product">
                <div class="modal-header">
                    <h5 class="modal-title">Редагувати товар</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="product_id" id="edit_product_id">
                    <div class="mb-3">
                        <label class="form-label required">Назва</label>
                        <input type="text" name="name" id="edit_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Модель</label>
                        <input type="text" name="model" id="edit_model" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">SKU</label>
                        <input type="text" name="sku" id="edit_sku" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Категорії</label>
                        <div id="edit_categories" class="d-flex flex-wrap gap-2" style="max-height:160px;overflow-y:auto;">
                            <?php $allCats = getCategories($pdo); foreach ($allCats as $cat): ?>
                            <label class="form-check form-check-inline mb-1">
                                <input type="checkbox" name="category_ids[]" value="<?php echo (int)$cat['category_id']; ?>" class="form-check-input">
                                <span class="form-check-label small"><?php echo escape($cat['name']); ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Опт</label>
                            <input type="number" name="price_wholesale" id="edit_price_wholesale" class="form-control" step="0.01" min="0">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Дрібний опт</label>
                            <input type="number" name="price_semi_wholesale" id="edit_price_semi" class="form-control" step="0.01" min="0">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Роздріб</label>
                            <input type="number" name="price_retail" id="edit_price_retail" class="form-control" step="0.01" min="0">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Закупівля</label>
                        <input type="number" name="price_purchase" id="edit_price_purchase" class="form-control" step="0.01" min="0">
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

<div class="modal fade" id="createProductModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" action="?action=create">
                <div class="modal-header">
                    <h5 class="modal-title">Новий товар</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Назва *</label>
                        <input type="text" name="name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Модель / Артикул</label>
                        <input type="text" name="model" class="form-control">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Скасувати</button>
                    <button type="submit" class="btn btn-success">Створити</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
var adjustProducts = <?php
$allP = $pdo->query("SELECT product_id, name, model, sku FROM erp_products ORDER BY name ASC")->fetchAll();
echo json_encode(array_map(function($ap) {
    return ['id' => (int)$ap['product_id'], 'name' => $ap['name'] ?: 'ID ' . $ap['product_id'], 'model' => $ap['model'] ?? '', 'sku' => $ap['sku'] ?? ''];
}, $allP), JSON_UNESCAPED_UNICODE);
?>;

(function() {
    var input = document.getElementById('adjustProductSearch');
    var hidden = document.getElementById('adjustProductId');
    var dropdown = document.getElementById('adjustProductDropdown');
    if (!input || !hidden || !dropdown) return;

    function filterProducts(q) {
        if (!q) { dropdown.classList.remove('show'); return; }
        var lower = q.toLowerCase();
        var matches = adjustProducts.filter(function(p) {
            return (p.name && p.name.toLowerCase().includes(lower)) ||
                   (p.model && p.model.toLowerCase().includes(lower)) ||
                   (p.sku && p.sku.toLowerCase().includes(lower));
        }).slice(0, 20);
        if (matches.length === 0) { dropdown.classList.remove('show'); return; }
        dropdown.innerHTML = matches.map(function(p) {
            var extra = p.model ? ' (' + p.model + ')' : '';
            return '<button class="dropdown-item" type="button" data-id="' + p.id + '">' + escapeHtml(p.name) + extra + '</button>';
        }).join('');
        dropdown.classList.add('show');
    }

    input.addEventListener('input', function() {
        if (this.value !== this._lastVal) {
            hidden.value = '';
            this._lastVal = this.value;
        }
        filterProducts(this.value);
    });

    input.addEventListener('blur', function() {
        setTimeout(function() { dropdown.classList.remove('show'); }, 200);
    });

    input.addEventListener('focus', function() {
        if (this.value) filterProducts(this.value);
    });

    dropdown.addEventListener('click', function(e) {
        var btn = e.target.closest('.dropdown-item');
        if (!btn) return;
        hidden.value = btn.dataset.id;
        input.value = btn.textContent;
        input._lastVal = btn.textContent;
        dropdown.classList.remove('show');
    });
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
