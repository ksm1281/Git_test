<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../_helpers.php';
requireLogin();

$action = $_GET['action'] ?? 'list';
$invoiceId = (int)($_GET['id'] ?? 0);
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$user = getUserData();

if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $supplierId = (int)($_POST['supplier_id'] ?? 0);
    $invoiceNumber = trim($_POST['invoice_number'] ?? '');
    $date = $_POST['date'] ?? date('Y-m-d');
    $currency = $_POST['currency'] ?? 'USD';
    $rate = (float)($_POST['exchange_rate'] ?? getCurrentRate($pdo));
    $notes = trim($_POST['notes'] ?? '');

    if (!$supplierId || empty($invoiceNumber)) {
        flashMessage('error', 'Заповніть обов\'язкові поля');
        redirect(BASE_URL . '/modules/incoming.php?action=create');
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("INSERT INTO erp_incoming_invoices (invoice_number, supplier_id, date, currency, exchange_rate, notes, user_id, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'draft')");
        $stmt->execute([$invoiceNumber, $supplierId, $date, $currency, $rate, $notes, $user['user_id']]);
        $invoiceId = $pdo->lastInsertId();

        $productIds = $_POST['product_id'] ?? [];
        $quantities = $_POST['quantity'] ?? [];
        $pricesForeign = $_POST['price_foreign'] ?? [];

        $totalForeign = 0;
        $totalLocal = 0;

        for ($i = 0; $i < count($productIds); $i++) {
            $pid = (int)$productIds[$i];
            $qty = (float)($quantities[$i] ?? 0);
            $pf = (float)($pricesForeign[$i] ?? 0);
            if ($pid <= 0 || $qty <= 0) continue;

            $tf = $qty * $pf;
            $tl = $tf * $rate;
            $totalForeign += $tf;
            $totalLocal += $tl;

            $stmt = $pdo->prepare("INSERT INTO erp_invoice_items (invoice_id, product_id, quantity, price_foreign, price_local, total_foreign, total_local) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$invoiceId, $pid, $qty, $pf, $pf * $rate, $tf, $tl]);

            $stmt = $pdo->prepare("INSERT INTO erp_stock_moves (product_id, type, quantity, cost_price, reference_type, reference_id, user_id, notes) VALUES (?, 'in', ?, ?, 'invoice', ?, ?, ?)");
            $stmt->execute([$pid, $qty, $pf, $invoiceId, $user['user_id'], 'Накладна ' . $invoiceNumber]);
        }

        $stmt = $pdo->prepare("UPDATE erp_incoming_invoices SET total_foreign = ?, total_local = ? WHERE invoice_id = ?");
        $stmt->execute([$totalForeign, $totalLocal, $invoiceId]);

        $pdo->commit();
        flashMessage('success', 'Накладну #' . $invoiceNumber . ' створено');
        redirect(BASE_URL . '/modules/incoming.php');
    } catch (Exception $e) {
        $pdo->rollBack();
        flashMessage('error', 'Помилка: ' . $e->getMessage());
        redirect(BASE_URL . '/modules/incoming.php?action=create');
    }
}

if ($action === 'confirm' && $invoiceId) {
    $pdo->prepare("UPDATE erp_incoming_invoices SET status = 'confirmed' WHERE invoice_id = ?")->execute([$invoiceId]);
    flashMessage('success', 'Накладну підтверджено');
    redirect(BASE_URL . '/modules/incoming.php');
}

if ($action === 'cancel' && $invoiceId) {
    $pdo->prepare("UPDATE erp_incoming_invoices SET status = 'cancelled' WHERE invoice_id = ?")->execute([$invoiceId]);
    flashMessage('success', 'Накладну скасовано');
    redirect(BASE_URL . '/modules/incoming.php');
}

