<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../_helpers.php';
requireLogin();

$action = $_GET['action'] ?? 'list';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;
$accountFilter = (int)($_GET['account'] ?? 0);
$typeFilter = $_GET['type'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$user = getUserData();

$accounts = $pdo->query("SELECT * FROM erp_cash_accounts WHERE status = 1 ORDER BY name ASC")->fetchAll();

if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST' && isManager()) {
    $accountId = (int)($_POST['account_id'] ?? 0);
    $type = $_POST['type'] ?? 'in';
    $amount = (float)($_POST['amount'] ?? 0);
    $method = $_POST['method'] ?? 'cash';
    $category = trim($_POST['category'] ?? '');
    $date = $_POST['date'] ?? date('Y-m-d');
    $description = trim($_POST['description'] ?? '');
    $invoiceId = (int)($_POST['invoice_id'] ?? 0);

    if ($accountId && $amount > 0) {
        if ($type === 'out' && $invoiceId > 0) {
            $stmt = $pdo->prepare("INSERT INTO erp_payments (invoice_id, amount, method, date, notes, user_id) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$invoiceId, $amount, $method, $date, $description, $user['user_id']]);
        }
        $stmt = $pdo->prepare("INSERT INTO erp_transactions (account_id, type, amount, method, category, date, description, reference_type, reference_id, user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$accountId, $type, $amount, $method, $category, $date, $description, $invoiceId > 0 ? 'invoice' : null, $invoiceId > 0 ? $invoiceId : null, $user['user_id']]);
        flashMessage('success', 'Транзакцію додано');
    } else {
        flashMessage('error', 'Заповніть обов\'язкові поля');
    }
    redirect(BASE_URL . '/modules/finance.php');
}

$where = [];
$params = [];

if ($accountFilter) {
    $where[] = "t.account_id = ?";
    $params[] = $accountFilter;
}
if ($typeFilter) {
    $where[] = "t.type = ?";
    $params[] = $typeFilter;
}
if ($dateFrom) {
    $where[] = "t.date >= ?";
    $params[] = $dateFrom;
}
if ($dateTo) {
    $where[] = "t.date <= ?";
    $params[] = $dateTo;
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countSql = "SELECT COUNT(*) FROM erp_transactions t $whereClause";
$stmt = $pdo->prepare($countSql);
$stmt->execute($params);
$total = $stmt->fetchColumn();
$pagination = paginate($total, $perPage, $page);

$sql = "SELECT t.*, a.name as account_name FROM erp_transactions t LEFT JOIN erp_cash_accounts a ON t.account_id = a.account_id $whereClause ORDER BY t.date DESC, t.date_added DESC LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$transactions = $stmt->fetchAll();

// calculate balances
$balances = [];
foreach ($accounts as $acc) {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN type = 'in' THEN amount ELSE 0 END) - SUM(CASE WHEN type = 'out' THEN amount ELSE 0 END), 0) as balance FROM erp_transactions WHERE account_id = ?");
    $stmt->execute([$acc['account_id']]);
    $row = $stmt->fetch();
    $balances[$acc['account_id']] = (float)$acc['initial_balance'] + (float)$row['balance'];
}

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

$stmt = $pdo->query("SELECT s.supplier_id, s.name,
    COALESCE((SELECT SUM(i.total_local) FROM erp_incoming_invoices i WHERE i.supplier_id = s.supplier_id AND i.status IN ('draft', 'confirmed')), 0) as invoice_total,
    COALESCE((SELECT SUM(p.amount) FROM erp_payments p JOIN erp_incoming_invoices i ON p.invoice_id = i.invoice_id WHERE i.supplier_id = s.supplier_id), 0) as payment_total
    FROM erp_suppliers s WHERE s.status = 1 ORDER BY s.name ASC");
$suppliersDebt = $stmt->fetchAll();

$stmt = $pdo->query("SELECT i.invoice_id, i.invoice_number, s.name as supplier_name, (i.total_local - COALESCE((SELECT SUM(p.amount) FROM erp_payments p WHERE p.invoice_id = i.invoice_id), 0)) as debt_remaining
    FROM erp_incoming_invoices i LEFT JOIN erp_suppliers s ON i.supplier_id = s.supplier_id
    WHERE i.status IN ('draft', 'confirmed')
    HAVING debt_remaining > 0.01
    ORDER BY i.date_added DESC");
$invoicesWithDebt = $stmt->fetchAll();

$methodLabels = ['cash' => 'Готівка', 'card' => 'Картка', 'fop' => 'ФОП', 'invoice' => 'Рахунок', 'transfer' => 'Переказ'];
$typeLabels = ['in' => 'Надходження', 'out' => 'Витрата', 'transfer' => 'Переказ'];

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="bi bi-wallet2"></i> Фінанси</h4>
    <div>
        <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#addIncomeModal"><i class="bi bi-plus-lg"></i> Надходження</button>
        <button class="btn btn-danger btn-sm" data-bs-toggle="modal" data-bs-target="#addExpenseModal"><i class="bi bi-plus-lg"></i> Витрата</button>
    </div>
</div>

<div class="row g-3 mb-3">
    <?php foreach ($accounts as $acc): ?>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <small class="text-muted">
                    <?php if ($acc['type'] === 'cash'): ?><i class="bi bi-cash"></i>
                    <?php elseif ($acc['type'] === 'bank'): ?><i class="bi bi-bank"></i>
                    <?php else: ?><i class="bi bi-person-badge"></i><?php endif; ?>
                    <?php echo escape($acc['name']); ?>
                </small>
                <div class="fw-bold fs-5"><?php echo formatMoney($balances[$acc['account_id']]); ?></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-6">
        <div class="card border-danger">
            <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center py-2">
                <span><i class="bi bi-truck"></i> Борг постачальникам</span>
                <a href="<?php echo BASE_URL; ?>/modules/suppliers.php" class="btn btn-sm btn-outline-light">Деталі</a>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead>
                        <tr><th>Постачальник</th><th class="text-end">Борг (UAH)</th></tr>
                    </thead>
                    <tbody>
                        <?php $totalSupDebt = 0; foreach ($suppliersDebt as $s):
                            $sd = (float)$s['invoice_total'] - (float)$s['payment_total'];
                            $totalSupDebt += $sd;
                            if ($sd <= 0) continue;
                        ?>
                        <tr>
                            <td><?php echo escape($s['name']); ?></td>
                            <td class="text-end text-danger fw-bold"><?php echo formatMoney($sd); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if ($totalSupDebt <= 0): ?>
                        <tr><td colspan="2" class="text-center text-muted py-2">Боргів немає</td></tr>
                        <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr class="fw-bold"><td>Разом</td><td class="text-end text-danger"><?php echo formatMoney($supplierDebt); ?></td></tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card border-success">
            <div class="card-header bg-success text-white d-flex justify-content-between align-items-center py-2">
                <span><i class="bi bi-people"></i> Борг нам (клієнти)</span>
                <a href="<?php echo BASE_URL; ?>/modules/orders.php" class="btn btn-sm btn-outline-light">Деталі</a>
            </div>
            <div class="card-body p-0">
                <div class="text-center py-4">
                    <div class="fs-3 fw-bold <?php echo $customerDebt > 0 ? 'text-success' : 'text-muted'; ?>"><?php echo formatMoney($customerDebt); ?></div>
                    <small class="text-muted">Загальна сума несплачених замовлень</small>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-auto">
                <select name="account" class="form-select form-select-sm">
                    <option value="">Усі рахунки</option>
                    <?php foreach ($accounts as $acc): ?>
                    <option value="<?php echo $acc['account_id']; ?>" <?php echo $accountFilter === (int)$acc['account_id'] ? 'selected' : ''; ?>><?php echo escape($acc['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <select name="type" class="form-select form-select-sm">
                    <option value="">Усі типи</option>
                    <option value="in" <?php echo $typeFilter === 'in' ? 'selected' : ''; ?>>Надходження</option>
                    <option value="out" <?php echo $typeFilter === 'out' ? 'selected' : ''; ?>>Витрати</option>
                </select>
            </div>
            <div class="col-auto">
                <input type="date" name="date_from" class="form-control form-control-sm" value="<?php echo $dateFrom; ?>" placeholder="Від">
            </div>
            <div class="col-auto">
                <input type="date" name="date_to" class="form-control form-control-sm" value="<?php echo $dateTo; ?>" placeholder="До">
            </div>
            <div class="col-auto">
                <button class="btn btn-sm btn-outline-primary"><i class="bi bi-funnel"></i> Фільтр</button>
            </div>
        </form>
    </div>
    <div class="card-body p-0">
        <?php if (count($transactions) > 0): ?>
        <div class="table-container">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Дата</th>
                        <th>Рахунок</th>
                        <th>Метод</th>
                        <th>Категорія</th>
                        <th>Опис</th>
                        <th class="text-end">Сума</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transactions as $t):
                            $refLink = '';
                            if ($t['reference_type'] === 'invoice' && $t['reference_id']) {
                                $stmt2 = $pdo->prepare("SELECT invoice_number FROM erp_incoming_invoices WHERE invoice_id = ?");
                                $stmt2->execute([$t['reference_id']]);
                                $invNum = $stmt2->fetchColumn();
                                $refLink = ' <a href="' . BASE_URL . '/modules/incoming.php?action=view&id=' . $t['reference_id'] . '" class="text-decoration-none"><i class="bi bi-box-arrow-up-right"></i> #' . escape($invNum ?: $t['reference_id']) . '</a>';
                            }
                            ?>
                    <tr>
                        <td><?php echo formatDateShort($t['date']); ?></td>
                        <td><?php echo escape($t['account_name']); ?></td>
                        <td><?php echo $methodLabels[$t['method']] ?? $t['method']; ?></td>
                        <td><?php echo escape($t['category'] ?: '-'); ?></td>
                        <td><?php echo escape($t['description'] ?: '-'); ?> <?php echo $refLink; ?></td>
                        <td class="text-end fw-bold <?php echo $t['type'] === 'in' ? 'text-success' : 'text-danger'; ?>">
                            <?php echo $t['type'] === 'in' ? '+' : '-'; ?><?php echo formatMoney($t['amount']); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="text-center py-5 text-muted">
            <i class="bi bi-wallet2" style="font-size:3rem;"></i>
            <p class="mt-3 mb-0">Немає транзакцій</p>
        </div>
        <?php endif; ?>
    </div>
    <?php if ($pagination['total_pages'] > 1): ?>
    <div class="card-footer"><?php echo renderPagination(BASE_URL . '/modules/finance.php?', $pagination); ?></div>
    <?php endif; ?>
</div>

<!-- Add Income Modal -->
<div class="modal fade" id="addIncomeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" action="?action=create">
                <div class="modal-header">
                    <h5 class="modal-title">Додати надходження</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="type" value="in">
                    <div class="mb-3">
                        <label class="form-label">Рахунок *</label>
                        <select name="account_id" class="form-select" required>
                            <option value="">-- Виберіть --</option>
                            <?php foreach ($accounts as $acc): ?>
                            <option value="<?php echo $acc['account_id']; ?>"><?php echo escape($acc['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Сума (UAH) *</label>
                        <input type="number" name="amount" class="form-control" step="0.01" min="0.01" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Метод</label>
                        <select name="method" class="form-select">
                            <option value="cash">Готівка</option>
                            <option value="card">Картка</option>
                            <option value="fop">ФОП</option>
                            <option value="invoice">Рахунок</option>
                            <option value="transfer">Переказ</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Категорія</label>
                        <select name="category" class="form-select">
                            <option value="">-- Без категорії --</option>
                            <option value="Продажі">Продажі</option>
                            <option value="Повернення">Повернення</option>
                            <option value="Поповнення">Поповнення</option>
                            <option value="Інше">Інше</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Дата</label>
                        <input type="date" name="date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Опис</label>
                        <textarea name="description" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Скасувати</button>
                    <button type="submit" class="btn btn-success">Додати</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Expense Modal -->
<div class="modal fade" id="addExpenseModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" action="?action=create">
                <div class="modal-header">
                    <h5 class="modal-title">Додати витрату</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="type" value="out">
                    <div class="mb-3">
                        <label class="form-label">Рахунок *</label>
                        <select name="account_id" class="form-select" required>
                            <option value="">-- Виберіть --</option>
                            <?php foreach ($accounts as $acc): ?>
                            <option value="<?php echo $acc['account_id']; ?>"><?php echo escape($acc['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Сума (UAH) *</label>
                        <input type="number" name="amount" class="form-control" step="0.01" min="0.01" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Метод</label>
                        <select name="method" class="form-select">
                            <option value="cash">Готівка</option>
                            <option value="card">Картка</option>
                            <option value="fop">ФОП</option>
                            <option value="invoice">Рахунок</option>
                            <option value="transfer">Переказ</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Категорія</label>
                        <select name="category" class="form-select">
                            <option value="">-- Без категорії --</option>
                            <option value="Закупівля">Закупівля</option>
                            <option value="Оренда">Оренда</option>
                            <option value="Комунальні">Комунальні</option>
                            <option value="Зарплата">Зарплата</option>
                            <option value="Податки">Податки</option>
                            <option value="Транспорт">Транспорт</option>
                            <option value="Реклама">Реклама</option>
                            <option value="Інше">Інше</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Дата</label>
                        <input type="date" name="date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Прив'язати до накладної (необов'язково)</label>
                        <select name="invoice_id" class="form-select">
                            <option value="">— Без накладної —</option>
                            <?php foreach ($invoicesWithDebt as $inv): ?>
                            <option value="<?php echo $inv['invoice_id']; ?>">#<?php echo escape($inv['invoice_number']); ?> — <?php echo escape($inv['supplier_name']); ?> (борг: <?php echo formatMoney($inv['debt_remaining']); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Опис</label>
                        <textarea name="description" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Скасувати</button>
                    <button type="submit" class="btn btn-danger">Додати</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
