<?php
require_once __DIR__ . '/config.php';
requireLogin();

$today = date('Y-m-d');

$stmt = $pdo->query("SELECT COUNT(*) as total FROM erp_products WHERE status = 1");
$totalProducts = $stmt->fetch()['total'];

$stmt = $pdo->query("SELECT COUNT(*) as total FROM erp_customers WHERE status = 1");
$totalCustomers = $stmt->fetch()['total'];

$stmt = $pdo->query("SELECT COUNT(*) as total FROM erp_suppliers WHERE status = 1");
$totalSuppliers = $stmt->fetch()['total'];

$stmt = $pdo->query("SELECT COUNT(*) as total FROM erp_orders WHERE DATE(date_added) = '$today'");
$todayOrders = $stmt->fetch()['total'];

$stmt = $pdo->query("SELECT COALESCE(SUM(total), 0) as total FROM erp_orders WHERE DATE(date_added) = '$today'");
$todayRevenue = (float)$stmt->fetch()['total'];

$stmt = $pdo->query("SELECT p.product_id, p.name, COALESCE(SUM(CASE WHEN sm.type IN ('in','return_in') THEN sm.quantity ELSE 0 END) - SUM(CASE WHEN sm.type IN ('out','return_out') THEN sm.quantity ELSE 0 END), 0) as stock FROM erp_products p LEFT JOIN erp_stock_moves sm ON p.product_id = sm.product_id GROUP BY p.product_id HAVING stock <= 5 ORDER BY stock ASC LIMIT 10");
$lowStock = $stmt->fetchAll();

$stmt = $pdo->query("SELECT o.order_id, o.customer_name, o.total, o.status_name, o.date_added FROM erp_orders o ORDER BY o.date_added DESC LIMIT 10");
$recentOrders = $stmt->fetchAll();

$stmt = $pdo->query("SELECT r.*, p.name as product_name FROM erp_returns r LEFT JOIN erp_products p ON r.product_id = p.product_id WHERE r.status = 'pending' ORDER BY r.date_added DESC LIMIT 10");
$pendingReturns = $stmt->fetchAll();

$rate = getCurrentRate($pdo);
$markups = getDefaultMarkups($pdo);

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-speedometer2"></i> Дашборд</h4>
    <div class="text-muted small">
        <i class="bi bi-currency-exchange"></i> Курс USD/UAH: <strong><?php echo number_format($rate, 2, '.', ' '); ?></strong>
        <span class="ms-3"><i class="bi bi-calendar3"></i> <?php echo date('d.m.Y'); ?></span>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3 col-6">
        <div class="stat-card bg-primary text-white position-relative">
            <i class="bi bi-box-seam stat-icon"></i>
            <div class="stat-value"><?php echo $totalProducts; ?></div>
            <div class="stat-label">Товарів</div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-card bg-success text-white position-relative">
            <i class="bi bi-people stat-icon"></i>
            <div class="stat-value"><?php echo $totalCustomers; ?></div>
            <div class="stat-label">Клієнтів</div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-card bg-info text-white position-relative">
            <i class="bi bi-truck stat-icon"></i>
            <div class="stat-value"><?php echo $totalSuppliers; ?></div>
            <div class="stat-label">Постачальників</div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-card bg-warning text-dark position-relative">
            <i class="bi bi-cart3 stat-icon"></i>
            <div class="stat-value"><?php echo $todayOrders; ?></div>
            <div class="stat-label">Замовлень сьогодні</div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Останні замовлення</span>
                <a href="<?php echo BASE_URL; ?>/modules/orders.php" class="btn btn-sm btn-outline-primary">Всі замовлення</a>
            </div>
            <div class="card-body p-0">
                <?php if (count($recentOrders) > 0): ?>
                <div class="table-container">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Клієнт</th>
                                <th>Сума</th>
                                <th>Статус</th>
                                <th>Дата</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentOrders as $order): ?>
                            <tr>
                                <td><?php echo (int)$order['order_id']; ?></td>
                                <td><?php echo escape($order['customer_name']); ?></td>
                                <td><?php echo formatMoney($order['total']); ?></td>
                                <td><?php echo getStatusBadge($order['status_name']); ?></td>
                                <td><?php echo formatDate($order['date_added']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="text-center py-4 text-muted">
                    <i class="bi bi-inbox" style="font-size:2rem;"></i>
                    <p class="mt-2 mb-0">Ще немає замовлень</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Закінчується товар</span>
                <a href="<?php echo BASE_URL; ?>/modules/stock.php" class="btn btn-sm btn-outline-primary">Склад</a>
            </div>
            <div class="card-body p-0">
                <?php if (count($lowStock) > 0): ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($lowStock as $item): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center py-2">
                        <span class="text-truncate me-2"><?php echo escape($item['name'] ?: 'ID: ' . $item['product_id']); ?></span>
                        <span class="badge bg-<?php echo $item['stock'] <= 0 ? 'danger' : 'warning'; ?> rounded-pill">
                            <?php echo (int)$item['stock']; ?>
                        </span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php else: ?>
                <div class="text-center py-4 text-muted">
                    <i class="bi bi-check-circle" style="font-size:2rem;"></i>
                    <p class="mt-2 mb-0">Усі товари в наявності</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mt-2">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Очікують повернення</span>
                <a href="<?php echo BASE_URL; ?>/modules/returns.php" class="btn btn-sm btn-outline-primary">Усі повернення</a>
            </div>
            <div class="card-body p-0">
                <?php if (count($pendingReturns) > 0): ?>
                <div class="table-container">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Товар</th>
                                <th>К-сть</th>
                                <th>Причина</th>
                                <th>Дата</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pendingReturns as $ret): ?>
                            <tr>
                                <td><?php echo (int)$ret['return_id']; ?></td>
                                <td><?php echo escape($ret['product_name'] ?: 'ID: ' . $ret['product_id']); ?></td>
                                <td><?php echo (int)$ret['quantity']; ?></td>
                                <td class="text-truncate" style="max-width:150px;"><?php echo escape($ret['reason']); ?></td>
                                <td><?php echo formatDate($ret['date_added']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="text-center py-4 text-muted">
                    <i class="bi bi-check-circle" style="font-size:2rem;"></i>
                    <p class="mt-2 mb-0">Немає очікуваних повернень</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-gear"></i> Швидкі дії
            </div>
            <div class="card-body">
                <div class="row g-2">
                    <div class="col-6">
                        <a href="<?php echo BASE_URL; ?>/modules/incoming.php" class="btn btn-outline-primary w-100">
                            <i class="bi bi-receipt"></i> Нова накладна
                        </a>
                    </div>
                    <div class="col-6">
                        <a href="<?php echo BASE_URL; ?>/modules/suppliers.php" class="btn btn-outline-success w-100">
                            <i class="bi bi-truck"></i> Постачальник
                        </a>
                    </div>
                    <div class="col-6">
                        <a href="<?php echo BASE_URL; ?>/modules/pricing.php" class="btn btn-outline-warning w-100">
                            <i class="bi bi-currency-exchange"></i> Ціни
                        </a>
                    </div>
                    <div class="col-6">
                        <a href="<?php echo BASE_URL; ?>/modules/analytics.php" class="btn btn-outline-info w-100">
                            <i class="bi bi-graph-up"></i> Аналітика
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
