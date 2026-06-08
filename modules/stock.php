<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../_helpers.php';
requireLogin();

$action = $_GET['action'] ?? 'list';
$productId = (int)($_GET['id'] ?? 0);
$moveId = (int)($_GET['move_id'] ?? 0);
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
    $rates = getCurrentRates($pdo);
    $stmtCur = $pdo->prepare("SELECT currency FROM erp_incoming_invoices ii JOIN erp_invoice_items iit ON ii.invoice_id = iit.invoice_id WHERE iit.product_id = ? ORDER BY ii.date_added DESC LIMIT 1");
    $stmtCur->execute([$productId]);
    $purchaseCur = $stmtCur->fetchColumn() ?: 'EUR';
    $moveRate = $rates[$purchaseCur] ?? $rates['EUR'];
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
                                <th class="text-center">Дії</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($moves as $m): ?>
                            <tr>
                                <td><?php echo (int)$m['move_id']; ?></td>
                                <td><?php echo getStockTypeLabel($m['type']); ?></td>
                                <td class="fw-bold"><?php echo (float)$m['quantity']; ?></td>
                                <td><?php echo $m['cost_price'] ? formatMoney($m['cost_price'] * $moveRate) : '-'; ?></td>
                                <td><?php echo escape($m['notes'] ?: '-'); ?></td>
                                <td><?php echo formatDate($m['date_added']); ?></td>
                                <td class="text-center">
                                    <a href="<?php echo BASE_URL; ?>/modules/stock.php?action=delete_adjust&move_id=<?php echo (int)$m['move_id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Видалити рух #<?php echo (int)$m['move_id']; ?>?')" title="Видалити"><i class="bi bi-trash"></i></a>
                                </td>
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
    <div class="mt-2 text-muted small">Собівартість перераховано в UAH за курсом <?php echo $purchaseCur; ?> <?php echo number_format($moveRate, 2, '.', ' '); ?></div>
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
    $eMoveId = (int)($_POST['move_id'] ?? 0);
    $qty = (float)($_POST['quantity'] ?? 0);
    $costPrice = (float)($_POST['cost_price'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    if ($eMoveId && $qty != 0) {
        $stmt = $pdo->prepare("UPDATE erp_stock_moves SET quantity = ?, cost_price = ?, notes = ? WHERE move_id = ? AND type = 'adjustment'");
        $stmt->execute([$qty, $costPrice ?: null, $notes, $eMoveId]);
        flashMessage('success', 'Корекцію оновлено');
    } else {
        flashMessage('error', 'Некоректні дані');
    }
    redirect(BASE_URL . '/modules/stock.php?action=corrections');
}

if ($action === 'delete_adjust' && $moveId && isManager()) {
    $pdo->prepare("DELETE FROM erp_stock_moves WHERE move_id = ?")->execute([$moveId]);
    flashMessage('success', 'Рух видалено');
    redirect(BASE_URL . '/modules/stock.php?action=corrections');
}

if ($action === 'batch_delete_adjust' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isManager()) { flashMessage('error', 'Недостатньо прав'); redirect(BASE_URL . '/modules/stock.php'); }
    $ids = $_POST['move_ids'] ?? [];
    $ids = array_map('intval', $ids);
    $ids = array_filter($ids);
    if (count($ids) > 0) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("DELETE FROM erp_stock_moves WHERE move_id IN ($placeholders)");
        $stmt->execute(array_values($ids));
        flashMessage('success', 'Видалено рухів: ' . $stmt->rowCount());
    }
    redirect(BASE_URL . '/modules/stock.php?action=corrections');
}

