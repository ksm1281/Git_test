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

    <?php include __DIR__ . '/../includes/footer.php'; ?>
    <?php exit;
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
$sql = "SELECT p.*,
    COALESCE(SUM(CASE WHEN sm.type IN ('in','return_in') THEN sm.quantity ELSE 0 END) - SUM(CASE WHEN sm.type IN ('out','return_out') THEN sm.quantity ELSE 0 END), 0) as stock_qty
    FROM erp_products p
    LEFT JOIN erp_stock_moves sm ON p.product_id = sm.product_id
    $where
    GROUP BY p.product_id
    ORDER BY p.name ASC
    LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="bi bi-boxes"></i> Склад</h4>
    <div class="d-flex gap-2">
        <form method="get" class="d-flex">
            <input type="search" name="search" class="form-control form-control-sm search-box me-2" placeholder="Пошук товару..." value="<?php echo escape($search); ?>">
            <button class="btn btn-sm btn-outline-primary"><i class="bi bi-search"></i></button>
        </form>
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
            <li class="nav-item"><a class="nav-link active" href="<?php echo BASE_URL; ?>/modules/stock.php">Товари на складі</a></li>
            <li class="nav-item"><a class="nav-link" href="<?php echo BASE_URL; ?>/modules/stock.php?action=corrections"><i class="bi bi-sliders"></i> Корекція</a></li>
        </ul>
    </div>
    <div class="card-body p-0">
        <?php if (count($products) > 0): ?>
        <div class="table-container">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Назва</th>
                        <th>Модель</th>
                        <th>SKU</th>
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
                    <tr class="<?php echo $stockQty <= 0 ? 'table-danger' : ($stockQty <= 5 ? 'table-warning' : ''); ?>">
                        <td><?php echo (int)$p['product_id']; ?></td>
                        <td class="text-truncate" style="max-width:200px;">
                            <a href="<?php echo BASE_URL; ?>/modules/stock.php?action=moves&id=<?php echo (int)$p['product_id']; ?>" class="text-decoration-none">
                                <?php echo escape($p['name'] ?: '—'); ?>
                            </a>
                        </td>
                        <td><?php echo escape($p['model'] ?: '-'); ?></td>
                        <td><?php echo escape($p['sku'] ?: '-'); ?></td>
                        <td class="text-center fw-bold"><?php echo (int)$stockQty; ?></td>
                        <td class="text-end"><?php echo $p['price_wholesale'] ? formatMoney($p['price_wholesale']) : '-'; ?></td>
                        <td class="text-end"><?php echo $p['price_semi_wholesale'] ? formatMoney($p['price_semi_wholesale']) : '-'; ?></td>
                        <td class="text-end"><?php echo $p['price_retail'] ? formatMoney($p['price_retail']) : '-'; ?></td>
                        <td class="text-end"><?php echo $p['price_purchase'] ? formatMoney($p['price_purchase']) : '-'; ?></td>
                        <td class="text-center">
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
                    <div class="mb-3">
                        <label class="form-label required">Товар</label>
                        <select name="product_id" class="form-select" required>
                            <option value="">-- Оберіть товар --</option>
                            <?php
                            $allProducts = $pdo->query("SELECT product_id, name, model FROM erp_products ORDER BY name ASC")->fetchAll();
                            foreach ($allProducts as $ap): ?>
                            <option value="<?php echo (int)$ap['product_id']; ?>"><?php echo escape($ap['name'] ?: 'ID ' . $ap['product_id']); ?> (<?php echo escape($ap['model'] ?: '-'); ?>)</option>
                            <?php endforeach; ?>
                        </select>
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

<?php include __DIR__ . '/../includes/footer.php'; ?>
