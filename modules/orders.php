<?php
require_once __DIR__ . '/../config.php';
requireLogin();

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? '';

$where = [];
$params = [];

if ($search) {
    $where[] = "(o.customer_name LIKE ? OR o.email LIKE ? OR o.telephone LIKE ? OR CAST(o.order_id AS CHAR) LIKE ?)";
    $s = "%$search%";
    $params = array_merge($params, [$s, $s, $s, $s]);
}

if ($statusFilter) {
    $where[] = "o.status_name = ?";
    $params[] = $statusFilter;
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countSql = "SELECT COUNT(*) FROM erp_orders o $whereClause";
$stmt = $pdo->prepare($countSql);
$stmt->execute($params);
$total = $stmt->fetchColumn();
$pagination = paginate($total, $perPage, $page);

$sql = "SELECT o.*, c.firstname, c.lastname FROM erp_orders o LEFT JOIN erp_customers c ON o.customer_id = c.customer_id $whereClause ORDER BY o.date_added DESC LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

$stmt = $pdo->query("SELECT DISTINCT status_name FROM erp_orders ORDER BY status_name");
$statuses = $stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="bi bi-cart3"></i> Замовлення</h4>
    <form method="get" class="d-flex gap-2">
        <select name="status" class="form-select form-select-sm" style="width:auto;" onchange="this.form.submit()">
            <option value="">Усі статуси</option>
            <?php foreach ($statuses as $s): ?>
            <option value="<?php echo escape($s['status_name']); ?>" <?php echo $statusFilter === $s['status_name'] ? 'selected' : ''; ?>><?php echo escape($s['status_name']); ?></option>
            <?php endforeach; ?>
        </select>
        <input type="search" name="search" class="form-control form-control-sm search-box" placeholder="Пошук..." value="<?php echo escape($search); ?>">
        <button class="btn btn-sm btn-outline-primary"><i class="bi bi-search"></i></button>
    </form>
</div>

<div class="card">
    <div class="card-body p-0">
        <?php if (count($orders) > 0): ?>
        <div class="table-container">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Клієнт</th>
                        <th>Email</th>
                        <th>Телефон</th>
                        <th class="text-end">Сума</th>
                        <th>Статус</th>
                        <th>Дата</th>
                        <th class="text-center">Дії</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $o): ?>
                    <tr>
                        <td><?php echo (int)$o['order_id']; ?></td>
                        <td><?php echo escape($o['customer_name'] ?: ($o['firstname'] . ' ' . $o['lastname'])); ?></td>
                        <td><?php echo escape($o['email'] ?: '-'); ?></td>
                        <td><?php echo escape($o['telephone'] ?: '-'); ?></td>
                        <td class="text-end fw-bold"><?php echo formatMoney($o['total']); ?></td>
                        <td><?php echo getStatusBadge(strtolower($o['status_name'])); ?></td>
                        <td><?php echo formatDate($o['date_added']); ?></td>
                        <td class="text-center">
                            <a href="<?php echo BASE_URL; ?>/modules/orders.php?action=view&id=<?php echo $o['order_id']; ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="text-center py-5 text-muted">
            <i class="bi bi-cart3" style="font-size:3rem;"></i>
            <p class="mt-3 mb-0"><?php echo $search || $statusFilter ? 'Нічого не знайдено' : 'Ще немає замовлень'; ?></p>
        </div>
        <?php endif; ?>
    </div>
    <?php if ($pagination['total_pages'] > 1): ?>
    <div class="card-footer"><?php echo renderPagination(BASE_URL . '/modules/orders.php?', $pagination); ?></div>
    <?php endif; ?>
</div>

<?php
if (isset($_GET['action']) && $_GET['action'] === 'view') {
    $orderId = (int)$_GET['id'];
    $stmt = $pdo->prepare("SELECT * FROM erp_orders WHERE order_id = ?");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    if (!$order) { flashMessage('error', 'Замовлення не знайдено'); redirect(BASE_URL . '/modules/orders.php'); }

    $stmt = $pdo->prepare("SELECT op.*, p.name as product_name FROM erp_order_products op LEFT JOIN erp_products p ON op.product_id = p.product_id WHERE op.order_id = ?");
    $stmt->execute([$orderId]);
    $items = $stmt->fetchAll();
?>
    <div class="card mt-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>Деталі замовлення #<?php echo (int)$order['order_id']; ?></span>
            <a href="<?php echo BASE_URL; ?>/modules/orders.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> Назад</a>
        </div>
        <div class="card-body">
            <div class="row g-3 mb-3">
                <div class="col-md-3">
                    <small class="text-muted">Клієнт</small>
                    <div class="fw-bold"><?php echo escape($order['customer_name']); ?></div>
                </div>
                <div class="col-md-3">
                    <small class="text-muted">Email</small>
                    <div><?php echo escape($order['email'] ?: '-'); ?></div>
                </div>
                <div class="col-md-3">
                    <small class="text-muted">Телефон</small>
                    <div><?php echo escape($order['telephone'] ?: '-'); ?></div>
                </div>
                <div class="col-md-3">
                    <small class="text-muted">Статус</small>
                    <div><?php echo getStatusBadge(strtolower($order['status_name'])); ?></div>
                </div>
            </div>
            <div class="table-container">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Товар</th>
                            <th class="text-center">К-сть</th>
                            <th class="text-end">Ціна</th>
                            <th class="text-end">Сума</th>
                            <th class="text-end">Собівартість</th>
                            <th class="text-end">Прибуток</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                        <tr>
                            <td><?php echo escape($item['product_name'] ?: $item['name']); ?></td>
                            <td class="text-center"><?php echo (int)$item['quantity']; ?></td>
                            <td class="text-end"><?php echo formatMoney($item['price']); ?></td>
                            <td class="text-end"><?php echo formatMoney($item['total']); ?></td>
                            <td class="text-end"><?php echo $item['cost_price'] ? formatMoney($item['cost_price']) : '-'; ?></td>
                            <td class="text-end <?php echo $item['profit'] > 0 ? 'text-success' : 'text-danger'; ?>"><?php echo formatMoney($item['profit']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="fw-bold">
                            <td colspan="3">Разом</td>
                            <td class="text-end"><?php echo formatMoney($order['total']); ?></td>
                            <td></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
<?php
}
?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