if ($action === 'delete_product' && isManager()) {
    $pid = (int)($_GET['product_id'] ?? 0);
    if ($pid) {
        $pdo->prepare("DELETE FROM erp_product_categories WHERE product_id = ?")->execute([$pid]);
        $pdo->prepare("DELETE FROM erp_stock_moves WHERE product_id = ?")->execute([$pid]);
        $pdo->prepare("DELETE FROM erp_products WHERE product_id = ?")->execute([$pid]);
        flashMessage('success', 'Товар видалено');
    }
    redirect(BASE_URL . '/modules/stock.php');
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
    <div x-data="stockPage">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0"><i class="bi bi-tags"></i> Категорії товарів</h4>
        <div>
            <a href="<?php echo BASE_URL; ?>/modules/stock.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> На склад</a>
            <?php if (isAdmin()): ?>
            <button class="btn btn-sm btn-outline-info" @click="syncCategories()" :disabled="syncingCats">
                <span x-show="!syncingCats"><i class="bi bi-arrow-repeat"></i> Синхр. OC</span>
                <span x-show="syncingCats" x-cloak><span class="spinner-border spinner-border-sm"></span> Синхр...</span>
            </button>
            <button class="btn btn-sm btn-success" @click="openCategoryEdit(0, '', 0)"><i class="bi bi-plus-lg"></i> Нова категорія</button>
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
                                <button class="btn btn-sm btn-outline-primary" @click="openCategoryEdit(<?php echo (int)$c['category_id']; ?>, '<?php echo escape($c['name'], "'"); ?>', <?php echo (int)$c['sort_order']; ?>)"><i class="bi bi-pencil"></i></button>
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

    <div class="modal fade" id="categoryModal" tabindex="-1" x-ref="categoryModal">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post" action="?action=save_category">
                    <div class="modal-header">
                        <h5 class="modal-title" x-text="editCategory.id ? 'Редагувати категорію' : 'Нова категорія'">Нова категорія</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="category_id" :value="editCategory.id">
                        <div class="mb-3">
                            <label class="form-label required">Назва</label>
                            <input type="text" name="name" x-model="editCategory.name" class="form-control" required maxlength="128">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Порядок сортування</label>
                            <input type="number" name="sort_order" x-model.number="editCategory.sort_order" class="form-control" min="0">
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
    </div>
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
        COALESCE(AVG(CASE WHEN sm.type IN ('in','return_in','adjustment') THEN sm.cost_price ELSE NULL END), 0) as avg_cost,
        COALESCE(SUM(CASE WHEN sm.type IN ('in','return_in','adjustment') THEN sm.quantity ELSE 0 END) - SUM(CASE WHEN sm.type IN ('out','return_out') THEN sm.quantity ELSE 0 END), 0) as stock_qty,
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
    <div x-data="stockPage">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0"><i class="bi bi-currency-exchange"></i> Ціноутворення</h4>
        <div class="d-flex gap-2">
            <a href="<?php echo BASE_URL; ?>/modules/stock.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-boxes"></i> На склад</a>
            <?php if (isAdmin()): ?>
            <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#defaultsModal"><i class="bi bi-gear"></i> Налаштування</button>
            <button class="btn btn-sm btn-success" @click="pushPrices()" :disabled="pushingPrices" title="Розрахувати ціни для всіх товарів і відправити на сайт">
                <span x-show="!pushingPrices"><i class="bi bi-send"></i> Застосувати курси → Сайт</span>
                <span x-show="pushingPrices" x-cloak><span class="spinner-border spinner-border-sm"></span>...</span>
            </button>
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
                <table id="pricing-table" class="table table-hover mb-0 table-product">
                    <colgroup>
                        <col style="width:36px">
                        <col>
                        <col style="width:110px">
                        <col style="width:70px">
                        <col style="width:60px">
                        <col style="width:100px">
                        <col style="width:105px">
                        <col style="width:85px">
                        <col style="width:85px">
                        <col style="width:85px">
                        <col style="width:70px">
                    </colgroup>
                    <thead>
                        <tr>
                            <th style="width:36px"><input type="checkbox" id="selectAllPricing" :checked="selectAllPricing" @change="toggleAllPricing($event.target.checked)"></th>
                            <th>Товар</th>
                            <th style="width:110px">Категорія</th>
                            <th style="width:70px" class="text-center">Залишок</th>
                            <th style="width:60px" class="text-center">Валюта</th>
                            <th style="width:100px" class="text-end">Собівартість</th>
                            <th style="width:105px" class="text-end">Собівартість (UAH)</th>
                            <th style="width:85px" class="text-end">Опт</th>
                            <th style="width:85px" class="text-end">Дріб. опт</th>
                            <th style="width:85px" class="text-end">Роздріб</th>
                            <th style="width:70px" class="text-center">Дії</th>
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
                            <td><span class="cat-label"><?php echo $pCats ? escape(implode(', ', $pCats)) : '-'; ?></span></td>
                            <td class="text-center fw-bold"><?php echo (int)$stockQty; ?></td>
                            <td class="text-center"><?php echo $purchaseCur; ?></td>
                            <td class="text-end"><?php echo $costForeign > 0 ? formatMoneyForeign($costForeign, $purchaseCur) : '-'; ?></td>
                            <td class="text-end"><?php echo $costUah > 0 ? formatMoney($costUah) : '-'; ?></td>
                            <td class="text-end fw-bold"><?php echo formatMoney($pw); ?></td>
                            <td class="text-end fw-bold"><?php echo formatMoney($ps); ?></td>
                            <td class="text-end fw-bold"><?php echo formatMoney($pr); ?></td>
                            <td class="text-center col-actions">
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
                                    title="Налаштувати ціни"
                                    @click="openPricingEdit($event.currentTarget)">
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

    <div class="modal fade" id="pricingModal" tabindex="-1" x-ref="pricingModal">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post" action="?action=save_rule">
                    <div class="modal-header">
                        <h5 class="modal-title">Ціноутворення: <span x-text="editPricing.name"></span></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="product_id" :value="editPricing.product_id">
                        <div class="mb-3">
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" name="use_custom" id="useCustom" value="1" x-model="editPricing.use_custom">
                                <label class="form-check-label" for="useCustom">Використовувати власні ціни</label>
                            </div>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Націнка опт (%)</label>
                                <input type="number" name="markup_wholesale" x-model.number="editPricing.mw" class="form-control" step="0.01" min="0">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Націнка дріб. опт (%)</label>
                                <input type="number" name="markup_semi_wholesale" x-model.number="editPricing.ms" class="form-control" step="0.01" min="0">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Націнка роздріб (%)</label>
                                <input type="number" name="markup_retail" x-model.number="editPricing.mr" class="form-control" step="0.01" min="0">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Власна ціна опт (UAH)</label>
                                <input type="number" name="custom_price_wholesale" x-model.number="editPricing.cpw" class="form-control" step="0.01" min="0">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Власна ціна дріб. опт (UAH)</label>
                                <input type="number" name="custom_price_semi_wholesale" x-model.number="editPricing.cps" class="form-control" step="0.01" min="0">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Власна ціна роздріб (UAH)</label>
                                <input type="number" name="custom_price_retail" x-model.number="editPricing.cpr" class="form-control" step="0.01" min="0">
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
    .table-product { table-layout: auto; }
    .table.table-product > :not(caption) > * > th,
    .table.table-product > :not(caption) > * > td { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; padding: 0.4rem 0.5rem; }
    .table.table-product > :not(caption) > * > .col-actions { overflow: visible; }
    .table.table-product .cat-label { max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; display: inline-block; vertical-align: middle; background: rgba(108,117,125,.15); color: #212529; border-radius: .375rem; padding: 0 0.5em; font-size: .85em; line-height: 1.6; }
    .table.table-product td.name-cell { overflow: hidden; text-overflow: ellipsis; }
    .table.table-product td.name-cell a { overflow: hidden; text-overflow: ellipsis; max-width: 100%; display: inline-block; vertical-align: middle; }
    </style>
    </div>
    <?php include __DIR__ . '/../includes/footer.php'; ?>
    <?php exit;
}

