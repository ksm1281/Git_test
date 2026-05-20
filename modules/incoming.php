<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../_helpers.php';
requireLogin();

$action = $_GET['action'] ?? 'list';
$invoiceId = (int)($_GET['id'] ?? 0);
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$user = getUserData();

if ($action === 'import' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $supplierId = (int)($_POST['supplier_id'] ?? 0);
    $invoiceNumber = trim($_POST['invoice_number'] ?? '');
    $date = $_POST['date'] ?? date('Y-m-d');
    $currency = $_POST['currency'] ?? 'UAH';
    $rate = $currency === 'UAH' ? 1 : (float)($_POST['exchange_rate'] ?? getCurrentRate($pdo, $currency));
    $notes = trim($_POST['notes'] ?? '');
    $colName = (int)($_POST['col_name'] ?? 1);
    $colSku = (int)($_POST['col_sku'] ?? 0);
    $colQty = (int)($_POST['col_qty'] ?? 2);
    $colPrice = (int)($_POST['col_price'] ?? 3);
    $headerRows = (int)($_POST['header_rows'] ?? 1);

    if (!$supplierId || empty($invoiceNumber) || empty($_FILES['import_file'])) {
        flashMessage('error', 'Заповніть обов\'язкові поля та завантажте файл');
        redirect(BASE_URL . '/modules/incoming.php?action=create');
    }

    $file = $_FILES['import_file'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['csv', 'xlsx'])) {
        flashMessage('error', 'Підтримуються тільки CSV та XLSX файли');
        redirect(BASE_URL . '/modules/incoming.php?action=create');
    }

    $rows = [];
    if ($ext === 'csv') {
        $handle = fopen($file['tmp_name'], 'r');
        if ($handle) {
            $rowNum = 0;
            while (($data = fgetcsv($handle)) !== false) {
                $rowNum++;
                if ($rowNum <= $headerRows) continue;
                $name = trim($data[$colName - 1] ?? '');
                $qty = (float)str_replace(',', '.', trim($data[$colQty - 1] ?? '0'));
                $price = (float)str_replace(',', '.', trim($data[$colPrice - 1] ?? '0'));
                $sku = $colSku > 0 ? trim($data[$colSku - 1] ?? '') : '';
                if ($name && $qty > 0) $rows[] = ['name' => $name, 'sku' => $sku, 'qty' => $qty, 'price' => $price];
            }
            fclose($handle);
        }
    } elseif ($ext === 'xlsx' && class_exists('ZipArchive')) {
        $zip = new ZipArchive;
        if ($zip->open($file['tmp_name']) === true) {
            $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
            $shared = $zip->getFromName('xl/sharedStrings.xml');
            $zip->close();

            if ($sheet) {
                $strings = [];
                if ($shared) {
                    $sxml = simplexml_load_string($shared);
                    foreach ($sxml->si as $si) $strings[] = (string)$si->t;
                }

                $xml = simplexml_load_string($sheet);
                $ns = $xml->getNamespaces(true);
                $data = $xml->sheetData->row;
                $rowNum = 0;
                foreach ($data as $row) {
                    $rowNum++;
                    if ($rowNum <= $headerRows) continue;
                    $vals = [];
                    foreach ($row->c as $c) {
                        $ref = (string)$c['r'];
                        $colIdx = preg_replace('/[0-9]/', '', $ref);
                        $colLetter = ord(strtoupper($colIdx)) - ord('A') + 1;
                        $type = (string)$c['t'];
                        $v = (string)$c->v;
                        if ($type === 's' && isset($strings[(int)$v])) $v = $strings[(int)$v];
                        $v = str_replace(',', '.', trim($v));
                        $vals[$colLetter] = $v;
                    }
                    $name = $vals[$colName] ?? '';
                    $qty = (float)($vals[$colQty] ?? 0);
                    $price = (float)($vals[$colPrice] ?? 0);
                    $sku = $colSku > 0 ? ($vals[$colSku] ?? '') : '';
                    if ($name && $qty > 0) $rows[] = ['name' => $name, 'sku' => $sku, 'qty' => $qty, 'price' => $price];
                }
            }
        }
    }

    if (empty($rows)) {
        flashMessage('error', 'Не вдалося прочитати дані з файлу. Перевірте формат і налаштування колонок.');
        redirect(BASE_URL . '/modules/incoming.php?action=create');
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("INSERT INTO erp_incoming_invoices (invoice_number, supplier_id, date, currency, exchange_rate, notes, user_id, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'draft')");
        $stmt->execute([$invoiceNumber, $supplierId, $date, $currency, $rate, $notes, $user['user_id']]);
        $invoiceId = $pdo->lastInsertId();

        $totalForeign = 0;
        $totalLocal = 0;
        $matched = 0;
        $notFound = [];

        foreach ($rows as $r) {
            $pid = 0;
            if ($r['sku']) {
                $stmt = $pdo->prepare("SELECT product_id FROM erp_products WHERE sku=? LIMIT 1");
                $stmt->execute([$r['sku']]);
                $pid = (int)$stmt->fetchColumn();
            }
            if (!$pid) {
                $stmt = $pdo->prepare("SELECT product_id FROM erp_products WHERE name=? LIMIT 1");
                $stmt->execute([$r['name']]);
                $pid = (int)$stmt->fetchColumn();
            }
            if (!$pid) {
                $stmt = $pdo->prepare("SELECT product_id FROM erp_products WHERE name LIKE ? LIMIT 1");
                $stmt->execute(['%' . $r['name'] . '%']);
                $pid = (int)$stmt->fetchColumn();
            }
            if (!$pid) {
                $notFound[] = $r['name'];
                continue;
            }

            $tf = $r['qty'] * $r['price'];
            $tl = $tf * $rate;
            $totalForeign += $tf;
            $totalLocal += $tl;

            $stmt = $pdo->prepare("INSERT INTO erp_invoice_items (invoice_id, product_id, quantity, price_foreign, price_local, total_foreign, total_local) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$invoiceId, $pid, $r['qty'], $r['price'], $r['price'] * $rate, $tf, $tl]);
            $stmt = $pdo->prepare("INSERT INTO erp_stock_moves (product_id, type, quantity, cost_price, reference_type, reference_id, user_id, notes) VALUES (?, 'in', ?, ?, 'invoice', ?, ?, ?)");
            $stmt->execute([$pid, $r['qty'], $r['price'], $invoiceId, $user['user_id'], 'Імпорт: ' . $invoiceNumber]);
            $matched++;
        }

        $stmt = $pdo->prepare("UPDATE erp_incoming_invoices SET total_foreign = ?, total_local = ? WHERE invoice_id = ?");
        $stmt->execute([$totalForeign, $totalLocal, $invoiceId]);
        $pdo->commit();

        $msg = 'Накладну #' . $invoiceNumber . ' створено. Опрацьовано ' . $matched . ' з ' . count($rows) . ' товарів.';
        if ($notFound) $msg .= ' Не знайдено: ' . implode(', ', array_slice($notFound, 0, 10)) . (count($notFound) > 10 ? '...' : '');
        flashMessage('success', $msg);
        redirect(BASE_URL . '/modules/incoming.php?action=view&id=' . $invoiceId);
    } catch (Exception $e) {
        $pdo->rollBack();
        flashMessage('error', 'Помилка: ' . $e->getMessage());
        redirect(BASE_URL . '/modules/incoming.php?action=create');
    }
}

