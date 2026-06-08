<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../_helpers.php';
requireLogin();

$period = $_GET['period'] ?? 'month';
$year = (int)($_GET['year'] ?? date('Y'));

switch ($period) {
    case 'today':
        $dateFrom = date('Y-m-d');
        $dateTo = date('Y-m-d');
        $groupBy = 'DATE_FORMAT(o.date_added, \'%H:00\')';
        break;
    case 'week':
        $dateFrom = date('Y-m-d', strtotime('monday this week'));
        $dateTo = date('Y-m-d');
        $groupBy = 'DATE(o.date_added)';
        break;
    case 'month':
        $dateFrom = date('Y-m-01');
        $dateTo = date('Y-m-t');
        $groupBy = 'DATE(o.date_added)';
        break;
    case 'year':
        $dateFrom = "$year-01-01";
        $dateTo = "$year-12-31";
        $groupBy = 'DATE_FORMAT(o.date_added, \'%Y-%m\')';
        break;
    default:
        $dateFrom = date('Y-m-01');
        $dateTo = date('Y-m-t');
        $groupBy = 'DATE(o.date_added)';
}

$stmt = $pdo->prepare("
    SELECT $groupBy as period, COUNT(*) as order_count, COALESCE(SUM(total), 0) as revenue
    FROM erp_orders o
    WHERE o.date_added >= ? AND o.date_added <= ?
    GROUP BY period ORDER BY period ASC
");
$stmt->execute([$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']);
$chartData = $stmt->fetchAll();

$labels = [];
$ordersData = [];
$revenueData = [];
foreach ($chartData as $row) {
    $labels[] = $row['period'];
    $ordersData[] = (int)$row['order_count'];
    $revenueData[] = (float)$row['revenue'];
}

$stmt = $pdo->query("SELECT COUNT(*) as total, COALESCE(SUM(total), 0) as total_revenue FROM erp_orders");
$totalStats = $stmt->fetch();

$stmt = $pdo->query("SELECT COUNT(*) as total FROM erp_products WHERE status = 1");
$totalProducts = (int)$stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) as total FROM erp_customers WHERE status = 1");
$totalCustomers = (int)$stmt->fetchColumn();

$stmt = $pdo->query("SELECT status_name, COUNT(*) as count FROM erp_orders GROUP BY status_name ORDER BY count DESC");
$orderStatuses = $stmt->fetchAll();

$stmt = $pdo->query("SELECT s.name as supplier_name, COUNT(*) as invoice_count, COALESCE(SUM(i.total_foreign), 0) as total_spent FROM erp_incoming_invoices i LEFT JOIN erp_suppliers s ON i.supplier_id = s.supplier_id WHERE i.status = 'confirmed' GROUP BY i.supplier_id ORDER BY total_spent DESC LIMIT 10");
$topSuppliers = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT COALESCE(SUM(op.profit), 0) as total_profit FROM erp_order_products op JOIN erp_orders o ON op.order_id = o.order_id WHERE o.date_added >= ? AND o.date_added <= ?");
$stmt->execute([$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']);
$profitData = $stmt->fetch();
$totalProfit = (float)$profitData['total_profit'];

include __DIR__ . '/../includes/header.php';
?>
<script id="chartData" type="application/json"><?php echo json_encode([
    'labels' => $labels,
    'ordersData' => $ordersData,
    'revenueData' => $revenueData,
    'statusLabels' => array_column($orderStatuses, 'status_name'),
    'statusData' => array_column($orderStatuses, 'count'),
]); ?></script>

<div x-data="analyticsPage" data-period="<?php echo $period; ?>" data-year="<?php echo $year; ?>">
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="bi bi-graph-up"></i> Аналітика</h4>
    <form method="get" class="d-flex gap-2">
        <select name="period" class="form-select form-select-sm" style="width:auto;" x-model="period" @change="submitForm()">
            <option value="today">Сьогодні</option>
            <option value="week">Цей тиждень</option>
            <option value="month">Цей місяць</option>
            <option value="year">Рік</option>
        </select>
        <select name="year" class="form-select form-select-sm" style="width:auto;" x-show="period === 'year'" x-cloak x-model="year" @change="submitForm()">
            <?php for ($y = date('Y'); $y >= 2020; $y--): ?>
            <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
            <?php endfor; ?>
        </select>
    </form>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3 col-6">
        <div class="stat-card bg-primary text-white position-relative">
            <i class="bi bi-cart3 stat-icon"></i>
            <div class="stat-value"><?php echo (int)$totalStats['total']; ?></div>
            <div class="stat-label">Всього замовлень</div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-card bg-success text-white position-relative">
            <i class="bi bi-currency-dollar stat-icon"></i>
            <div class="stat-value"><?php echo formatMoney($totalStats['total_revenue']); ?></div>
            <div class="stat-label">Загальний дохід</div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-card bg-info text-white position-relative">
            <i class="bi bi-graph-up-arrow stat-icon"></i>
            <div class="stat-value"><?php echo formatMoney($totalProfit); ?></div>
            <div class="stat-label">Прибуток (період)</div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-card bg-secondary text-white position-relative">
            <i class="bi bi-box-seam stat-icon"></i>
            <div class="stat-value"><?php echo $totalProducts; ?></div>
            <div class="stat-label">Товарів / Клієнтів</div>
            <div class="stat-label"><?php echo $totalProducts; ?> / <?php echo $totalCustomers; ?></div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">Динаміка замовлень та доходу</div>
            <div class="card-body">
                <canvas id="analyticsChart" height="250"></canvas>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">Статуси замовлень</div>
            <div class="card-body">
                <canvas id="statusPieChart" height="250"></canvas>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mt-2">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header">Топ постачальники</div>
            <div class="card-body p-0">
                <?php if (count($topSuppliers) > 0): ?>
                <div class="table-container">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Постачальник</th>
                                <th class="text-end">Накладних</th>
                                <th class="text-end">Сума (USD)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($topSuppliers as $s): ?>
                            <tr>
                                <td><?php echo escape($s['supplier_name'] ?: '—'); ?></td>
                                <td class="text-end"><?php echo (int)$s['invoice_count']; ?></td>
                                <td class="text-end"><?php echo formatMoneyForeign($s['total_spent']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="text-center py-4 text-muted"><p class="mb-0">Немає даних</p></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header">Швидкий доступ</div>
            <div class="card-body">
                <div class="row g-2">
                    <div class="col-6">
                        <a href="<?php echo BASE_URL; ?>/modules/orders.php" class="btn btn-outline-primary w-100"><i class="bi bi-cart3"></i> Замовлення</a>
                    </div>
                    <div class="col-6">
                        <a href="<?php echo BASE_URL; ?>/modules/stock.php" class="btn btn-outline-success w-100"><i class="bi bi-boxes"></i> Склад</a>
                    </div>
                    <div class="col-6">
                        <a href="<?php echo BASE_URL; ?>/modules/incoming.php" class="btn btn-outline-warning w-100"><i class="bi bi-receipt"></i> Накладні</a>
                    </div>
                    <div class="col-6">
                        <a href="<?php echo BASE_URL; ?>/modules/pricing.php" class="btn btn-outline-info w-100"><i class="bi bi-currency-exchange"></i> Ціни</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