if ($action === 'import_gsheet' && $_SERVER['REQUEST_METHOD'] === 'POST' && isManager()) {
    $result = ['matched' => 0, 'errors' => [], 'rows' => 0];
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $result['errors'][] = 'Помилка завантаження файлу';
    } else {
        $fh = fopen($_FILES['csv_file']['tmp_name'], 'r');
        if (!$fh) {
            $result['errors'][] = 'Не вдалося відкрити файл';
        } else {
            $bom = fread($fh, 3);
            if ($bom !== "\xEF\xBB\xBF") rewind($fh);
            $raw = stream_get_contents($fh);
            fclose($fh);
            if (strlen($raw) === 0) {
                $result['errors'][] = 'Порожній файл';
            } else {
                $lines = explode("\n", str_replace("\r\n", "\n", $raw));
                $delimiter = substr_count($lines[0], "\t") >= substr_count($lines[0], ',') ? "\t" : ',';
                $fh2 = fopen('php://temp', 'r+');
                fwrite($fh2, $raw);
                rewind($fh2);
                $headers = fgetcsv($fh2, 0, $delimiter);
                $subHeaders = fgetcsv($fh2, 0, $delimiter);
                if (!$headers) {
                    $result['errors'][] = 'Порожній файл';
                } else {
                    $headers = array_map('trim', $headers);
                    $hLower = [];
                    foreach ($headers as $i => $h) $hLower[mb_strtolower($h)] = $i;
                    $shLower = [];
                    if ($subHeaders) {
                        $subHeaders = array_map('trim', $subHeaders);
                        foreach ($subHeaders as $i => $h) $shLower[mb_strtolower($h)] = $i;
                    }
                    $idIdx = $hLower['id'] ?? null;
                    $priceIdx = $shLower['округл'] ?? $hLower['закупка'] ?? $hLower['купка'] ?? null;
                    $qtyIdx = $shLower['склад кол-во'] ?? $shLower['кол-во'] ?? $hLower['склад кол-во'] ?? $hLower['склад'] ?? $hLower['кол-во'] ?? $hLower['количество'] ?? null;
                    if ($idIdx === null) {
                        $result['errors'][] = 'Не знайдено колонку ID (шукаю заголовок "ID")';
                    } else {
                        $result['debug'] = "ID=$idIdx, Price=$priceIdx, Qty=$qtyIdx";
                        $user = getUserData();
                        $pdo->beginTransaction();
                        try {
                            $dataPos = ftell($fh2);
                            $firstRow = fgetcsv($fh2, 0, $delimiter);
                            if ($firstRow !== false) {
                                $result['first_row_qty'] = ($qtyIdx !== null && isset($firstRow[$qtyIdx])) ? trim($firstRow[$qtyIdx]) : 'N/A';
                                $result['first_row_id'] = ($idIdx !== null && isset($firstRow[$idIdx])) ? trim($firstRow[$idIdx]) : 'N/A';
                                $result['first_row_count'] = count($firstRow);
                                fseek($fh2, $dataPos);
                            }
                            while (($row = fgetcsv($fh2, 0, $delimiter)) !== false) {
                                if (count($row) < $idIdx + 1) continue;
                                $productId = (int)trim($row[$idIdx] ?? 0);
                                if ($productId <= 0) continue;
                                $qty = 0;
                                if ($qtyIdx !== null && isset($row[$qtyIdx])) {
                                    $qty = (float)str_replace(',', '.', trim($row[$qtyIdx]));
                                }
                                $costPrice = 0;
                                if ($priceIdx !== null && isset($row[$priceIdx])) {
                                    $costPrice = (float)str_replace(',', '.', str_replace(' ', '', trim($row[$priceIdx])));
                                }
                                if ($qty <= 0 && $costPrice <= 0) continue;
                                $stmt = $pdo->prepare("SELECT product_id FROM erp_products WHERE product_id = ?");
                                $stmt->execute([$productId]);
                                if ($stmt->fetch()) {
                                    if ($costPrice > 0) {
                                        $pdo->prepare("UPDATE erp_products SET price_purchase = ? WHERE product_id = ?")->execute([$costPrice, $productId]);
                                    }
                                    if ($qty > 0) {
                                        $pdo->prepare("INSERT INTO erp_stock_moves (product_id, type, quantity, cost_price, notes, user_id) VALUES (?, 'in', ?, ?, ?, ?)")
                                            ->execute([$productId, $qty, $costPrice ?: null, 'Початковий залишок (GSheets)', $user['user_id']]);
                                    }
                                    $result['matched']++;
                                } else {
                                    $result['errors'][] = "Товар з ID $productId не знайдено в ERP";
                                }
                                $result['rows']++;
                            }
                            $pdo->commit();
                        } catch (Exception $e) {
                            $pdo->rollBack();
                            $result['errors'][] = 'Помилка: ' . $e->getMessage();
                        }
                    }
                }
                fclose($fh2);
            }
        }
    }
    $debug = $result['debug'] ?? '';
    $firstRow = isset($result['first_row_qty']) ? " | 1-й рядок: ID={$result['first_row_id']}, Qty='{$result['first_row_qty']}', cols={$result['first_row_count']}" : '';
    $errStr = $result['errors'] ? ' (' . implode('; ', array_slice($result['errors'], 0, 5)) . ')' : '';
    if (count($result['errors']) > 0) {
        flashMessage('warning', 'Імпорт: оброблено ' . $result['rows'] . ', оновлено ' . $result['matched'] . ', помилок: ' . count($result['errors']) . $errStr . ($debug ? " | $debug" : '') . $firstRow);
    } else {
        flashMessage('success', 'Імпорт завершено. Оновлено ' . $result['matched'] . ' товарів.' . ($debug ? " ($debug)" : '') . $firstRow);
    }
    redirect(BASE_URL . '/modules/stock.php?action=import_gsheet');
}