if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $supplierId = (int)($_POST['supplier_id'] ?? 0);
    $invoiceNumber = trim($_POST['invoice_number'] ?? '');
    $date = $_POST['date'] ?? date('Y-m-d');
    $currency = $_POST['currency'] ?? 'UAH';
    $rate = $currency === 'UAH' ? 1 : (float)($_POST['exchange_rate'] ?? getCurrentRate($pdo, $currency));
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

if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST' && $invoiceId) {
    $stmt = $pdo->prepare("SELECT status FROM erp_incoming_invoices WHERE invoice_id = ?");
    $stmt->execute([$invoiceId]);
    $inv = $stmt->fetch();
    if (!$inv || $inv['status'] !== 'draft') {
        flashMessage('error', 'Можна редагувати тільки чернетки');
        redirect(BASE_URL . '/modules/incoming.php');
    }

    $supplierId = (int)($_POST['supplier_id'] ?? 0);
    $invoiceNumber = trim($_POST['invoice_number'] ?? '');
    $date = $_POST['date'] ?? date('Y-m-d');
    $currency = $_POST['currency'] ?? 'UAH';
    $rate = $currency === 'UAH' ? 1 : (float)($_POST['exchange_rate'] ?? getCurrentRate($pdo, $currency));
    $notes = trim($_POST['notes'] ?? '');

    if (!$supplierId || empty($invoiceNumber)) {
        flashMessage('error', 'Заповніть обов\'язкові поля');
        redirect(BASE_URL . '/modules/incoming.php?action=edit&id=' . $invoiceId);
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE erp_incoming_invoices SET invoice_number=?, supplier_id=?, date=?, currency=?, exchange_rate=?, notes=? WHERE invoice_id=?")
            ->execute([$invoiceNumber, $supplierId, $date, $currency, $rate, $notes, $invoiceId]);

        $pdo->prepare("DELETE FROM erp_invoice_items WHERE invoice_id = ?")->execute([$invoiceId]);
        $pdo->prepare("DELETE FROM erp_stock_moves WHERE reference_type = 'invoice' AND reference_id = ?")->execute([$invoiceId]);

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

        $pdo->prepare("UPDATE erp_incoming_invoices SET total_foreign = ?, total_local = ? WHERE invoice_id = ?")
            ->execute([$totalForeign, $totalLocal, $invoiceId]);

        $pdo->commit();
        flashMessage('success', 'Накладну #' . $invoiceNumber . ' оновлено');
        redirect(BASE_URL . '/modules/incoming.php');
    } catch (Exception $e) {
        $pdo->rollBack();
        flashMessage('error', 'Помилка: ' . $e->getMessage());
        redirect(BASE_URL . '/modules/incoming.php?action=edit&id=' . $invoiceId);
    }
}

