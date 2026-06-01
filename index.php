<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_helpers.php';
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

$stmt = $pdo->query("
    SELECT COALESCE(SUM(i.total_local - COALESCE(p.paid, 0)), 0) as debt
    FROM erp_incoming_invoices i
    LEFT JOIN (
        SELECT invoice_id, SUM(amount) as paid FROM erp_payments WHERE invoice_id IS NOT NULL GROUP BY invoice_id
    ) p ON i.invoice_id = p.invoice_id
    WHERE i.status IN ('draft', 'confirmed')
");
$supplierDebt = (float)$stmt->fetch()['debt'];

$stmt = $pdo->query("
    SELECT COALESCE(SUM(o.total - COALESCE(p.paid, 0)), 0) as debt
    FROM erp_orders o
    LEFT JOIN (
        SELECT order_id, SUM(amount) as paid FROM erp_payments WHERE order_id IS NOT NULL GROUP BY order_id
    ) p ON o.order_id = p.order_id
");
$customerDebt = (float)$stmt->fetch()['debt'];

$stmt = $pdo->query("SELECT p.product_id, p.name, COALESCE(SUM(CASE WHEN sm.type IN ('in','return_in','adjustment') THEN sm.quantity ELSE 0 END) - SUM(CASE WHEN sm.type IN ('out','return_out') THEN sm.quantity ELSE 0 END), 0) as stock FROM erp_products p LEFT JOIN erp_stock_moves sm ON p.product_id = sm.product_id GROUP BY p.product_id HAVING stock <= 5 ORDER BY stock ASC LIMIT 10");
$lowStock = $stmt->fetchAll();

$stmt = $pdo->query("SELECT o.order_id, o.customer_name, o.total, o.status_name, o.date_added FROM erp_orders o ORDER BY o.date_added DESC LIMIT 10");
$recentOrders = $stmt->fetchAll();

$rates = getCurrentRates($pdo);
$markups = getDefaultMarkups($pdo);

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-speedometer2"></i> Дашборд</h4>
    <div class="text-muted small">
        <i class="bi bi-currency-exchange"></i> Курс: USD <strong><?php echo number_format($rates['USD'], 2, '.', ' '); ?></strong> / EUR <strong><?php echo number_format($rates['EUR'], 2, '.', ' '); ?></strong>
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

<div class="row g-3 mb-4">
    <div class="col-md-3 col-6">
        <div class="stat-card bg-danger text-white position-relative">
            <i class="bi bi-truck stat-icon"></i>
            <div class="stat-value"><?php echo formatMoney($supplierDebt); ?></div>
            <div class="stat-label">Борг постачальникам</div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-card bg-success text-white position-relative">
            <i class="bi bi-people stat-icon"></i>
            <div class="stat-value"><?php echo formatMoney($customerDebt); ?></div>
            <div class="stat-label">Борг нам (клієнти)</div>
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
    <div class="col-lg-12">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-gear"></i> Швидкі дії
            </div>
            <div class="card-body">
                <div class="row g-2">
                    <div class="col-3">
                        <a href="<?php echo BASE_URL; ?>/modules/orders.php?action=create" class="btn btn-primary w-100">
                            <i class="bi bi-plus-lg"></i> Нове замовлення
                        </a>
                    </div>
                    <div class="col-3">
                        <a href="<?php echo BASE_URL; ?>/modules/stock.php" class="btn btn-outline-success w-100">
                            <i class="bi bi-box-seam"></i> Товари
                        </a>
                    </div>
                    <div class="col-3">
                        <a href="<?php echo BASE_URL; ?>/modules/pricing.php" class="btn btn-outline-warning w-100">
                            <i class="bi bi-currency-exchange"></i> Ціни
                        </a>
                    </div>
                    <div class="col-3">
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