if ($action === 'import_gsheet') {
    include __DIR__ . '/../includes/header.php';
    ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0"><i class="bi bi-upload"></i> Імпорт з Google Таблиці</h4>
        <div>
            <a href="<?php echo BASE_URL; ?>/modules/stock.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> На склад</a>
        </div>
    </div>
    <div class="card">
        <div class="card-header">Завантажити CSV (експорт з Google Таблиці)</div>
        <div class="card-body">
            <form method="post" action="?action=import_gsheet" enctype="multipart/form-data">
                <div class="mb-3">
                    <label class="form-label">Файл CSV (Tab-роздільник)</label>
                    <input type="file" name="csv_file" class="form-control" accept=".csv,.txt,.tsv" required>
                </div>
                <div class="alert alert-info small">
                    <strong>Очікуваний формат:</strong> перший рядок — заголовки, другий — підзаголовки (пропускаються).
                    Роздільник визначається автоматично (кома або табуляція).
                    Колонки за назвою заголовків:
                    <code>ID</code> — співпадає з <code>erp_products.product_id</code>,
                    <code>Закупка</code> — ціна закупівлі (записується в <code>price_purchase</code>),
                    <code>Склад кол-во</code> — кількість (створюється adjustment-рух).
                </div>
                <button type="submit" class="btn btn-primary"><i class="bi bi-upload"></i> Імпортувати</button>
            </form>
        </div>
    </div>
    <?php
    include __DIR__ . '/../includes/footer.php';
    exit;
}