if ($action === 'pay' && $_SERVER['REQUEST_METHOD'] === 'POST' && $invoiceId && isManager()) {
    $amount = (float)($_POST['amount'] ?? 0);
    $method = $_POST['method'] ?? 'cash';
    $date = $_POST['date'] ?? date('Y-m-d');
    $notes = trim($_POST['notes'] ?? '');

    if ($amount > 0 && in_array($method, ['cash', 'card', 'fop', 'invoice'])) {
        $stmt = $pdo->prepare("INSERT INTO erp_payments (invoice_id, amount, method, date, notes, user_id) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$invoiceId, $amount, $method, $date, $notes, $user['user_id']]);
        flashMessage('success', 'Платіж на ' . formatMoney($amount) . ' зареєстровано');
    } else {
        flashMessage('error', 'Некоректна сума або метод оплати');
    }
    redirect(BASE_URL . '/modules/incoming.php?action=view&id=' . $invoiceId);
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
            <a href="<?php echo BASE_URL; ?>/modules/incoming.php?action=edit&id=<?php echo $invoiceId; ?>" class="btn btn-warning btn-sm"><i class="bi bi-pencil"></i> Редагувати</a>
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
                        <tr><td>Сума (<?php echo escape($invoice['currency']); ?>)</td><td class="fw-bold"><?php echo formatMoneyForeign($invoice['total_foreign'], $invoice['currency']); ?></td></tr>
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
                        <tr><th>Товар</th><th>К-сть</th><th class="text-end">Ціна (<?php echo escape($invoice['currency']); ?>)</th><th class="text-end">Ціна (UAH)</th><th class="text-end">Сума (<?php echo escape($invoice['currency']); ?>)</th><th class="text-end">Сума (UAH)</th></tr>
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
    $stmt = $pdo->prepare("SELECT * FROM erp_payments WHERE invoice_id = ? ORDER BY date_added ASC");
    $stmt->execute([$invoiceId]);
    $payments = $stmt->fetchAll();
    $totalPaid = 0;
    foreach ($payments as $pmt) { $totalPaid += (float)$pmt['amount']; }
    $balance = (float)$invoice['total_local'] - $totalPaid;
    $methodLabels = ['cash' => 'Готівка', 'card' => 'Картка', 'fop' => 'ФОП', 'invoice' => 'Рахунок'];
    ?>
    <div class="row mt-3 g-3">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">Оплати постачальнику</div>
                <div class="card-body p-0">
                    <?php if (count($payments) > 0): ?>
                    <table class="table table-sm mb-0">
                        <thead>
                            <tr><th>Дата</th><th>Метод</th><th class="text-end">Сума</th><th>Примітка</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payments as $pmt): ?>
                            <tr>
                                <td><?php echo formatDateShort($pmt['date']); ?></td>
                                <td><?php echo $methodLabels[$pmt['method']] ?? $pmt['method']; ?></td>
                                <td class="text-end fw-bold text-danger"><?php echo formatMoney($pmt['amount']); ?></td>
                                <td><?php echo escape($pmt['notes'] ?: '-'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="fw-bold"><td colspan="2">Сплачено</td><td class="text-end text-danger"><?php echo formatMoney($totalPaid); ?></td><td></td></tr>
                            <tr class="fw-bold <?php echo $balance > 0 ? 'text-warning' : 'text-success'; ?>"><td colspan="2">Залишок</td><td class="text-end"><?php echo formatMoney($balance); ?></td><td></td></tr>
                        </tfoot>
                    </table>
                    <?php else: ?>
                    <div class="text-center py-3 text-muted">Оплат ще немає</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">Додати оплату</div>
                <div class="card-body">
                    <form method="post" action="?action=pay&id=<?php echo $invoiceId; ?>">
                        <div class="row g-2">
                            <div class="col-md-4">
                                <label class="form-label">Сума (UAH)</label>
                                <input type="number" name="amount" class="form-control" step="0.01" min="0.01" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Метод</label>
                                <select name="method" class="form-select">
                                    <option value="cash">Готівка</option>
                                    <option value="card">Картка</option>
                                    <option value="fop">ФОП</option>
                                    <option value="invoice">Рахунок</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Дата</label>
                                <input type="date" name="date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Примітка</label>
                                <input type="text" name="notes" class="form-control" placeholder="Опис платежу">
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn btn-danger btn-sm"><i class="bi bi-cash"></i> Додати платіж</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php
    include __DIR__ . '/../includes/footer.php';
    exit;
}

if ($action === 'create' || $action === 'edit') {
    $stmt = $pdo->query("SELECT * FROM erp_suppliers WHERE status = 1 ORDER BY name ASC");
    $suppliers = $stmt->fetchAll();
    $stmt = $pdo->query("SELECT product_id, name, model, sku FROM erp_products WHERE status = 1 ORDER BY name ASC LIMIT 500");
    $products = $stmt->fetchAll();

    $isEdit = ($action === 'edit' && $invoiceId);
    $invoice = [];
    $items = [];
    $rate = getCurrentRate($pdo, 'EUR');

    if ($isEdit) {
        $stmt = $pdo->prepare("SELECT * FROM erp_incoming_invoices WHERE invoice_id = ? AND status = 'draft'");
        $stmt->execute([$invoiceId]);
        $invoice = $stmt->fetch();
        if (!$invoice) {
            flashMessage('error', 'Накладну не знайдено або вона не є чернеткою');
            redirect(BASE_URL . '/modules/incoming.php');
        }
        $stmt = $pdo->prepare("SELECT * FROM erp_invoice_items WHERE invoice_id = ?");
        $stmt->execute([$invoiceId]);
        $items = $stmt->fetchAll();
        $rate = $invoice['currency'] === 'UAH' ? 1 : $invoice['exchange_rate'];
    }

    $formAction = $isEdit ? 'update&id=' . $invoiceId : 'create';
    $title = $isEdit ? 'Редагування накладної #' . escape($invoice['invoice_number']) : 'Нова прихідна накладна';
    $submitLabel = $isEdit ? 'Зберегти зміни' : 'Створити накладну';

    include __DIR__ . '/../includes/header.php';
    ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0"><i class="bi bi-receipt"></i> <?php echo $title; ?></h4>
        <a href="<?php echo BASE_URL; ?>/modules/incoming.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x-lg"></i> Скасувати</a>
    </div>

    <?php if (!$isEdit): ?>
    <div class="card mb-3">
        <div class="card-header" data-bs-toggle="collapse" data-bs-target="#importSection" style="cursor:pointer;">
            <i class="bi bi-file-earmark-spreadsheet"></i> Імпорт з Excel/CSV <i class="bi bi-chevron-down float-end"></i>
        </div>
        <div class="collapse" id="importSection">
            <div class="card-body">
                <form method="post" action="?action=import" enctype="multipart/form-data">
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
                            <select name="currency" class="form-select">
                                <option value="UAH" selected>UAH</option>
                                <option value="EUR">EUR</option>
                                <option value="USD">USD</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Курс</label>
                            <input type="number" name="exchange_rate" class="form-control" step="0.0001" value="<?php echo $rate; ?>">
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label required">Файл (CSV або XLSX)</label>
                            <input type="file" name="import_file" class="form-control" accept=".csv,.xlsx" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Рядків заголовка</label>
                            <input type="number" name="header_rows" class="form-control" value="1" min="0">
                        </div>
                    </div>
                    <div class="row g-3 mb-3 p-3 bg-light rounded">
                        <div class="col-12"><small class="fw-bold">Налаштування колонок</small></div>
                        <div class="col-md-3">
                            <label class="form-label">Назва товару</label>
                            <input type="number" name="col_name" class="form-control" value="1" min="1">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">SKU/Артикул (0 — пропустити)</label>
                            <input type="number" name="col_sku" class="form-control" value="0" min="0">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Кількість</label>
                            <input type="number" name="col_qty" class="form-control" value="2" min="1">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Ціна</label>
                            <input type="number" name="col_price" class="form-control" value="3" min="1">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Примітки</label>
                        <textarea name="notes" class="form-control" rows="1"></textarea>
                    </div>
                    <button type="submit" class="btn btn-success"><i class="bi bi-upload"></i> Імпортувати та створити</button>
                    <small class="text-muted ms-3">Товари шукаються за артикулом, потім за назвою. Нерозпізнані пропускаються.</small>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header">Дані накладної</div>
        <div class="card-body">
            <form method="post" action="?action=<?php echo $formAction; ?>" id="invoiceForm">
                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <label class="form-label required">Номер накладної</label>
                        <input type="text" name="invoice_number" class="form-control" required value="<?php echo $isEdit ? escape($invoice['invoice_number']) : ''; ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label required">Постачальник</label>
                        <select name="supplier_id" class="form-select" required>
                            <option value="">-- Виберіть --</option>
                            <?php foreach ($suppliers as $s): ?>
                            <option value="<?php echo $s['supplier_id']; ?>" <?php echo $isEdit && $invoice['supplier_id'] == $s['supplier_id'] ? 'selected' : ''; ?>><?php echo escape($s['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Дата</label>
                        <input type="date" name="date" class="form-control" value="<?php echo $isEdit ? $invoice['date'] : date('Y-m-d'); ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Валюта</label>
                        <select name="currency" class="form-select" id="currency">
                            <option value="UAH" <?php echo $isEdit && $invoice['currency'] === 'UAH' ? 'selected' : ''; ?>>UAH</option>
                            <option value="EUR" <?php echo $isEdit && $invoice['currency'] === 'EUR' ? 'selected' : ''; ?>>EUR</option>
                            <option value="USD" <?php echo $isEdit && $invoice['currency'] === 'USD' ? 'selected' : ''; ?>>USD</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Курс</label>
                        <input type="number" name="exchange_rate" class="form-control" step="0.0001" value="<?php echo $rate; ?>">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Примітки</label>
                    <textarea name="notes" class="form-control" rows="2"><?php echo $isEdit ? escape($invoice['notes']) : ''; ?></textarea>
                </div>

                <h6 class="fw-bold mb-2">Товари</h6>
                <div class="table-container">
                    <table class="table table-bordered" id="itemsTable">
                        <thead>
                            <tr>
                                <th style="width:40%;">Товар</th>
                                <th style="width:15%;">Кількість</th>
                                <th style="width:20%;">Ціна (<?php echo $isEdit ? $invoice['currency'] : 'UAH'; ?>)</th>
                                <th style="width:20%;">Сума</th>
                                <th style="width:5%;"></th>
                            </tr>
                        </thead>
                        <tbody id="itemsBody">
                            <?php if ($isEdit && count($items) > 0): ?>
                                <?php foreach ($items as $item): ?>
                                <tr>
                                    <td>
                                        <select name="product_id[]" class="form-select" required>
                                            <option value="">-- Виберіть --</option>
                                            <?php foreach ($products as $p): ?>
                                            <option value="<?php echo $p['product_id']; ?>" <?php echo $item['product_id'] == $p['product_id'] ? 'selected' : ''; ?>><?php echo escape($p['name'] ?: 'ID: ' . $p['product_id']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td><input type="number" name="quantity[]" class="form-control" step="0.01" min="0.01" required value="<?php echo (float)$item['quantity']; ?>"></td>
                                    <td><input type="number" name="price_foreign[]" class="form-control price-foreign" step="0.0001" min="0" required value="<?php echo (float)$item['price_foreign']; ?>"></td>
                                    <td><span class="line-total fw-bold"><?php echo number_format((float)$item['quantity'] * (float)$item['price_foreign'], 2); ?></span></td>
                                    <td><button type="button" class="btn btn-outline-danger btn-sm remove-item"><i class="bi bi-trash"></i></button></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
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
                            <?php endif; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td><button type="button" class="btn btn-sm btn-outline-primary" id="addItem"><i class="bi bi-plus-lg"></i> Додати рядок</button></td>
                                <td colspan="2" class="text-end fw-bold">Всього:</td>
                                <td><span id="grandTotal" class="fw-bold">0.00</span></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> <?php echo $submitLabel; ?></button>
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
                        <th class="text-end">Сума (валюта)</th>
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
                        <td class="text-end"><?php echo formatMoneyForeign($inv['total_foreign'], $inv['currency']); ?></td>
                        <td class="text-end"><?php echo formatMoney($inv['total_local']); ?></td>
                        <td><?php echo getStatusBadge($inv['status']); ?></td>
                        <td class="text-center">
                            <a href="<?php echo BASE_URL; ?>/modules/incoming.php?action=view&id=<?php echo $inv['invoice_id']; ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a>
                            <?php if ($inv['status'] === 'draft'): ?>
                            <a href="<?php echo BASE_URL; ?>/modules/incoming.php?action=edit&id=<?php echo $inv['invoice_id']; ?>" class="btn btn-sm btn-warning"><i class="bi bi-pencil"></i></a>
                            <?php endif; ?>
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