if ($action === 'view' && $invoiceId) {
    $stmt = $pdo->prepare("SELECT i.*, s.name as supplier_name FROM erp_incoming_invoices i LEFT JOIN erp_suppliers s ON i.supplier_id = s.supplier_id WHERE i.invoice_id = ?");
    $stmt->execute([$invoiceId]);
    $invoice = $stmt->fetch();
    if (!$invoice) { flashMessage('error', 'Накладну не знайдено'); redirect(BASE_URL . '/modules/incoming.php'); }

    $stmt = $pdo->prepare("SELECT ii.*, p.name as product_name FROM erp_invoice_items ii LEFT JOIN erp_products p ON ii.product_id = p.product_id WHERE ii.invoice_id = ?");
    $stmt->execute([$invoiceId]);
    $items = $stmt->fetchAll();

    include __DIR__ . '/../includes/header.php';
    ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0"><i class="bi bi-receipt"></i> Накладна #<?php echo escape($invoice['invoice_number']); ?></h4>
        <div>
            <a href="<?php echo BASE_URL; ?>/modules/incoming.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Назад</a>
            <?php if ($invoice['status'] === 'draft'): ?>
            <a href="<?php echo BASE_URL; ?>/modules/incoming.php?action=confirm&id=<?php echo $invoiceId; ?>" class="btn btn-success btn-sm" onclick="return confirm('Підтвердити накладну?')"><i class="bi bi-check-lg"></i> Підтвердити</a>
            <a href="<?php echo BASE_URL; ?>/modules/incoming.php?action=cancel&id=<?php echo $invoiceId; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Скасувати накладну?')"><i class="bi bi-x-lg"></i> Скасувати</a>
            <?php endif; ?>
        </div>
    </div>
    <div class="row g-3">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">Деталі</div>
                <div class="card-body">
                    <table class="table table-sm mb-0">
                        <tr><td>Постачальник</td><td class="fw-bold"><?php echo escape($invoice['supplier_name']); ?></td></tr>
                        <tr><td>Дата</td><td><?php echo formatDateShort($invoice['date']); ?></td></tr>
                        <tr><td>Валюта</td><td><?php echo escape($invoice['currency']); ?></td></tr>
                        <tr><td>Курс</td><td><?php echo number_format($invoice['exchange_rate'], 4); ?></td></tr>
                        <tr><td>Статус</td><td><?php echo getStatusBadge($invoice['status']); ?></td></tr>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">Сума</div>
                <div class="card-body">
                    <table class="table table-sm mb-0">
                        <tr><td>Сума (USD)</td><td class="fw-bold"><?php echo formatMoneyForeign($invoice['total_foreign']); ?></td></tr>
                        <tr><td>Сума (UAH)</td><td class="fw-bold"><?php echo formatMoney($invoice['total_local']); ?></td></tr>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="card mt-3">
        <div class="card-header">Товари</div>
        <div class="card-body p-0">
            <div class="table-container">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr><th>Товар</th><th>К-сть</th><th class="text-end">Ціна (USD)</th><th class="text-end">Ціна (UAH)</th><th class="text-end">Сума (USD)</th><th class="text-end">Сума (UAH)</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                        <tr>
                            <td><?php echo escape($item['product_name'] ?: 'ID: ' . $item['product_id']); ?></td>
                            <td><?php echo (float)$item['quantity']; ?></td>
                            <td class="text-end"><?php echo formatMoneyForeign($item['price_foreign']); ?></td>
                            <td class="text-end"><?php echo formatMoney($item['price_local']); ?></td>
                            <td class="text-end"><?php echo formatMoneyForeign($item['total_foreign']); ?></td>
                            <td class="text-end"><?php echo formatMoney($item['total_local']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="fw-bold">
                            <td>Разом</td>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td class="text-end"><?php echo formatMoneyForeign($invoice['total_foreign']); ?></td>
                            <td class="text-end"><?php echo formatMoney($invoice['total_local']); ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
    <?php
    include __DIR__ . '/../includes/footer.php';
    exit;
}

if ($action === 'create') {
    $stmt = $pdo->query("SELECT * FROM erp_suppliers WHERE status = 1 ORDER BY name ASC");
    $suppliers = $stmt->fetchAll();
    $stmt = $pdo->query("SELECT product_id, name, model, sku FROM erp_products WHERE status = 1 ORDER BY name ASC LIMIT 500");
    $products = $stmt->fetchAll();
    $rate = getCurrentRate($pdo);
    include __DIR__ . '/../includes/header.php';
    ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0"><i class="bi bi-receipt"></i> Нова прихідна накладна</h4>
        <a href="<?php echo BASE_URL; ?>/modules/incoming.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x-lg"></i> Скасувати</a>
    </div>
    <div class="card">
        <div class="card-header">Дані накладної</div>
        <div class="card-body">
            <form method="post" id="invoiceForm">
                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <label class="form-label required">Номер накладної</label>
                        <input type="text" name="invoice_number" class="form-control" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label required">Постачальник</label>
                        <select name="supplier_id" class="form-select" required>
                            <option value="">-- Виберіть --</option>
                            <?php foreach ($suppliers as $s): ?>
                            <option value="<?php echo $s['supplier_id']; ?>"><?php echo escape($s['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Дата</label>
                        <input type="date" name="date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Валюта</label>
                        <select name="currency" class="form-select" id="currency">
                            <option value="USD">USD</option>
                            <option value="EUR">EUR</option>
                            <option value="UAH">UAH</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Курс</label>
                        <input type="number" name="exchange_rate" class="form-control" step="0.0001" value="<?php echo $rate; ?>">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Примітки</label>
                    <textarea name="notes" class="form-control" rows="2"></textarea>
                </div>

                <h6 class="fw-bold mb-2">Товари</h6>
                <div class="table-container">
                    <table class="table table-bordered" id="itemsTable">
                        <thead>
                            <tr>
                                <th style="width:40%;">Товар</th>
                                <th style="width:15%;">Кількість</th>
                                <th style="width:20%;">Ціна (USD)</th>
                                <th style="width:20%;">Сума (USD)</th>
                                <th style="width:5%;"></th>
                            </tr>
                        </thead>
                        <tbody id="itemsBody">
                            <tr>
                                <td>
                                    <select name="product_id[]" class="form-select" required>
                                        <option value="">-- Виберіть --</option>
                                        <?php foreach ($products as $p): ?>
                                        <option value="<?php echo $p['product_id']; ?>"><?php echo escape($p['name'] ?: 'ID: ' . $p['product_id']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td><input type="number" name="quantity[]" class="form-control" step="0.01" min="0.01" required></td>
                                <td><input type="number" name="price_foreign[]" class="form-control price-foreign" step="0.0001" min="0" required></td>
                                <td><span class="line-total fw-bold">0.00</span></td>
                                <td><button type="button" class="btn btn-outline-danger btn-sm remove-item"><i class="bi bi-trash"></i></button></td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td><button type="button" class="btn btn-sm btn-outline-primary" id="addItem"><i class="bi bi-plus-lg"></i> Додати рядок</button></td>
                                <td colspan="2" class="text-end fw-bold">Всього (USD):</td>
                                <td><span id="grandTotal" class="fw-bold">0.00</span></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Створити накладну</button>
            </form>
        </div>
    </div>
    <script>
    document.getElementById('addItem')?.addEventListener('click', function() {
        const tbody = document.getElementById('itemsBody');
        const firstRow = tbody.querySelector('tr');
        const newRow = firstRow.cloneNode(true);
        newRow.querySelectorAll('input').forEach(i => i.value = '');
        newRow.querySelector('.line-total').textContent = '0.00';
        tbody.appendChild(newRow);
    });
    document.addEventListener('click', function(e) {
        if (e.target.closest('.remove-item')) {
            const tbody = document.getElementById('itemsBody');
            if (tbody.querySelectorAll('tr').length > 1) {
                e.target.closest('tr').remove();
                calcTotal();
            }
        }
    });
    document.addEventListener('input', function(e) {
        if (e.target.classList.contains('price-foreign') || e.target.name.includes('quantity')) {
            const row = e.target.closest('tr');
            if (row) {
                const qty = parseFloat(row.querySelector('[name*="quantity"]').value) || 0;
                const price = parseFloat(row.querySelector('.price-foreign').value) || 0;
                row.querySelector('.line-total').textContent = (qty * price).toFixed(2);
                calcTotal();
            }
        }
    });
    function calcTotal() {
        let total = 0;
        document.querySelectorAll('.line-total').forEach(function(el) {
            total += parseFloat(el.textContent) || 0;
        });
        document.getElementById('grandTotal').textContent = total.toFixed(2);
    }
    </script>
    <?php
    include __DIR__ . '/../includes/footer.php';
    exit;
}

$countSql = "SELECT COUNT(*) FROM erp_incoming_invoices";
$stmt = $pdo->query($countSql);
$total = $stmt->fetchColumn();
$pagination = paginate($total, $perPage, $page);

$stmt = $pdo->query("SELECT i.*, s.name as supplier_name FROM erp_incoming_invoices i LEFT JOIN erp_suppliers s ON i.supplier_id = s.supplier_id ORDER BY i.date_added DESC LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}");
$invoices = $stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="bi bi-receipt"></i> Прихідні накладні</h4>
    <a href="<?php echo BASE_URL; ?>/modules/incoming.php?action=create" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> Нова накладна</a>
</div>

<div class="card">
    <div class="card-body p-0">
        <?php if (count($invoices) > 0): ?>
        <div class="table-container">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Номер</th>
                        <th>Постачальник</th>
                        <th>Дата</th>
                        <th class="text-end">Сума (USD)</th>
                        <th class="text-end">Сума (UAH)</th>
                        <th>Статус</th>
                        <th class="text-center">Дії</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($invoices as $inv): ?>
                    <tr>
                        <td><?php echo (int)$inv['invoice_id']; ?></td>
                        <td><a href="<?php echo BASE_URL; ?>/modules/incoming.php?action=view&id=<?php echo $inv['invoice_id']; ?>"><?php echo escape($inv['invoice_number']); ?></a></td>
                        <td><?php echo escape($inv['supplier_name'] ?: '-'); ?></td>
                        <td><?php echo formatDateShort($inv['date']); ?></td>
                        <td class="text-end"><?php echo formatMoneyForeign($inv['total_foreign']); ?></td>
                        <td class="text-end"><?php echo formatMoney($inv['total_local']); ?></td>
                        <td><?php echo getStatusBadge($inv['status']); ?></td>
                        <td class="text-center">
                            <a href="<?php echo BASE_URL; ?>/modules/incoming.php?action=view&id=<?php echo $inv['invoice_id']; ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="text-center py-5 text-muted">
            <i class="bi bi-receipt" style="font-size:3rem;"></i>
            <p class="mt-3 mb-0">Немає прихідних накладних</p>
            <a href="<?php echo BASE_URL; ?>/modules/incoming.php?action=create" class="btn btn-primary mt-3">Створити першу</a>
        </div>
        <?php endif; ?>
    </div>
    <?php if ($pagination['total_pages'] > 1): ?>
    <div class="card-footer"><?php echo renderPagination(BASE_URL . '/modules/incoming.php?', $pagination); ?></div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