if ($action === 'import_initial' && $_SERVER['REQUEST_METHOD'] === 'POST' && isManager()) {
    $result = ['matched' => 0, 'errors' => [], 'rows' => 0];
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $result['errors'][] = 'Помилка завантаження файлу';
    } else {
        $fh = fopen($_FILES['csv_file']['tmp_name'], 'r');
        if (!$fh) {
            $result['errors'][] = 'Не вдалося відкрити файл';
        } else {
            $bom = fread($fh, 3);
            if ($bom !== "\xEF\xBB\xBF") rewind($fh);
            $firstLine = fgets($fh);
            if ($firstLine === false) {
                $result['errors'][] = 'Порожній файл';
            } else {
                $commaCount = substr_count($firstLine, ',');
                $semicolonCount = substr_count($firstLine, ';');
                $tabCount = substr_count($firstLine, "\t");
                $delimiter = $semicolonCount >= $commaCount && $semicolonCount >= $tabCount ? ';' : ($tabCount >= $commaCount ? "\t" : ',');
                rewind($fh);
                $bom2 = fread($fh, 3);
                if ($bom2 !== "\xEF\xBB\xBF") rewind($fh);
                $headers = fgetcsv($fh, 0, $delimiter);
                if (!$headers) {
                    $result['errors'][] = 'Порожній файл';
                } else {
                    $headers = array_map('trim', $headers);
                    $hLower = [];
                    foreach ($headers as $i => $h) $hLower[mb_strtolower($h)] = $i;
                    $skuIdx = $hLower['артикул'] ?? $hLower['sku'] ?? $hLower['код'] ?? $hLower['code'] ?? null;
                    $modelIdx = $hLower['модель'] ?? $hLower['model'] ?? null;
                    $nameIdx = $hLower['назва'] ?? $hLower['name'] ?? $hLower['товар'] ?? null;
                    $qtyIdx = $hLower['кількість'] ?? $hLower['quantity'] ?? $hLower['к-сть'] ?? $hLower['количество'] ?? null;
                    $priceIdx = $hLower['ціна'] ?? $hLower['цена'] ?? $hLower['price'] ?? $hLower['закупівля'] ?? $hLower['cost_price'] ?? $hLower['собівартість'] ?? null;
                    if ($qtyIdx === null) {
                        $result['errors'][] = 'Не знайдено колонку з кількістю (кількість/quantity/к-сть)';
                    } elseif ($skuIdx === null && $modelIdx === null && $nameIdx === null) {
                        $result['errors'][] = 'Не знайдено колонку для пошуку товару (артикул/sku/модель/назва)';
                    } else {
                        $user = getUserData();
                        $pdo->beginTransaction();
                        try {
                            $products = $pdo->query("SELECT product_id, sku, model, name FROM erp_products")->fetchAll();
                            $prodBySku = []; $prodByModel = []; $prodByName = [];
                            foreach ($products as $p) {
                                if ($p['sku']) $prodBySku[mb_strtolower(trim($p['sku']))] = $p;
                                if ($p['model']) $prodByModel[mb_strtolower(trim($p['model']))] = $p;
                                $prodByName[mb_strtolower(trim($p['name']))] = $p;
                            }
                            $matchedCount = 0;
                            while (($row = fgetcsv($fh, 0, $delimiter)) !== false) {
                                $result['rows']++;
                                if (count($row) === 1 && trim($row[0]) === '') continue;
                                $qty = (float)trim($row[$qtyIdx] ?? 0);
                                if ($qty == 0) continue;
                                $costPrice = $priceIdx !== null ? (float)str_replace([' ', ','], ['', '.'], trim($row[$priceIdx] ?? '0')) : 0;
                                $product = null;
                                if ($skuIdx !== null) {
                                    $sku = mb_strtolower(trim($row[$skuIdx] ?? ''));
                                    if ($sku && isset($prodBySku[$sku])) $product = $prodBySku[$sku];
                                }
                                if (!$product && $modelIdx !== null) {
                                    $mod = mb_strtolower(trim($row[$modelIdx] ?? ''));
                                    if ($mod && isset($prodByModel[$mod])) $product = $prodByModel[$mod];
                                }
                                if (!$product && $nameIdx !== null) {
                                    $nm = mb_strtolower(trim($row[$nameIdx] ?? ''));
                                    if ($nm && isset($prodByName[$nm])) $product = $prodByName[$nm];
                                }
                                if ($product) {
                                    $stmt = $pdo->prepare("INSERT INTO erp_stock_moves (product_id, type, quantity, cost_price, notes, user_id) VALUES (?, 'adjustment', ?, ?, ?, ?)");
                                    $stmt->execute([$product['product_id'], $qty, $costPrice ?: null, 'Початковий залишок (імпорт)', $user['user_id']]);
                                    $matchedCount++;
                                }
                            }
                            $pdo->commit();
                            $result['matched'] = $matchedCount;
                        } catch (Exception $e) {
                            $pdo->rollBack();
                            $result['errors'][] = 'Помилка: ' . $e->getMessage();
                        }
                    }
                }
            }
            fclose($fh);
        }
    }
    if (count($result['errors']) > 0) {
        $msg = 'Імпорт: знайдено ' . $result['matched'] . ', помилок: ' . implode('; ', $result['errors']);
        flashMessage('error', $msg);
    } else {
        flashMessage('success', 'Імпорт завершено. Додано корекцій для ' . $result['matched'] . ' товарів із ' . $result['rows'] . ' рядків.');
    }
    redirect(BASE_URL . '/modules/stock.php?action=import_initial');
}

if ($action === 'import_initial') {
    include __DIR__ . '/../includes/header.php';
    ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0"><i class="bi bi-upload"></i> Імпорт початкових залишків</h4>
        <div>
            <a href="<?php echo BASE_URL; ?>/modules/stock.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> На склад</a>
        </div>
    </div>
    <div class="card">
        <div class="card-header">Завантажити CSV</div>
        <div class="card-body">
            <form method="post" action="?action=import_initial" enctype="multipart/form-data">
                <div class="mb-3">
                    <label class="form-label">Файл CSV</label>
                    <input type="file" name="csv_file" class="form-control" accept=".csv,.txt" required>
                </div>
                <p class="text-muted small">
                    Колонки: <code>артикул/sku/код</code> (для пошуку товару) або <code>модель</code> або <code>назва</code>,
                    <code>кількість</code> (обов'язково, позитивна — додати на склад),
                    <code>ціна</code> (опціонально, закупівельна).
                    Роздільник визначається автоматично (<code>;</code> або <code>,</code>).
                    Перший рядок — заголовки.
                </p>
                <button type="submit" class="btn btn-primary"><i class="bi bi-upload"></i> Імпортувати</button>
            </form>
            <hr>
            <p class="small mb-0">
                <i class="bi bi-arrow-right"></i> Для формату Google Таблиці з 2-рядковими заголовками — 
                <a href="?action=import_gsheet">спеціальний імпорт</a>
            </p>
        </div>
    </div>
    <?php
    include __DIR__ . '/../includes/footer.php';
    exit;
}

if ($action === 'corrections') {
    $corrPage = max(1, (int)($_GET['corr_page'] ?? 1));
    $corrPerPage = 50;
    $corrTotal = $pdo->query("SELECT COUNT(*) FROM erp_stock_moves WHERE type = 'adjustment'")->fetchColumn();
    $corrPagination = paginate($corrTotal, $corrPerPage, $corrPage);
    $corrections = $pdo->prepare("
        SELECT m.*, p.name as product_name,
            COALESCE((SELECT currency FROM erp_incoming_invoices ii JOIN erp_invoice_items iit ON ii.invoice_id = iit.invoice_id WHERE iit.product_id = p.product_id ORDER BY ii.date_added DESC LIMIT 1), 'EUR') as purchase_currency
        FROM erp_stock_moves m
        LEFT JOIN erp_products p ON m.product_id = p.product_id
        WHERE m.type = 'adjustment'
        ORDER BY CASE WHEN m.quantity = 0 THEN 1 ELSE 0 END, m.date_added DESC
        LIMIT ? OFFSET ?
    ");
    $corrections->bindValue(1, $corrPagination['per_page'], PDO::PARAM_INT);
    $corrections->bindValue(2, $corrPagination['offset'], PDO::PARAM_INT);
    $corrections->execute();
    $corrections = $corrections->fetchAll();

    $products = $pdo->query("SELECT product_id, name, model FROM erp_products ORDER BY name ASC")->fetchAll();
    $corrRates = getCurrentRates($pdo);

    $allP = $pdo->query("SELECT product_id, name, model, sku FROM erp_products ORDER BY name ASC")->fetchAll();
    $adjustProductsList = array_map(function($ap) {
        return [
            'id' => (int)$ap['product_id'],
            'name' => $ap['name'] ?: 'ID ' . $ap['product_id'],
            'model' => $ap['model'] ?? '',
            'sku' => $ap['sku'] ?? ''
        ];
    }, $allP);

    include __DIR__ . '/../includes/header.php';
    ?>
    <div x-data='stockPage(<?php echo htmlspecialchars(json_encode(["products" => $adjustProductsList]), ENT_QUOTES, 'UTF-8'); ?>)'>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0"><i class="bi bi-sliders"></i> Корекції залишків</h4>
        <div>
            <a href="<?php echo BASE_URL; ?>/modules/stock.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> На склад</a>
            <button class="btn btn-sm btn-warning" @click="openAdjust(0)"><i class="bi bi-plus-lg"></i> Нова корекція</button>
            <a href="<?php echo BASE_URL; ?>/modules/stock.php?action=import_initial" class="btn btn-sm btn-info"><i class="bi bi-upload"></i> Імпорт CSV</a>
        </div>
    </div>
    <div class="card">
        <div class="card-header">Історія корекцій</div>
        <div class="card-body p-0">
            <?php if (count($corrections) > 0): ?>
            <form method="post" action="?action=batch_delete_adjust" id="batchCorrForm" onsubmit="return confirm('Видалити вибрані корекції?')">
            <div class="table-container">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th style="width:40px;"><input type="checkbox" id="selectAll" :checked="selectAllCorr" @change="toggleAllCorr($event.target.checked)"></th>
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
                            <td><input type="checkbox" name="move_ids[]" value="<?php echo (int)$c['move_id']; ?>" class="corr-check"></td>
                            <td><?php echo (int)$c['move_id']; ?></td>
                            <td>
                                <a href="<?php echo BASE_URL; ?>/modules/stock.php?action=moves&id=<?php echo (int)$c['product_id']; ?>" class="text-decoration-none">
                                    <?php echo escape($c['product_name'] ?: 'ID: ' . $c['product_id']); ?>
                                </a>
                            </td>
                            <td class="fw-bold <?php echo $c['quantity'] > 0 ? 'text-success' : 'text-danger'; ?>"><?php echo (float)$c['quantity'] > 0 ? '+' . (float)$c['quantity'] : (float)$c['quantity']; ?></td>
                            <td><?php echo $c['cost_price'] ? formatMoney($c['cost_price'] * ($corrRates[$c['purchase_currency']] ?? $corrRates['EUR'])) : '-'; ?></td>
                            <td><?php echo escape($c['notes'] ?: '-'); ?></td>
                            <td><?php echo formatDate($c['date_added']); ?></td>
                            <td class="text-center">
                                <button class="btn btn-sm btn-outline-primary" @click="openCorrectionEdit(<?php echo htmlspecialchars(json_encode($c), ENT_QUOTES, 'UTF-8'); ?>)" title="Редагувати">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <a href="?action=delete_adjust&move_id=<?php echo (int)$c['move_id']; ?>" class="btn btn-sm btn-outline-danger" title="Видалити" onclick="return confirm('Видалити корекцію #<?php echo (int)$c['move_id']; ?>?')"><i class="bi bi-trash"></i></a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i> Видалити вибрані</button>
            </div>
            </form>
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

    <div class="modal fade" id="editCorrectionModal" tabindex="-1" x-ref="editCorrectionModal">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post" action="?action=edit_adjust">
                    <div class="modal-header">
                        <h5 class="modal-title">Редагувати корекцію</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="move_id" :value="editCorrection.move_id">
                        <div class="mb-3">
                            <label class="form-label">ID товару</label>
                            <input type="number" name="product_id" :value="editCorrection.product_id" class="form-control" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label required">Кількість (+, -)</label>
                            <input type="number" name="quantity" x-model.number="editCorrection.quantity" class="form-control" step="0.01" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Собівартість (валюта)</label>
                            <input type="number" name="cost_price" x-model.number="editCorrection.cost_price" class="form-control" step="0.01" min="0" placeholder="0.00">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Примітка</label>
                            <textarea name="notes" x-model="editCorrection.notes" class="form-control" rows="2"></textarea>
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

    <div class="modal fade" id="adjustModal" tabindex="-1" x-ref="adjustModal">
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
                            <input type="text" class="form-control" placeholder="Почніть вводити назву товару..." autocomplete="off" required
                                   x-model="adjustQuery" @input="searchAdjust()" @focus="if(adjustQuery) searchAdjust()">
                            <input type="hidden" name="product_id" :value="adjustSelectedId">
                            <div class="dropdown-menu w-100" :class="adjustResults.length > 0 ? 'show' : ''" style="max-height:300px;overflow-y:auto;display:block;" x-show="adjustResults.length > 0" x-cloak>
                                <template x-for="p in adjustResults" :key="p.id">
                                    <button type="button" class="dropdown-item" @click="selectAdjust(p)" x-text="p.name + (p.model ? ' (' + p.model + ')' : '')"></button>
                                </template>
                            </div>
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
    COALESCE(SUM(CASE WHEN sm.type IN ('in','return_in','adjustment') THEN sm.quantity ELSE 0 END) - SUM(CASE WHEN sm.type IN ('out','return_out') THEN sm.quantity ELSE 0 END), 0) as stock_qty
    FROM erp_products p
    LEFT JOIN erp_stock_moves sm ON p.product_id = sm.product_id
    $joinCategory
    $where
    GROUP BY p.product_id
    ORDER BY SUM(CASE WHEN sm.type IN ('in','return_in','adjustment') THEN sm.quantity ELSE 0 END) - SUM(CASE WHEN sm.type IN ('out','return_out') THEN sm.quantity ELSE 0 END) = 0, p.name ASC
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

// Load all products for the adjust modal autocomplete
$allP = $pdo->query("SELECT product_id, name, model, sku FROM erp_products ORDER BY name ASC")->fetchAll();
$adjustProductsList = array_map(function($ap) {
    return [
        'id' => (int)$ap['product_id'],
        'name' => $ap['name'] ?: 'ID ' . $ap['product_id'],
        'model' => $ap['model'] ?? '',
        'sku' => $ap['sku'] ?? ''
    ];
}, $allP);

// Load categories for the edit product modal
$allCategories = getCategories($pdo);

include __DIR__ . '/../includes/header.php';
?>
<div x-data='stockPage(<?php echo htmlspecialchars(json_encode(["products" => $adjustProductsList]), ENT_QUOTES, 'UTF-8'); ?>)'>

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
        <button class="btn btn-sm btn-outline-info" @click="syncProducts()" :disabled="syncing">
            <span x-show="!syncing"><i class="bi bi-arrow-repeat"></i> Синхр. OC</span>
            <span x-show="syncing" x-cloak><span class="spinner-border spinner-border-sm"></span> Синхр...</span>
        </button>
        <button class="btn btn-sm btn-outline-info" @click="toggleSyncOneWrap()"><i class="bi bi-search"></i> OC по ID</button>
        <?php endif; ?>
    </div>
</div>

<div class="mb-3" x-show="syncOneOpen" x-cloak>
    <div class="input-group" style="max-width:400px;">
        <span class="input-group-text">API ID</span>
        <input type="text" x-ref="syncOneId" class="form-control" placeholder="Введіть ID товару" inputmode="numeric">
        <button class="btn btn-outline-info" @click="syncOneProduct()" :disabled="syncingOne">
            <span x-show="!syncingOne">Синхронізувати</span>
            <span x-show="syncingOne" x-cloak><span class="spinner-border spinner-border-sm"></span>...</span>
        </button>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <ul class="nav nav-tabs card-header-tabs">
            <li class="nav-item"><a class="nav-link <?php echo $action === 'list' ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>/modules/stock.php"><i class="bi bi-box"></i> Товари</a></li>
            <li class="nav-item"><a class="nav-link <?php echo $action === 'corrections' ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>/modules/stock.php?action=corrections"><i class="bi bi-sliders"></i> Корекція</a></li>
            <li class="nav-item"><a class="nav-link <?php echo $action === 'import_initial' || $action === 'import_gsheet' ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>/modules/stock.php?action=import_initial"><i class="bi bi-upload"></i> Імпорт</a></li>
            <li class="nav-item"><a class="nav-link <?php echo $action === 'pricing' ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>/modules/stock.php?action=pricing"><i class="bi bi-currency-exchange"></i> Ціни</a></li>
        </ul>
    </div>
    <div class="card-body p-0">
        <?php if (count($products) > 0): ?>
        <div class="table-container">
            <table id="products-table" class="table table-hover mb-0 table-product">
                <colgroup>
                    <col style="width:50px">
                    <col>
                    <col style="width:100px">
                    <col style="width:90px">
                    <col style="width:110px">
                    <col style="width:75px">
                    <col style="width:80px">
                    <col style="width:80px">
                    <col style="width:80px">
                    <col style="width:85px">
                    <col style="width:85px">
                </colgroup>
                <thead>
                    <tr>
                        <th style="width:50px">ID</th>
                        <th>Назва</th>
                        <th style="width:100px">Модель</th>
                        <th style="width:90px">SKU</th>
                        <th style="width:110px">Категорія</th>
                        <th style="width:75px" class="text-center">На складі</th>
                        <th style="width:80px" class="text-end">Опт</th>
                        <th style="width:80px" class="text-end">Дрібний опт</th>
                        <th style="width:80px" class="text-end">Роздріб</th>
                        <th style="width:85px" class="text-end">Закупівля</th>
                        <th style="width:85px" class="text-center">Дії</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $p): ?>
                    <?php $stockQty = (float)$p['stock_qty']; ?>
                    <?php $cats = $productCategories[$p['product_id']] ?? []; ?>
                    <tr class="<?php echo $stockQty <= 0 ? 'table-danger' : ($stockQty <= 5 ? 'table-warning' : ''); ?>">
                        <td><?php echo (int)$p['product_id']; ?></td>
                        <td class="name-cell">
                            <a href="<?php echo BASE_URL; ?>/modules/stock.php?action=moves&id=<?php echo (int)$p['product_id']; ?>" class="text-decoration-none">
                                <?php echo escape($p['name'] ?: '—'); ?>
                            </a>
                        </td>
                        <td><?php echo escape($p['model'] ?: '-'); ?></td>
                        <td><?php echo escape($p['sku'] ?: '-'); ?></td>
                        <td><span class="cat-label"><?php echo $cats ? escape(implode(', ', $cats)) : '-'; ?></span></td>
                        <td class="text-center fw-bold"><?php echo (int)$stockQty; ?></td>
                        <td class="text-end"><?php echo $p['price_wholesale'] ? formatMoney($p['price_wholesale']) : '-'; ?></td>
                        <td class="text-end"><?php echo $p['price_semi_wholesale'] ? formatMoney($p['price_semi_wholesale']) : '-'; ?></td>
                        <td class="text-end"><?php echo $p['price_retail'] ? formatMoney($p['price_retail']) : '-'; ?></td>
                        <td class="text-end"><?php echo $p['price_purchase'] ? formatMoney($p['price_purchase']) : '-'; ?></td>
                        <td class="text-center col-actions">
                            <button class="btn btn-sm btn-outline-primary" @click='openProductEdit(<?php echo json_encode(array_merge($p, ['category_ids' => $productCategoryIds[$p['product_id']] ?? []]), JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)' title="Редагувати товар">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-success" @click="openAdjust(<?php echo (int)$p['product_id']; ?>)" title="Корекція залишку">
                                <i class="bi bi-box-seam"></i>
                            </button>
                            <a href="<?php echo BASE_URL; ?>/modules/stock.php?action=moves&id=<?php echo (int)$p['product_id']; ?>" class="btn btn-sm btn-outline-info" title="Рух товару">
                                <i class="bi bi-arrow-left-right"></i>
                            </a>
                            <a href="?action=delete_product&product_id=<?php echo (int)$p['product_id']; ?>" class="btn btn-sm btn-outline-danger" title="Видалити товар" onclick="return confirm('Видалити товар «<?php echo escape($p['name'] ?: 'ID: ' . $p['product_id']); ?>» і всі його рухи?')"><i class="bi bi-trash"></i></a>
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

<div class="modal fade" id="adjustModal" tabindex="-1" x-ref="adjustModal">
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
                        <input type="text" class="form-control" placeholder="Почніть вводити назву товару..." autocomplete="off" required
                               x-model="adjustQuery" @input="searchAdjust()" @focus="if(adjustQuery) searchAdjust()">
                        <input type="hidden" name="product_id" :value="adjustSelectedId">
                        <div class="dropdown-menu w-100" :class="adjustResults.length > 0 ? 'show' : ''" style="max-height:300px;overflow-y:auto;display:block;" x-show="adjustResults.length > 0" x-cloak>
                            <template x-for="p in adjustResults" :key="p.id">
                                <button type="button" class="dropdown-item" @click="selectAdjust(p)" x-text="p.name + (p.model ? ' (' + p.model + ')' : '')"></button>
                            </template>
                        </div>
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

<div class="modal fade" id="editProductModal" tabindex="-1" x-ref="editProductModal">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" action="?action=edit_product">
                <div class="modal-header">
                    <h5 class="modal-title">Редагувати товар</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="product_id" :value="editProduct.product_id">
                    <div class="mb-3">
                        <label class="form-label required">Назва</label>
                        <input type="text" name="name" x-model="editProduct.name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Модель</label>
                        <input type="text" name="model" x-model="editProduct.model" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">SKU</label>
                        <input type="text" name="sku" x-model="editProduct.sku" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Категорії</label>
                        <div class="d-flex flex-wrap gap-2" style="max-height:160px;overflow-y:auto;">
                            <?php foreach ($allCategories as $cat): ?>
                            <label class="form-check form-check-inline mb-1">
                                <input type="checkbox" name="category_ids[]" value="<?php echo (int)$cat['category_id']; ?>" class="form-check-input"
                                       :checked="editProduct.category_ids.includes(<?php echo (int)$cat['category_id']; ?>)"
                                       @change="toggleCategory(<?php echo (int)$cat['category_id']; ?>)">
                                <span class="form-check-label small"><?php echo escape($cat['name']); ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Опт</label>
                            <input type="number" name="price_wholesale" x-model.number="editProduct.price_wholesale" class="form-control" step="0.01" min="0">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Дрібний опт</label>
                            <input type="number" name="price_semi_wholesale" x-model.number="editProduct.price_semi_wholesale" class="form-control" step="0.01" min="0">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Роздріб</label>
                            <input type="number" name="price_retail" x-model.number="editProduct.price_retail" class="form-control" step="0.01" min="0">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Закупівля</label>
                        <input type="number" name="price_purchase" x-model.number="editProduct.price_purchase" class="form-control" step="0.01" min="0">
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
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
