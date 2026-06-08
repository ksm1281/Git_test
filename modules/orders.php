<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../_helpers.php';
requireLogin();

function syncOrderCustomer($pdo, $orderId, $name, $email, $phone) {
    $stmt = $pdo->prepare("SELECT * FROM erp_orders WHERE order_id=?");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    if (!$order) return;
    if ($order['customer_id']) return;

    $name = $order['customer_name'] ?: $name;
    $email = $order['email'] ?: $email;
    $phone = $order['telephone'] ?: $phone;

    $customerId = null;
    if ($email) {
        $stmt = $pdo->prepare("SELECT customer_id FROM erp_customers WHERE email=? LIMIT 1");
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        if ($row) $customerId = (int)$row['customer_id'];
    }
    if (!$customerId && $phone) {
        $stmt = $pdo->prepare("SELECT customer_id FROM erp_customers WHERE telephone=? LIMIT 1");
        $stmt->execute([$phone]);
        $row = $stmt->fetch();
        if ($row) $customerId = (int)$row['customer_id'];
    }
    if (!$customerId && ($email || $phone || $name)) {
        $stmt = $pdo->query("SELECT COALESCE(MAX(customer_id), 0) + 1 FROM erp_customers");
        $customerId = (int)$stmt->fetchColumn();
        $parts = explode(' ', trim($name), 2);
        $stmt = $pdo->prepare("INSERT INTO erp_customers (customer_id, firstname, lastname, email, telephone) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$customerId, $parts[0] ?? '', $parts[1] ?? '', $email, $phone]);
    }
    if ($customerId) {
        $pdo->prepare("UPDATE erp_orders SET customer_id=? WHERE order_id=?")->execute([$customerId, $orderId]);
        $pdo->prepare("UPDATE erp_customers SET total_orders=(SELECT COUNT(*) FROM erp_orders WHERE customer_id=?), total_spent=(SELECT COALESCE(SUM(total),0) FROM erp_orders WHERE customer_id=?) WHERE customer_id=?")
            ->execute([$customerId, $customerId, $customerId]);
    }
}

$action = $_GET['action'] ?? 'list';
$orderId = (int)($_GET['id'] ?? 0);
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? '';
$user = getUserData();

if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstname = trim($_POST['firstname'] ?? '');
    $lastname = trim($_POST['lastname'] ?? '');
    $customerName = trim($firstname . ' ' . $lastname);
    $email = trim($_POST['email'] ?? '');
    $telephone = trim($_POST['telephone'] ?? '');
    $statusName = $_POST['status_name'] ?? 'pending';
    $notes = trim($_POST['notes'] ?? '');
    $paymentMethod = $_POST['payment_method'] ?? '';
    $deliveryMethod = $_POST['delivery_method'] ?? '';
    if ($deliveryMethod === 'nova_poshta') {
        $deliveryCity = trim($_POST['np_city'] ?? '');
        $deliveryOffice = trim($_POST['np_warehouse'] ?? '');
    } else {
        $deliveryCity = trim($_POST['delivery_city'] ?? '');
        $deliveryOffice = trim($_POST['delivery_office'] ?? '');
    }
    $deliveryStreet = trim($_POST['delivery_street'] ?? '');
    $deliveryBuilding = trim($_POST['delivery_building'] ?? '');
    $deliveryApartment = trim($_POST['delivery_apartment'] ?? '');
    $deliveryAddress = $deliveryMethod === 'courier'
        ? trim("$deliveryStreet, буд. $deliveryBuilding" . ($deliveryApartment ? ", $deliveryApartment" : ''))
        : trim($deliveryCity . ($deliveryOffice ? ', від. ' . $deliveryOffice : ''));
    $orderDate = $_POST['order_date'] ?? date('Y-m-d');
    $ttnNumber = trim($_POST['ttn_number'] ?? '');
    $deliveryStatus = $_POST['delivery_status'] ?? 'new';
    $payAmount = (float)($_POST['pay_amount'] ?? 0);
    $payMethod = $_POST['pay_method'] ?? 'cash';
    $payDate = $_POST['pay_date'] ?? date('Y-m-d');

    $productIds = $_POST['product_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $prices = $_POST['price'] ?? [];

    if (empty($firstname) && empty($lastname)) {
        flashMessage('error', 'Введіть ім\'я клієнта');
        redirect(BASE_URL . '/modules/orders.php?action=create');
    }

    $pdo->beginTransaction();
    try {
        if ($orderId) {
            $stmt = $pdo->prepare("UPDATE erp_orders SET customer_name=?, email=?, telephone=?, status_name=?, erp_notes=?, payment_method=?, delivery_method=?, delivery_address=?, delivery_city=?, delivery_street=?, delivery_building=?, delivery_apartment=?, delivery_office=?, ttn_number=?, delivery_status=?, order_date=? WHERE order_id=?");
            $stmt->execute([$customerName, $email, $telephone, $statusName, $notes, $paymentMethod, $deliveryMethod, $deliveryAddress, $deliveryCity, $deliveryStreet, $deliveryBuilding, $deliveryApartment, $deliveryOffice, $ttnNumber, $deliveryStatus, $orderDate, $orderId]);

            $oldProducts = $pdo->prepare("SELECT product_id, quantity FROM erp_order_products WHERE order_id=?");
            $oldProducts->execute([$orderId]);
            foreach ($oldProducts as $op) {
                $stmt = $pdo->prepare("INSERT INTO erp_stock_moves (product_id, type, quantity, notes, user_id) VALUES (?, 'return_in', ?, ?, ?)");
                $stmt->execute([$op['product_id'], $op['quantity'], 'Повернення з редагування замовлення #' . $orderId, $user['user_id']]);
            }
            $pdo->prepare("DELETE FROM erp_order_products WHERE order_id=?")->execute([$orderId]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO erp_orders (customer_name, email, telephone, status_name, total, erp_notes, payment_method, delivery_method, delivery_address, delivery_city, delivery_street, delivery_building, delivery_apartment, delivery_office, ttn_number, delivery_status, order_date) VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$customerName, $email, $telephone, $statusName, $notes, $paymentMethod, $deliveryMethod, $deliveryAddress, $deliveryCity, $deliveryStreet, $deliveryBuilding, $deliveryApartment, $deliveryOffice, $ttnNumber, $deliveryStatus, $orderDate]);
            $orderId = (int)$pdo->lastInsertId();
        }

        $errorRows = [];
        $total = 0;
        for ($i = 0; $i < count($productIds); $i++) {
            $pid = (int)($productIds[$i] ?? 0);
            $qty = (float)($quantities[$i] ?? 0);
            $price = (float)($prices[$i] ?? 0);

            if ($pid <= 0) {
                $errorRows[] = 'Рядок ' . ($i + 1) . ': не вибрано товар';
                continue;
            }
            if ($qty <= 0) {
                $errorRows[] = 'Рядок ' . ($i + 1) . ': некоректна кількість';
                continue;
            }

            $lineTotal = $qty * $price;
            $total += $lineTotal;

            $costPrice = getProductCostPrice($pdo, $pid, $qty);
            $profit = $lineTotal - ($costPrice * $qty);

            $stmt = $pdo->prepare("INSERT INTO erp_order_products (order_id, product_id, name, quantity, price, total, cost_price, profit) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$orderId, $pid, '', $qty, $price, $lineTotal, $costPrice, $profit]);

            if (in_array($statusName, ['processed', 'completed', 'shipped', 'delivered'])) {
                $stmt = $pdo->prepare("INSERT INTO erp_stock_moves (product_id, type, quantity, cost_price, reference_type, reference_id, user_id, notes) VALUES (?, 'out', ?, ?, 'order', ?, ?, ?)");
                $stmt->execute([$pid, $qty, $costPrice, $orderId, $user['user_id'], 'Замовлення #' . $orderId]);
            }
        }

        if (!empty($errorRows)) {
            $pdo->rollBack();
            flashMessage('error', implode('<br>', $errorRows));
            if ($orderId) {
                redirect(BASE_URL . '/modules/orders.php?action=edit&id=' . $orderId);
            } else {
                redirect(BASE_URL . '/modules/orders.php?action=create');
            }
        }

        $pdo->prepare("UPDATE erp_orders SET total=? WHERE order_id=?")->execute([$total, $orderId]);

        syncOrderCustomer($pdo, $orderId, $customerName, $email, $telephone);

        if ($payAmount > 0) {
            $stmt = $pdo->prepare("INSERT INTO erp_payments (order_id, amount, method, date, notes, user_id) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$orderId, $payAmount, $payMethod, $payDate, 'Оплата замовлення #' . $orderId, $user['user_id']]);
        }

        $pdo->commit();

        $sendNotif = $_POST['send_notification'] ?? '';
        $isNewOrder = !((int)($_GET['id'] ?? 0));
        if ($isNewOrder && $sendNotif === '1') {
            $stmt = $pdo->prepare("SELECT `value` FROM erp_settings WHERE `key`='notify_on_new_order'");
            $stmt->execute();
            $notifyEnabled = $stmt->fetchColumn();

            if ($notifyEnabled === '1') {
                $itemsList = '';
                for ($i = 0; $i < count($productIds); $i++) {
                    $pid = (int)($productIds[$i] ?? 0);
                    $qty = (float)($quantities[$i] ?? 0);
                    $price = (float)($prices[$i] ?? 0);
                    if ($pid <= 0 || $qty <= 0) continue;
                    $stmt = $pdo->prepare("SELECT name FROM erp_products WHERE product_id=?");
                    $stmt->execute([$pid]);
                    $pname = $stmt->fetchColumn() ?: ('ID ' . $pid);
                    $itemsList .= sprintf("\n• %s — %s × %s = %s ₴",
                        $pname, $qty, number_format($price, 2), number_format($qty * $price, 2));
                }

                $msg = "<b>🆕 Нове замовлення #{$orderId}</b>\n"
                    . "👤 {$customerName}\n"
                    . "📞 {$telephone}\n"
                    . ($email ? "📧 {$email}\n" : '')
                    . ($deliveryMethod ? "🚚 {$deliveryMethod}\n" : '')
                    . ($deliveryAddress ? "📍 {$deliveryAddress}\n" : '')
                    . ($notes ? "📝 {$notes}\n" : '')
                    . "\n<b>Товари:</b>{$itemsList}\n\n"
                    . "<b>💰 Всього: " . number_format($total, 2) . " ₴</b>\n"
                    . ($payAmount > 0 ? "💳 Оплата: " . number_format($payAmount, 2) . " ₴\n" : '')
                    . "🕒 " . date('d.m.Y H:i');

                sendTelegramNotification($pdo, $msg);
            }
        }

        flashMessage('success', 'Замовлення #' . $orderId . ' збережено');
        redirect(BASE_URL . '/modules/orders.php?action=view&id=' . $orderId);
    } catch (Exception $e) {
        $pdo->rollBack();
        flashMessage('error', 'Помилка: ' . $e->getMessage());
        redirect(BASE_URL . '/modules/orders.php?action=' . ($orderId ? 'edit&id=' . $orderId : 'create'));
    }
}

if ($action === 'pay' && $_SERVER['REQUEST_METHOD'] === 'POST' && $orderId && isManager()) {
    $amount = (float)($_POST['amount'] ?? 0);
    $method = $_POST['method'] ?? 'cash';
    $date = $_POST['date'] ?? date('Y-m-d');
    $notes = trim($_POST['notes'] ?? '');

    if ($amount > 0) {
        $stmt = $pdo->prepare("INSERT INTO erp_payments (order_id, amount, method, date, notes, user_id) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$orderId, $amount, $method, $date, $notes, $user['user_id']]);
        flashMessage('success', 'Платіж на ' . formatMoney($amount) . ' зареєстровано');
    } else {
        flashMessage('error', 'Некоректна сума');
    }
    redirect(BASE_URL . '/modules/orders.php?action=view&id=' . $orderId);
}

if ($action === 'status' && $orderId && isManager()) {
    $newStatus = $_GET['status'] ?? '';
    $validStatuses = ['pending', 'approved', 'processed', 'shipped', 'delivered', 'cancelled'];
    if (in_array($newStatus, $validStatuses)) {
        $oldStmt = $pdo->prepare("SELECT status_name FROM erp_orders WHERE order_id=?");
        $oldStmt->execute([$orderId]);
        $oldStatus = $oldStmt->fetchColumn();

        $pdo->prepare("UPDATE erp_orders SET status_name=? WHERE order_id=?")->execute([$newStatus, $orderId]);

        if (!in_array($oldStatus, ['processed', 'completed', 'shipped', 'delivered']) && in_array($newStatus, ['processed', 'shipped', 'delivered'])) {
            $stmt = $pdo->prepare("SELECT product_id, quantity FROM erp_order_products WHERE order_id=?");
            $stmt->execute([$orderId]);
            foreach ($stmt as $op) {
                $costPrice = getProductCostPrice($pdo, $op['product_id']);
                $pdo->prepare("INSERT INTO erp_stock_moves (product_id, type, quantity, cost_price, reference_type, reference_id, user_id, notes) VALUES (?, 'out', ?, ?, 'order', ?, ?, ?)")
                    ->execute([$op['product_id'], $op['quantity'], $costPrice, $orderId, $user['user_id'], 'Замовлення #' . $orderId]);
            }
        }
        if (in_array($oldStatus, ['processed', 'shipped', 'delivered']) && !in_array($newStatus, ['processed', 'shipped', 'delivered'])) {
            $stmt = $pdo->prepare("SELECT product_id, quantity FROM erp_order_products WHERE order_id=?");
            $stmt->execute([$orderId]);
            foreach ($stmt as $op) {
                $pdo->prepare("INSERT INTO erp_stock_moves (product_id, type, quantity, reference_type, reference_id, user_id, notes) VALUES (?, 'return_in', ?, 'order', ?, ?, ?)")
                    ->execute([$op['product_id'], $op['quantity'], $orderId, $user['user_id'], 'Скасування відвантаження замовлення #' . $orderId]);
            }
        }
        flashMessage('success', 'Статус замовлення змінено на "' . $newStatus . '"');
    } else {
        flashMessage('error', 'Невірний статус');
    }
    redirect(BASE_URL . '/modules/orders.php?action=view&id=' . $orderId);
}

if ($action === 'delivery_status' && $orderId && isManager()) {
    $newDs = $_GET['ds'] ?? '';
    $validDs = ['new', 'sending', 'in_transit', 'arrived', 'delivered', 'returned'];
    if (in_array($newDs, $validDs)) {
        $pdo->prepare("UPDATE erp_orders SET delivery_status=? WHERE order_id=?")->execute([$newDs, $orderId]);
        $dsLabels = ['new' => 'Нове', 'sending' => 'Відправляється', 'in_transit' => 'В дорозі', 'arrived' => 'Прибуло у відділення', 'delivered' => 'Видано одержувачу', 'returned' => 'Повернення'];
        flashMessage('success', 'Статус доставки змінено на "' . ($dsLabels[$newDs] ?? $newDs) . '"');
    } else {
        flashMessage('error', 'Невірний статус доставки');
    }
    redirect(BASE_URL . '/modules/orders.php?action=view&id=' . $orderId);
}

if ($action === 'delete' && $orderId && isAdmin()) {
    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM erp_stock_moves WHERE reference_type='order' AND reference_id=?")->execute([$orderId]);
        $pdo->prepare("DELETE FROM erp_payments WHERE order_id=?")->execute([$orderId]);
        $pdo->prepare("DELETE FROM erp_order_products WHERE order_id=?")->execute([$orderId]);
        $pdo->prepare("DELETE FROM erp_orders WHERE order_id=?")->execute([$orderId]);
        $pdo->commit();
        flashMessage('success', 'Замовлення видалено');
    } catch (Exception $e) {
        $pdo->rollBack();
        flashMessage('error', 'Помилка: ' . $e->getMessage());
    }
    redirect(BASE_URL . '/modules/orders.php');
}

if ($action === 'invoice' && $orderId) {
    $stmt = $pdo->prepare("SELECT * FROM erp_orders WHERE order_id = ?");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    if (!$order) { flashMessage('error', 'Замовлення не знайдено'); redirect(BASE_URL . '/modules/orders.php'); }

    $stmt = $pdo->prepare("SELECT op.*, p.name as product_name FROM erp_order_products op LEFT JOIN erp_products p ON op.product_id = p.product_id WHERE op.order_id = ?");
    $stmt->execute([$orderId]);
    $items = $stmt->fetchAll();
    $totalCost = 0;
    $totalProfit = 0;
    foreach ($items as $item) {
        $totalCost += (float)$item['cost_price'] * (float)$item['quantity'];
        $totalProfit += (float)$item['profit'];
    }

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM erp_payments WHERE order_id=?");
    $stmt->execute([$orderId]);
    $totalPaid = (float)$stmt->fetchColumn();

    $methodLabels = ['cash' => 'Готівка', 'card' => 'Картка', 'fop' => 'ФОП', 'invoice' => 'Рахунок', 'transfer' => 'Переказ', 'nova_poshta' => 'Нова Пошта (зворотня доставка)'];
    $deliveryLabels = ['pickup' => 'Самовивіз', 'courier' => 'Кур\'єр', 'nova_poshta' => 'Нова Пошта', 'delivery' => 'Делівері', 'ukrposhta' => 'Укрпошта'];
    $nameParts = explode(' ', trim($order['customer_name']), 2);
    $balance = (float)$order['total'] - $totalPaid;
    $appName = 'ERP/CRM';
    $stmt = $pdo->prepare("SELECT * FROM erp_customers WHERE customer_id = ?");
    $stmt->execute([$order['customer_id']]);
    $customerReqs = $stmt->fetch();

    $stmt = $pdo->prepare("SELECT `key`, `value` FROM erp_settings");
    $stmt->execute();
    $allSettings = [];
    foreach ($stmt as $row) {
        $allSettings[$row['key']] = $row['value'];
    }

    $supplierName = $allSettings['supplier_name'] ?? $appName;
    $supplierEdrpou = $allSettings['supplier_edrpou'] ?? '';
    $supplierPhone = $allSettings['supplier_phone'] ?? '';
    $supplierIban = $allSettings['supplier_iban'] ?? '';
    $supplierBank = $allSettings['supplier_bank'] ?? '';
    $supplierMfo = $allSettings['supplier_mfo'] ?? '';
    $supplierCert = $allSettings['supplier_certificate'] ?? '';
    $supplierCertDate = $allSettings['supplier_cert_date'] ?? '';
    $supplierAddress = $allSettings['supplier_address'] ?? '';

    $orderDate = $order['order_date'] ? date('d.m.Y', strtotime($order['order_date'])) : date('d.m.Y');
    $orderDay = date('d', strtotime($order['order_date'] ?: 'now'));
    $orderMonth = date('m', strtotime($order['order_date'] ?: 'now'));
    $totalWords = num2str($order['total']);

    ?><!DOCTYPE html>
    <html lang="uk">
    <head>
        <meta charset="UTF-8">
        <title>Видаткова-накладна #<?php echo $orderId; ?></title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>
            body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 11px; padding: 15px 25px; color: #000; }
            .print-btn { margin-bottom: 15px; }
            @media print { .print-btn { display: none; } body { padding: 0; } }
            .header-box { border: 1px solid #000; padding: 8px; margin-bottom: 10px; font-size: 10px; }
            .header-box td { padding: 1px 5px; vertical-align: top; }
            .header-label { font-weight: bold; }
            .doc-title { font-size: 16px; font-weight: bold; text-align: center; margin: 15px 0 5px; }
            .doc-sub { text-align: center; font-size: 11px; margin-bottom: 15px; }
            table.items { width: 100%; border-collapse: collapse; margin: 10px 0; }
            table.items th, table.items td { border: 1px solid #000; padding: 4px 6px; text-align: center; }
            table.items th { font-weight: bold; }
            table.items td.left { text-align: left; }
            table.items td.right { text-align: right; }
            .total-table { width: 100%; border-collapse: collapse; margin: 5px 0; }
            .total-table td { padding: 3px 8px; border: 1px solid #000; }
            .total-table td.label { text-align: right; font-weight: bold; width: 80%; }
            .total-table td.value { text-align: right; width: 20%; }
            .sign-line { margin-top: 30px; font-size: 10px; }
            .sign-line td { padding: 2px 10px; vertical-align: bottom; }
        </style>
    </head>
    <body>
        <button class="btn btn-primary print-btn" onclick="window.print()"><i class="bi bi-printer"></i> Друк</button>
        <a href="<?php echo BASE_URL; ?>/modules/orders.php?action=view&id=<?php echo $orderId; ?>" class="btn btn-outline-secondary print-btn">Назад</a>

        <table class="header-box" style="width:100%;">
            <tr>
                <td style="width:50%;">
                    <div class="header-label">Постачальник:</div>
                    <?php echo escape($supplierName); ?><br>
                    ЄДРПОУ <?php echo escape($supplierEdrpou); ?>, тел. <?php echo escape($supplierPhone); ?><br>
                    IBAN <?php echo escape($supplierIban); ?> в <?php echo escape($supplierBank); ?><br>
                    МФО <?php echo escape($supplierMfo); ?><br>
                    Свідоцтво № <?php echo escape($supplierCert); ?> від <?php echo escape($supplierCertDate); ?><br>
                    Адреса: <?php echo escape($supplierAddress); ?>
                </td>
                <td style="width:50%;vertical-align:top;">
                    <div class="header-label">Одержувач:</div>
                    <?php echo escape($supplierName); ?><br>
                    IBAN <?php echo escape($supplierIban); ?><br>
                    МФО <?php echo escape($supplierMfo); ?>
                </td>
            </tr>
        </table>

        <table class="header-box" style="width:100%;">
            <tr>
                <td style="width:50%;">
                    <div class="header-label">Платник:</div>
                    <?php echo escape($order['customer_name']); ?><br>
                    Тел. <?php echo escape($order['telephone'] ?: '-'); ?>
                    <?php if ($order['email']): ?>
                    <br><?php echo escape($order['email']); ?>
                    <?php endif; ?>
                    <?php if ($customerReqs && $customerReqs['company_name']): ?>
                    <br><?php echo escape($customerReqs['company_name']); ?>
                    <?php if ($customerReqs['edrpou']): ?>, ЄДРПОУ <?php echo escape($customerReqs['edrpou']); ?><?php endif; ?>
                    <?php endif; ?>
                    <?php if ($customerReqs && $customerReqs['legal_address']): ?>
                    <br><?php echo escape($customerReqs['legal_address']); ?>
                    <?php endif; ?>
                </td>
                <td style="width:50%;">
                    <?php if ($customerReqs && $customerReqs['iban']): ?>
                    <div class="header-label">Рахунок платника:</div>
                    IBAN <?php echo escape($customerReqs['iban']); ?>
                    <?php if ($customerReqs['mfo']): ?><br>МФО <?php echo escape($customerReqs['mfo']); ?><?php endif; ?>
                    <?php endif; ?>
                </td>
            </tr>
        </table>

        <div class="doc-title">ВИДАТКОВА-НАКЛАДНА №-<?php echo $orderId; ?>\<?php echo $orderDay; ?>-<?php echo $orderMonth; ?></div>
        <div class="doc-sub">Від <?php echo $orderDate; ?> р.</div>

        <table class="items">
            <thead>
                <tr>
                    <th style="width:4%;">№</th>
                    <th style="width:38%;">Назва</th>
                    <th style="width:8%;">Од.</th>
                    <th style="width:10%;">К-сть</th>
                    <th style="width:15%;">Ціна без ПДВ</th>
                    <th style="width:15%;">Сума без ПДВ</th>
                </tr>
            </thead>
            <tbody>
                <?php $i = 1; foreach ($items as $item): ?>
                <tr>
                    <td><?php echo $i++; ?></td>
                    <td class="left"><?php echo escape($item['product_name'] ?: $item['name']); ?></td>
                    <td>шт</td>
                    <td><?php echo (float)$item['quantity']; ?></td>
                    <td class="right"><?php echo formatMoney($item['price']); ?></td>
                    <td class="right"><?php echo formatMoney($item['total']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <table class="total-table">
            <tr><td class="label">Разом:</td><td class="value"><?php echo formatMoney($order['total']); ?></td></tr>
            <tr><td class="label">ПДВ:</td><td class="value">0.00 грн</td></tr>
            <tr><td class="label">Всього:</td><td class="value"><?php echo formatMoney($order['total']); ?></td></tr>
        </table>

        <div style="margin:10px 0;font-weight:bold;">
            Всього на суму: <?php echo formatMoney($order['total']); ?><br>
            <?php echo escape($totalWords); ?>.<br>
            ПДВ: 0.00 грн.
        </div>

        <table class="sign-line" style="width:100%;">
            <tr>
                <td style="width:40%;">Відвантажив(ла): _______________</td>
                <td style="width:40%;">Отримав(ла): _______________</td>
                <td style="width:20%;"></td>
            </tr>
            <tr>
                <td colspan="2" style="padding-top:10px;">За дов.______ № _____ від _________</td>
                <td></td>
            </tr>
        </table>

        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    </body>
    </html>
    <?php
    exit;
}

if ($action === 'receipt' && $orderId) {
    $stmt = $pdo->prepare("SELECT * FROM erp_orders WHERE order_id = ?");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    if (!$order) { flashMessage('error', 'Замовлення не знайдено'); redirect(BASE_URL . '/modules/orders.php'); }

    $stmt = $pdo->prepare("SELECT op.*, p.name as product_name FROM erp_order_products op LEFT JOIN erp_products p ON op.product_id = p.product_id WHERE op.order_id = ?");
    $stmt->execute([$orderId]);
    $items = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM erp_payments WHERE order_id=?");
    $stmt->execute([$orderId]);
    $totalPaid = (float)$stmt->fetchColumn();

    $methodLabels = ['cash' => 'Готівка', 'card' => 'Картка', 'fop' => 'ФОП', 'invoice' => 'Рахунок', 'transfer' => 'Переказ', 'nova_poshta' => 'Нова Пошта (зворотня доставка)'];
    $deliveryLabels = ['pickup' => 'Самовивіз', 'courier' => 'Кур\'єр', 'nova_poshta' => 'Нова Пошта', 'delivery' => 'Делівері', 'ukrposhta' => 'Укрпошта'];
    $nameParts = explode(' ', trim($order['customer_name']), 2);
    $appName = 'ERP/CRM';
    $stmt = $pdo->prepare("SELECT `value` FROM erp_settings WHERE `key`='app_name'");
    $stmt->execute();
    $row = $stmt->fetch();
    if ($row) $appName = $row['value'];
    $balance = (float)$order['total'] - $totalPaid;

    $paymentStatus = $balance <= 0
        ? ($totalPaid > 0 ? 'Оплачено' : 'Не оплачено')
        : 'Частково оплачено';

    $totalWords = num2str($order['total']);

    ?><!DOCTYPE html>
    <html lang="uk">
    <head>
        <meta charset="UTF-8">
        <title>Товарний чек #<?php echo $orderId; ?></title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>
            body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 12px; padding: 30px; color: #000; }
            .tc-title { font-size: 18px; font-weight: bold; text-align: center; margin-bottom: 5px; }
            .print-btn { margin-bottom: 20px; }
            @media print { .print-btn { display: none; } body { padding: 0; } }
            table.items { width: 100%; border-collapse: collapse; margin: 15px 0; }
            table.items th, table.items td { border: 1px solid #000; padding: 5px 8px; text-align: center; }
            table.items th { font-weight: bold; }
            table.items td.left { text-align: left; }
            table.items td.right { text-align: right; }
            .total-line { font-weight: bold; font-size: 13px; margin: 10px 0; }
            .sign-line { margin-top: 30px; }
        </style>
    </head>
    <body>
        <button class="btn btn-primary print-btn" onclick="window.print()"><i class="bi bi-printer"></i> Друк</button>
        <a href="<?php echo BASE_URL; ?>/modules/orders.php?action=view&id=<?php echo $orderId; ?>" class="btn btn-outline-secondary print-btn">Назад</a>

        <div class="tc-title">ТОВАРНИЙ ЧЕК № <?php echo $orderId; ?></div>
        <p>Від «<?php echo $order['order_date'] ? date('d', strtotime($order['order_date'])) : date('d'); ?>» <?php echo monthName($order['order_date'] ? date('m', strtotime($order['order_date'])) : date('m')); ?> <?php echo $order['order_date'] ? date('Y', strtotime($order['order_date'])) : date('Y'); ?> р.</p>

        <table class="items">
            <thead>
                <tr>
                    <th style="width:5%;">№</th>
                    <th style="width:40%;">Найменування</th>
                    <th style="width:10%;">Од. вим.</th>
                    <th style="width:10%;">К-ть</th>
                    <th style="width:15%;">Ціна</th>
                    <th style="width:15%;">Сума</th>
                </tr>
            </thead>
            <tbody>
                <?php $i = 1; foreach ($items as $item): ?>
                <tr>
                    <td><?php echo $i++; ?></td>
                    <td class="left"><?php echo escape($item['product_name'] ?: $item['name']); ?></td>
                    <td>шт</td>
                    <td><?php echo (float)$item['quantity']; ?></td>
                    <td class="right"><?php echo formatMoney($item['price']); ?></td>
                    <td class="right"><?php echo formatMoney($item['total']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="total-line">
            Всього на суму: <?php echo formatMoney($order['total']); ?><br>
            (<?php echo escape($totalWords); ?>)<br>
            в т.ч. ПДВ _____________
        </div>

        <div class="sign-line">
            <table style="width:100%;">
                <tr>
                    <td style="width:50%;">Товар відпустив</td>
                    <td style="width:30%;text-align:center;border-bottom:1px solid #000;">_________________</td>
                    <td style="width:20%;text-align:center;border-bottom:1px solid #000;">_______________</td>
                </tr>
                <tr>
                    <td></td>
                    <td style="text-align:center;font-size:10px;">підпис</td>
                    <td></td>
                </tr>
            </table>
        </div>

        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    </body>
    </html>
    <?php
    exit;
}

if ($action === 'create' || $action === 'edit') {
    $isEdit = ($action === 'edit' && $orderId);
    $order = [];
    $items = [];

    if ($isEdit) {
        $stmt = $pdo->prepare("SELECT * FROM erp_orders WHERE order_id=?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();
        if (!$order) { flashMessage('error', 'Замовлення не знайдено'); redirect(BASE_URL . '/modules/orders.php'); }
        $stmt = $pdo->prepare("SELECT op.*, p.name as product_name, p.price_retail, p.price_semi_wholesale, p.price_wholesale FROM erp_order_products op LEFT JOIN erp_products p ON op.product_id = p.product_id WHERE op.order_id=?");
        $stmt->execute([$orderId]);
        $items = $stmt->fetchAll();
    }

    $alpineItems = [];
    if ($isEdit && !empty($items)) {
        foreach ($items as $item) {
            $alpineItems[] = [
                'product_id' => (int)$item['product_id'],
                'name' => $item['product_name'] ?? '',
                'qty' => (float)$item['quantity'],
                'price' => (float)$item['price'],
                'prices' => [
                    'retail' => (float)($item['price_retail'] ?? 0),
                    'semi' => (float)($item['price_semi_wholesale'] ?? 0),
                    'wholesale' => (float)($item['price_wholesale'] ?? 0),
                ],
            ];
        }
    }
    if (empty($alpineItems)) {
        $alpineItems[] = ['product_id' => 0, 'name' => '', 'qty' => 1, 'price' => 0, 'prices' => []];
    }

    $title = $isEdit ? 'Редагувати замовлення #' . $orderId : 'Нове замовлення';
    $formAction = $isEdit ? 'save&id=' . $orderId : 'save';
    $submitLabel = $isEdit ? 'Зберегти зміни' : 'Створити замовлення';

    $statusOptions = ['pending' => 'Очікує', 'approved' => 'Підтверджено', 'processed' => 'В обробці', 'shipped' => 'Відправлено', 'delivered' => 'Доставлено', 'cancelled' => 'Скасовано'];

    include __DIR__ . '/../includes/header.php';
    ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0"><i class="bi bi-cart3"></i> <?php echo $title; ?></h4>
        <a href="<?php echo BASE_URL; ?>/modules/orders.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x-lg"></i> Скасувати</a>
    </div>
    <div class="card">
        <div class="card-body">
            <form method="post" action="?action=<?php echo $formAction; ?>" id="orderForm" @submit.prevent="submitOrder"
                  x-data="orderForm"
                  data-search-url="<?php echo htmlspecialchars(BASE_URL . '/api/search-products.php', ENT_QUOTES, 'UTF-8'); ?>"
                  data-search-customers-url="<?php echo htmlspecialchars(BASE_URL . '/api/search-customers.php', ENT_QUOTES, 'UTF-8'); ?>"
                  data-np-api-url="<?php echo htmlspecialchars(BASE_URL . '/api/nova-poshta.php', ENT_QUOTES, 'UTF-8'); ?>"
                  data-delivery-method="<?php echo $isEdit ? htmlspecialchars($order['delivery_method'], ENT_QUOTES, 'UTF-8') : ''; ?>"
                  data-customer-name="<?php echo $isEdit ? htmlspecialchars($order['customer_name'], ENT_QUOTES, 'UTF-8') : ''; ?>"
                  data-np-city="<?php echo $isEdit && $order['delivery_method'] === 'nova_poshta' ? htmlspecialchars($order['delivery_city'], ENT_QUOTES, 'UTF-8') : ''; ?>"
                  data-np-city-ref="<?php echo $isEdit && $order['delivery_method'] === 'nova_poshta' ? htmlspecialchars($order['delivery_city'], ENT_QUOTES, 'UTF-8') : ''; ?>"
                  data-np-warehouse="<?php echo $isEdit && $order['delivery_method'] === 'nova_poshta' ? htmlspecialchars($order['delivery_office'], ENT_QUOTES, 'UTF-8') : ''; ?>"
                  data-telephone="<?php echo $isEdit ? htmlspecialchars($order['telephone'], ENT_QUOTES, 'UTF-8') : ''; ?>"
                  data-items="<?php echo htmlspecialchars(json_encode($alpineItems, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>">
                <div class="row g-3 mb-3">
                    <div class="col-md-12">
                        <label class="form-label">Пошук клієнта <small class="text-muted">(введіть ім'я, телефон або email)</small></label>
                        <div class="position-relative">
                            <input type="text" class="form-control" placeholder="Почніть вводити ім'я або телефон..." autocomplete="off"
                                   x-model="customerQuery"
                                   @input.debounce.300ms="searchCustomer()"
                                   @focus="searchCustomer()"
                                   @keydown.escape="customerOpen = false"
                                   @blur="closeCustomer()">
                            <div x-show="customerOpen" x-cloak
                                 style="position:absolute;top:100%;left:0;right:0;z-index:1050;max-height:250px;overflow-y:auto;background:#fff;border:1px solid #dee2e6;border-radius:4px;box-shadow:0 4px 12px rgba(0,0,0,0.15);">
                                <template x-for="c in customerResults" :key="c.customer_id || '_err'">
                                    <button type="button" class="dropdown-item"
                                            :class="{'text-danger': c._error}"
                                            @mousedown.prevent="if(!c._error) selectCustomer(c)">
                                        <span x-show="!c._error" x-text="((c.firstname||'')+' '+(c.lastname||'')).trim() || '#'+c.customer_id"></span>
                                        <span x-show="c._error" x-text="c._error"></span>
                                        <br x-show="!c._error">
                                        <small x-show="!c._error" class="text-muted" x-text="[c.telephone, c.email].filter(Boolean).join(' · ')"></small>
                                    </button>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <label class="form-label required">Ім'я</label>
                        <input type="text" name="firstname" class="form-control" required value="<?php echo $isEdit ? explode(' ', trim($order['customer_name']))[0] : ''; ?>" x-ref="orderFirstname">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Фамілія</label>
                        <input type="text" name="lastname" class="form-control" value="<?php echo $isEdit ? (strpos(trim($order['customer_name']), ' ') !== false ? substr(trim($order['customer_name']), strpos(trim($order['customer_name']), ' ') + 1) : '') : ''; ?>" x-ref="orderLastname">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Телефон</label>
                        <div style="display:flex;gap:3px;">
                            <input type="tel" name="telephone" class="form-control" pattern="^\+?380[0-9]{9}$|^0[0-9]{9}$" title="Формат: +380XXXXXXXXX або 0XXXXXXXXX" style="flex:1;"
                                   x-model="telephone"
                                   x-ref="orderTelephone">
                            <a :href="messengerTelegram" target="_blank" title="Telegram"
                               x-show="hasPhone" x-cloak
                               style="display:inline-flex;align-items:center;justify-content:center;width:34px;border:1px solid #dee2e6;border-radius:4px;text-decoration:none;background:#fff;"><svg width="18" height="18" viewBox="0 0 24 24" fill="#0088cc"><path d="M2.5 3.2l18.5 7.8c.7.3.7 1.2 0 1.5L2.5 20.2c-.7.3-1.4-.3-1.2-1L3.7 13c.1-.3.3-.5.6-.6l8.8-2.4c.2-.1.2-.3 0-.4L4.3 7.1c-.3-.1-.5-.3-.6-.6L1.3 4.2c-.2-.7.5-1.3 1.2-1z"/></svg></a>
                            <a :href="messengerViber" target="_blank" title="Viber"
                               x-show="hasPhone" x-cloak
                               style="display:inline-flex;align-items:center;justify-content:center;width:34px;border:1px solid #dee2e6;border-radius:4px;text-decoration:none;background:#fff;"><svg width="18" height="18" viewBox="0 0 24 24" fill="#7360F2"><path d="M6.62 10.79c1.44 2.83 3.76 5.15 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z"/></svg></a>
                        </div>
                        <small class="text-muted">+380XXXXXXXXX або 0XXXXXXXXX</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" value="<?php echo $isEdit ? escape($order['email']) : ''; ?>" x-ref="orderEmail">
                    </div>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <label class="form-label">Спосіб доставки</label>
                        <select name="delivery_method" class="form-select" x-model="deliveryMethod">
                            <option value="">-- Виберіть --</option>
                            <option value="pickup">Самовивіз</option>
                            <option value="courier">Кур'єр</option>
                            <option value="nova_poshta">Нова Пошта</option>
                            <option value="delivery">Делівері</option>
                            <option value="ukrposhta">Укрпошта</option>
                        </select>
                    </div>
                    <div class="col-md-3" x-show="deliveryMethod === 'courier'" x-cloak>
                        <label class="form-label">Вулиця</label>
                        <input type="text" name="delivery_street" class="form-control" value="<?php echo $isEdit ? escape($order['delivery_street']) : ''; ?>" placeholder="Назва вулиці">
                    </div>
                    <div class="col-md-2" x-show="deliveryMethod === 'courier'" x-cloak>
                        <label class="form-label">Будинок</label>
                        <input type="text" name="delivery_building" class="form-control" value="<?php echo $isEdit ? escape($order['delivery_building']) : ''; ?>" placeholder="№">
                    </div>
                    <div class="col-md-2" x-show="deliveryMethod === 'courier'" x-cloak>
                        <label class="form-label">Квартира/офіс</label>
                        <input type="text" name="delivery_apartment" class="form-control" value="<?php echo $isEdit ? escape($order['delivery_apartment']) : ''; ?>" placeholder="№">
                    </div>
                    <div class="col-md-6" x-show="deliveryMethod === 'nova_poshta'" x-cloak>
                        <label class="form-label">Місто</label>
                        <div class="position-relative">
                            <input type="text" class="form-control" placeholder="Почніть вводити назву міста..." autocomplete="off"
                                   x-model="npCityQuery"
                                   @input.debounce.300ms="searchNpCity()"
                                   @focus="searchNpCity()"
                                   @keydown.escape="npCityOpen = false"
                                   @blur="closeNpCity()">
                            <input type="hidden" name="np_city" x-model="npCityHidden">
                            <div x-show="npCityOpen" x-cloak
                                 style="position:absolute;top:100%;left:0;right:0;z-index:1060;background:#fff;border:1px solid #dee2e6;border-radius:4px;box-shadow:0 4px 12px rgba(0,0,0,0.15);overflow-y:auto;max-height:200px;">
                                <template x-for="c in npCityResults" :key="c.ref || '_err'">
                                    <button type="button" class="dropdown-item"
                                            :class="{'text-danger': c._error}"
                                            @mousedown.prevent="if(!c._error) selectNpCity(c)">
                                        <span x-show="!c._error" x-text="c.name"></span>
                                        <span x-show="c._error" x-text="c._error"></span>
                                    </button>
                                </template>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3" x-show="deliveryMethod === 'nova_poshta'" x-cloak>
                        <label class="form-label">Відділення</label>
                        <div class="position-relative">
                            <input type="text" class="form-control" placeholder="Почніть вводити номер відділення..." autocomplete="off"
                                   x-model="npWarehouseQuery"
                                   @input.debounce.300ms="searchNpWarehouse()"
                                   @focus="if(npWarehouseQuery && !npWarehouseHidden) searchNpWarehouse()"
                                   @keydown.escape="npWarehouseOpen = false"
                                   @blur="closeNpWarehouse()">
                            <input type="hidden" name="np_warehouse" x-model="npWarehouseHidden">
                            <div x-show="npWarehouseOpen" x-cloak
                                 style="position:absolute;top:100%;left:0;right:0;z-index:1060;background:#fff;border:1px solid #dee2e6;border-radius:4px;box-shadow:0 4px 12px rgba(0,0,0,0.15);overflow-y:auto;max-height:200px;">
                                <template x-for="w in npWarehouseResults" :key="w.name || '_err'">
                                    <button type="button" class="dropdown-item"
                                            :class="{'text-danger': w._error}"
                                            @mousedown.prevent="if(!w._error) selectNpWarehouse(w)">
                                        <span x-show="!w._error" x-text="w.name"></span>
                                        <span x-show="w._error" x-text="w._error"></span>
                                    </button>
                                </template>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3" x-show="deliveryMethod === 'delivery' || deliveryMethod === 'ukrposhta'" x-cloak>
                        <label class="form-label">Місто</label>
                        <input type="text" name="delivery_city" class="form-control" value="<?php echo $isEdit && !in_array($order['delivery_method'], ['nova_poshta', 'courier', 'pickup', '']) ? escape($order['delivery_city']) : ''; ?>" placeholder="Місто">
                    </div>
                    <div class="col-md-3" x-show="deliveryMethod === 'delivery' || deliveryMethod === 'ukrposhta'" x-cloak>
                        <label class="form-label">Відділення</label>
                        <input type="text" name="delivery_office" class="form-control" value="<?php echo $isEdit && !in_array($order['delivery_method'], ['nova_poshta', 'courier', 'pickup', '']) ? escape($order['delivery_office']) : ''; ?>" placeholder="№ відділення / адреса">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Статус</label>
                        <select name="status_name" class="form-select">
                            <?php foreach ($statusOptions as $val => $label): ?>
                            <option value="<?php echo $val; ?>" <?php echo $isEdit && $order['status_name'] === $val ? 'selected' : ''; ?>><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3" x-show="deliveryMethod !== 'pickup'" x-cloak>
                        <label class="form-label">Номер ТТН</label>
                        <input type="text" name="ttn_number" class="form-control" value="<?php echo $isEdit ? escape($order['ttn_number']) : ''; ?>" placeholder="Транспортний номер">
                    </div>
                    <div class="col-md-3" x-show="deliveryMethod !== 'pickup'" x-cloak>
                        <label class="form-label">Статус доставки</label>
                        <select name="delivery_status" class="form-select">
                            <option value="new" <?php echo $isEdit && $order['delivery_status'] === 'new' ? 'selected' : ''; ?>>Нове</option>
                            <option value="sending" <?php echo $isEdit && $order['delivery_status'] === 'sending' ? 'selected' : ''; ?>>Відправляється</option>
                            <option value="in_transit" <?php echo $isEdit && $order['delivery_status'] === 'in_transit' ? 'selected' : ''; ?>>В дорозі</option>
                            <option value="arrived" <?php echo $isEdit && $order['delivery_status'] === 'arrived' ? 'selected' : ''; ?>>Прибуло у відділення</option>
                            <option value="delivered" <?php echo $isEdit && $order['delivery_status'] === 'delivered' ? 'selected' : ''; ?>>Видано одержувачу</option>
                            <option value="returned" <?php echo $isEdit && $order['delivery_status'] === 'returned' ? 'selected' : ''; ?>>Повернення</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Метод оплати</label>
                        <select name="payment_method" class="form-select">
                            <option value="">-- Без оплати --</option>
                            <option value="cash" <?php echo $isEdit && $order['payment_method'] === 'cash' ? 'selected' : ''; ?>>Готівка</option>
                            <option value="card" <?php echo $isEdit && $order['payment_method'] === 'card' ? 'selected' : ''; ?>>Картка</option>
                            <option value="fop" <?php echo $isEdit && $order['payment_method'] === 'fop' ? 'selected' : ''; ?>>ФОП</option>
                            <option value="invoice" <?php echo $isEdit && $order['payment_method'] === 'invoice' ? 'selected' : ''; ?>>Рахунок</option>
                            <option value="transfer" <?php echo $isEdit && $order['payment_method'] === 'transfer' ? 'selected' : ''; ?>>Переказ</option>
                            <option value="nova_poshta" <?php echo $isEdit && $order['payment_method'] === 'nova_poshta' ? 'selected' : ''; ?>>Нова Пошта (зворотня доставка)</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Дата</label>
                        <input type="date" name="order_date" class="form-control" value="<?php echo $isEdit ? ($order['order_date'] ?: date('Y-m-d', strtotime($order['date_added']))) : date('Y-m-d'); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Примітки</label>
                        <textarea name="notes" class="form-control" rows="3"><?php echo $isEdit ? escape($order['erp_notes']) : ''; ?></textarea>
                    </div>
                </div>

                <h6 class="fw-bold mb-2">Товари</h6>
                <div class="table-container">
                    <table class="table table-bordered">
                        <thead>
                            <tr>
                                <th style="width:35%;">Товар</th>
                                <th style="width:12%;">Кількість</th>
                                <th style="width:16%;">Ціна (<?php echo CURRENCY_CODE; ?>)</th>
                                <th style="width:14%;">Сума</th>
                                <th style="width:12%;">Собівартість</th>
                                <th style="width:5%;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="(item, idx) in items" :key="idx">
                            <tr :data-idx="idx">
                                <td class="position-relative">
                                    <input type="text" class="form-control form-control-sm product-autocomplete"
                                           placeholder="Пошук товару..." autocomplete="off"
                                           x-model="item.name"
                                           @input.debounce.300ms="searchProduct(idx, $event.target.value)"
                                           @focus="if(item.name) searchProduct(idx, item.name)"
                                           @keydown.escape="searchOpenIdx = -1"
                                           @blur="setTimeout(() => searchOpenIdx = -1, 200)">
                                    <input type="hidden" name="product_id[]" x-model="item.product_id">
                                    <div class="product-dropdown" x-cloak
                                         x-show="searchOpenIdx === idx && searchResults.length > 0"
                                         style="z-index:1060;background:#fff;border:1px solid #dee2e6;border-radius:4px;box-shadow:0 4px 12px rgba(0,0,0,0.15);overflow-y:auto;">
                                        <template x-for="p in searchResults" :key="p.product_id">
                                            <button type="button" class="dropdown-item"
                                                    @mousedown.prevent="selectProduct(p)">
                                                <span x-text="p.name"></span>
                                                <small class="text-muted" x-show="p.price_retail > 0"
                                                       x-text="' - ' + parseFloat(p.price_retail).toFixed(2) + ' ₴'"></small>
                                            </button>
                                        </template>
                                    </div>
                                    <div class="mt-1 small" x-show="item.prices && (item.prices.retail || item.prices.semi || item.prices.wholesale)">
                                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1"
                                                :class="{'active': item.price === item.prices.retail}"
                                                @click="setPrice(idx, 'retail')">Роздріб</button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1"
                                                :class="{'active': item.price === item.prices.semi}"
                                                @click="setPrice(idx, 'semi')">Дріб.опт</button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1"
                                                :class="{'active': item.price === item.prices.wholesale}"
                                                @click="setPrice(idx, 'wholesale')">Опт</button>
                                    </div>
                                </td>
                                <td><input type="number" name="quantity[]" class="form-control" x-model="item.qty" step="1" min="1" inputmode="numeric"></td>
                                <td>
                                    <input type="number" name="price[]" class="form-control price-input" x-model="item.price" step="0.01" min="0"
                                           :class="{'is-invalid': item.costPrice > 0 && item.price < item.costPrice}"
                                           @blur="if(item.costPrice > 0 && item.price < item.costPrice) { $el.classList.add('is-invalid'); } else { $el.classList.remove('is-invalid'); }">
                                </td>
                                <td><span class="fw-bold" x-text="(item.qty * item.price).toFixed(2)"></span></td>
                                <td>
                                    <span x-show="item.costPrice > 0" x-text="parseFloat(item.costPrice).toFixed(2)" :class="{'text-danger': item.price < item.costPrice}"></span>
                                    <span x-show="!item.costPrice" class="text-muted">—</span>
                                </td>
                                <td><button type="button" class="btn btn-outline-danger btn-sm" @click="removeItem(idx)"><i class="bi bi-trash"></i></button></td>
                            </tr>
                            </template>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td><button type="button" class="btn btn-sm btn-outline-primary" @click="addItem"><i class="bi bi-plus-lg"></i> Додати рядок</button></td>
                                <td colspan="2" class="text-end fw-bold">Всього:</td>
                                <td><span class="fw-bold" x-text="grandTotal.toFixed(2)">0.00</span></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <?php if (!$isEdit): ?>
                <div class="card mt-3">
                    <div class="card-header">Реєстрація оплати (необов'язково)</div>
                    <div class="card-body">
                        <div class="row g-2">
                            <div class="col-md-3">
                                <label class="form-label">Сума</label>
                                <input type="number" name="pay_amount" class="form-control" step="0.01" min="0">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Метод</label>
                                <select name="pay_method" class="form-select">
                                    <option value="cash">Готівка</option>
                                    <option value="card">Картка</option>
                                    <option value="fop">ФОП</option>
                                    <option value="invoice">Рахунок</option>
                                    <option value="transfer">Переказ</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Дата</label>
                                <input type="date" name="pay_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!$isEdit): ?>
                <div class="mt-3">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="send_notification" id="sendNotification" value="1" checked>
                        <label class="form-check-label" for="sendNotification">
                            <i class="bi bi-telegram"></i> Надіслати сповіщення в Telegram
                        </label>
                    </div>
                </div>
                <?php endif; ?>

                <button type="submit" class="btn btn-primary mt-3"><i class="bi bi-save"></i> <?php echo $submitLabel; ?></button>
            </form>
        </div>
    </div>
    <?php
    include __DIR__ . '/../includes/footer.php';
    exit;
}

if ($action === 'view' && $orderId) {
    $stmt = $pdo->prepare("SELECT * FROM erp_orders WHERE order_id = ?");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    if (!$order) { flashMessage('error', 'Замовлення не знайдено'); redirect(BASE_URL . '/modules/orders.php'); }

    syncOrderCustomer($pdo, $orderId, $order['customer_name'], $order['email'], $order['telephone']);

    $stmt = $pdo->prepare("SELECT op.*, p.name as product_name FROM erp_order_products op LEFT JOIN erp_products p ON op.product_id = p.product_id WHERE op.order_id = ?");
    $stmt->execute([$orderId]);
    $items = $stmt->fetchAll();
    $totalCost = 0;
    $totalProfit = 0;
    foreach ($items as $item) {
        $totalCost += (float)$item['cost_price'] * (float)$item['quantity'];
        $totalProfit += (float)$item['profit'];
    }

    $stmt = $pdo->prepare("SELECT * FROM erp_payments WHERE order_id = ? ORDER BY date_added ASC");
    $stmt->execute([$orderId]);
    $payments = $stmt->fetchAll();
    $totalPaid = 0;
    foreach ($payments as $pmt) { $totalPaid += (float)$pmt['amount']; }
    $balance = (float)$order['total'] - $totalPaid;
    $methodLabels = ['cash' => 'Готівка', 'card' => 'Картка', 'fop' => 'ФОП', 'invoice' => 'Рахунок', 'transfer' => 'Переказ', 'nova_poshta' => 'Нова Пошта (зворотня доставка)'];

    $statusOptions = ['pending' => 'Очікує', 'approved' => 'Підтверджено', 'processed' => 'В обробці', 'shipped' => 'Відправлено', 'delivered' => 'Доставлено', 'cancelled' => 'Скасовано'];
    $statusClasses = ['pending' => 'bg-warning text-dark', 'approved' => 'bg-info', 'processed' => 'bg-primary', 'shipped' => 'bg-secondary', 'delivered' => 'bg-success', 'cancelled' => 'bg-danger'];
    $deliveryStatusOptions = ['new' => 'Нове', 'sending' => 'Відправляється', 'in_transit' => 'В дорозі', 'arrived' => 'Прибуло у відділення', 'delivered' => 'Видано одержувачу', 'returned' => 'Повернення'];
    $deliveryStatusClasses = ['new' => 'bg-secondary', 'sending' => 'bg-info', 'in_transit' => 'bg-primary', 'arrived' => 'bg-warning text-dark', 'delivered' => 'bg-success', 'returned' => 'bg-danger'];
    $canEdit = true;

    include __DIR__ . '/../includes/header.php';
    ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0"><i class="bi bi-cart3"></i> Замовлення #<?php echo (int)$order['order_id']; ?></h4>
        <div>
            <a href="<?php echo BASE_URL; ?>/modules/orders.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Назад</a>
            <?php if ($canEdit): ?>
            <a href="<?php echo BASE_URL; ?>/modules/orders.php?action=edit&id=<?php echo $orderId; ?>" class="btn btn-warning btn-sm"><i class="bi bi-pencil"></i> Редагувати</a>
            <?php endif; ?>
            <a href="<?php echo BASE_URL; ?>/modules/orders.php?action=invoice&id=<?php echo $orderId; ?>" class="btn btn-outline-primary btn-sm" target="_blank"><i class="bi bi-file-text"></i> Накладна</a>
            <a href="<?php echo BASE_URL; ?>/modules/orders.php?action=receipt&id=<?php echo $orderId; ?>" class="btn btn-outline-success btn-sm" target="_blank"><i class="bi bi-printer"></i> Чек</a>
            <?php if (isAdmin()): ?>
            <a href="<?php echo BASE_URL; ?>/modules/orders.php?action=delete&id=<?php echo $orderId; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Видалити замовлення?')"><i class="bi bi-trash"></i></a>
            <?php endif; ?>
        </div>
    </div>
    <div class="row g-3">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">Деталі замовлення</div>
                <div class="card-body">
                    <?php
                        $nameParts = explode(' ', trim($order['customer_name']), 2);
                        $firstname = $nameParts[0] ?? '';
                        $lastname = $nameParts[1] ?? '';
                        $deliveryLabels = ['pickup' => 'Самовивіз', 'courier' => 'Кур\'єр', 'nova_poshta' => 'Нова Пошта', 'delivery' => 'Делівері', 'ukrposhta' => 'Укрпошта'];
                    ?>
                    <div class="row g-3 mb-3">
                        <div class="col-md-3">
                            <small class="text-muted">Ім'я</small>
                            <div class="fw-bold"><?php echo escape($firstname); ?></div>
                        </div>
                        <div class="col-md-3">
                            <small class="text-muted">Фамілія</small>
                            <div class="fw-bold"><?php echo escape($lastname ?: '-'); ?></div>
                        </div>
                        <div class="col-md-3">
                            <small class="text-muted">Телефон</small>
                            <div><?php
                                $phone = $order['telephone'] ?? '';
                                if ($phone):
                                    $digits = preg_replace('/\D/', '', $phone);
                                    if (strlen($digits) === 10 && $digits[0] === '0') $digits = '38' . $digits;
                                    elseif (strlen($digits) === 9) $digits = '380' . $digits;
                                    elseif (strpos($digits, '380') !== 0) $digits = '380' . $digits;
                                    echo escape($phone);
                                    echo ' <a href="tg://resolve?phone=' . $digits . '" target="_blank" class="text-decoration-none" title="Telegram"><i class="bi bi-telegram" style="color:#0088cc;vertical-align:middle;"></i></a>';
                                    echo ' <a href="viber://chat?number=' . $digits . '" target="_blank" class="text-decoration-none" title="Viber"><svg width="16" height="16" viewBox="0 0 24 24" style="vertical-align:middle;"><rect width="24" height="24" rx="4" fill="#7360F2"/><path d="M17.4 14.1c-.4-.4-.9-.6-1.4-.6s-1 .2-1.4.6l-1.1 1.1c-1.7-.9-3.1-2.1-4.1-3.7l1.1-1.1c.4-.4.6-.9.6-1.4s-.2-1-.6-1.4l-1-1c-.4-.4-.9-.6-1.4-.6s-1 .2-1.4.6L5.5 8.9c-.7.7-1 1.6-1 2.6 0 3.3 2.5 7.5 7.5 7.5 1 0 1.9-.4 2.6-1l1.1-1.1c.4-.4.6-.9.6-1.4s-.2-1-.6-1.4l-1-1z" fill="white"/></svg></a>';
                                else:
                                    echo '-';
                                endif;
                            ?></div>
                        </div>
                        <div class="col-md-3">
                            <small class="text-muted">Email</small>
                            <div><?php echo escape($order['email'] ?: '-'); ?></div>
                        </div>
                        <div class="col-md-3">
                            <small class="text-muted">Статус</small>
                            <div><?php $sn = strtolower($order['status_name']); echo '<span class="badge ' . ($statusClasses[$sn] ?? 'bg-secondary') . '">' . ($statusOptions[$sn] ?? $sn) . '</span>'; ?></div>
                        </div>
                        <div class="col-md-3">
                            <small class="text-muted">Метод оплати</small>
                            <div><?php echo $order['payment_method'] ? ($methodLabels[$order['payment_method']] ?? $order['payment_method']) : '-'; ?></div>
                        </div>
                        <div class="col-md-3">
                            <small class="text-muted">Доставка</small>
                            <div><?php echo $deliveryLabels[$order['delivery_method']] ?? escape($order['delivery_method'] ?: '-'); ?></div>
                        </div>
                        <div class="col-md-3">
                            <small class="text-muted">Дата</small>
                            <div><?php echo $order['order_date'] ? formatDateShort($order['order_date']) : formatDate($order['date_added']); ?></div>
                        </div>
                        <div class="col-12">
                            <small class="text-muted">Адреса доставки</small>
                            <div><?php
                                if ($order['delivery_method'] === 'courier') {
                                    $addr = trim($order['delivery_street'] . ', буд. ' . $order['delivery_building'] . ($order['delivery_apartment'] ? ', ' . $order['delivery_apartment'] : ''));
                                    echo escape($addr ?: $order['delivery_address'] ?: '-');
                                } elseif (in_array($order['delivery_method'], ['nova_poshta', 'delivery', 'ukrposhta'])) {
                                    $addr = trim($order['delivery_city'] . ($order['delivery_office'] ? ', від. ' . $order['delivery_office'] : ''));
                                    echo escape($addr ?: $order['delivery_address'] ?: '-');
                                } else {
                                    echo escape($order['delivery_address'] ?: '-');
                                }
                            ?></div>
                        </div>
                        <?php if ($order['delivery_method'] !== 'pickup'): ?>
                        <div class="row g-3 mb-3">
                            <div class="col-md-4">
                                <small class="text-muted">Номер ТТН</small>
                                <div class="fw-bold"><?php echo $order['ttn_number'] ? escape($order['ttn_number']) : '-'; ?></div>
                            </div>
                            <div class="col-md-4">
                                <small class="text-muted">Статус доставки</small>
                                <div><?php
                                    $ds = $order['delivery_status'] ?: 'new';
                                    echo '<span class="badge ' . ($deliveryStatusClasses[$ds] ?? 'bg-secondary') . '">' . ($deliveryStatusOptions[$ds] ?? $ds) . '</span>';
                                ?></div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <div class="col-12">
                            <small class="text-muted">Примітки</small>
                            <div><?php echo escape($order['erp_notes'] ?: '-'); ?></div>
                        </div>
                    </div>
                    <div class="table-container">
                        <table class="table table-hover mb-0">
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
                                    <td class="text-center"><?php echo (float)$item['quantity']; ?></td>
                                    <td class="text-end"><?php echo formatMoney($item['price']); ?></td>
                                    <td class="text-end"><?php echo formatMoney($item['total']); ?></td>
                                    <td class="text-end"><?php echo $item['cost_price'] ? formatMoney($item['cost_price']) : '-'; ?></td>
                                    <td class="text-end <?php echo $item['profit'] > 0 ? 'text-success' : ($item['profit'] < 0 ? 'text-danger' : ''); ?>"><?php echo formatMoney($item['profit']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr class="fw-bold">
                                    <td colspan="3">Разом</td>
                                    <td class="text-end"><?php echo formatMoney($order['total']); ?></td>
                                    <td class="text-end"><?php echo formatMoney($totalCost); ?></td>
                                    <td class="text-end <?php echo $totalProfit > 0 ? 'text-success' : ($totalProfit < 0 ? 'text-danger' : ''); ?>"><?php echo formatMoney($totalProfit); ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
            <?php if (isManager()): ?>
            <div class="card mt-3">
                <div class="card-header">Змінити статус</div>
                <div class="card-body">
                    <div class="d-flex gap-2 flex-wrap">
                        <?php foreach ($statusOptions as $val => $label): ?>
                        <?php if ($val !== strtolower($order['status_name'])): ?>
                        <a href="<?php echo BASE_URL; ?>/modules/orders.php?action=status&id=<?php echo $orderId; ?>&status=<?php echo $val; ?>" class="btn btn-sm btn-outline-<?php echo $val === 'cancelled' ? 'danger' : 'primary'; ?>" onclick="return confirm('Змінити статус на \"<?php echo $label; ?>\"?')"><?php echo $label; ?></a>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <div class="col-md-4">
            <div class="card">
                <div class="card-header">Оплати</div>
                <div class="card-body p-0">
                    <?php if (count($payments) > 0): ?>
                    <table class="table table-sm mb-0">
                        <thead>
                            <tr><th>Дата</th><th>Метод</th><th class="text-end">Сума</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payments as $pmt): ?>
                            <tr>
                                <td><?php echo formatDateShort($pmt['date']); ?></td>
                                <td><?php echo $methodLabels[$pmt['method']] ?? $pmt['method']; ?></td>
                                <td class="text-end fw-bold text-success"><?php echo formatMoney($pmt['amount']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="fw-bold"><td colspan="2">Сплачено</td><td class="text-end text-success"><?php echo formatMoney($totalPaid); ?></td></tr>
                            <tr class="fw-bold <?php echo $balance > 0 ? 'text-warning' : 'text-success'; ?>"><td colspan="2">Залишок</td><td class="text-end"><?php echo formatMoney($balance); ?></td></tr>
                        </tfoot>
                    </table>
                    <?php else: ?>
                    <div class="text-center py-3 text-muted">Оплат ще немає</div>
                    <?php endif; ?>
                </div>
                <?php if (isManager()): ?>
                <div class="card-footer">
                    <form method="post" action="?action=pay&id=<?php echo $orderId; ?>" class="row g-2">
                        <div class="col-6">
                            <input type="number" name="amount" class="form-control form-control-sm" step="0.01" min="0.01" placeholder="Сума" required>
                        </div>
                        <div class="col-6">
                            <select name="method" class="form-select form-select-sm">
                                <option value="cash">Готівка</option>
                                <option value="card">Картка</option>
                                <option value="fop">ФОП</option>
                                <option value="invoice">Рахунок</option>
                                <option value="transfer">Переказ</option>
                                <option value="nova_poshta">Нова Пошта (зворотня доставка)</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <input type="date" name="date" class="form-control form-control-sm" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="col-6">
                            <button type="submit" class="btn btn-success btn-sm w-100"><i class="bi bi-cash"></i> Додати платіж</button>
                        </div>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
    include __DIR__ . '/../includes/footer.php';
    exit;
}

// LIST VIEW
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

$statusOptions = ['pending' => 'Очікує', 'approved' => 'Підтверджено', 'processed' => 'В обробці', 'shipped' => 'Відправлено', 'delivered' => 'Доставлено', 'cancelled' => 'Скасовано'];
$statusClasses = ['pending' => 'bg-warning text-dark', 'approved' => 'bg-info', 'processed' => 'bg-primary', 'shipped' => 'bg-secondary', 'delivered' => 'bg-success', 'cancelled' => 'bg-danger'];
$deliveryStatusOptions = ['new' => 'Нове', 'sending' => 'Відправляється', 'in_transit' => 'В дорозі', 'arrived' => 'Прибуло у відділення', 'delivered' => 'Видано одержувачу', 'returned' => 'Повернення'];
$deliveryStatusClasses = ['new' => 'bg-secondary', 'sending' => 'bg-info', 'in_transit' => 'bg-primary', 'arrived' => 'bg-warning text-dark', 'delivered' => 'bg-success', 'returned' => 'bg-danger'];

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="bi bi-cart3"></i> Замовлення</h4>
    <div class="d-flex gap-2">
        <form method="get" class="d-flex gap-2">
            <select name="status" class="form-select form-select-sm" style="width:auto;" onchange="this.form.submit()">
                <option value="">Усі статуси</option>
                <?php foreach ($statuses as $s): ?>
                <option value="<?php echo escape($s['status_name']); ?>" <?php echo $statusFilter === $s['status_name'] ? 'selected' : ''; ?>><?php echo escape($s['status_name']); ?></option>
                <?php endforeach; ?>
            </select>
            <input type="search" name="search" class="form-control form-control-sm search-box" placeholder="Номер або ім'я..." value="<?php echo escape($search); ?>">
            <button class="btn btn-sm btn-outline-primary"><i class="bi bi-search"></i></button>
        </form>
        <a href="<?php echo BASE_URL; ?>/modules/orders.php?action=create" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> Нове замовлення</a>
    </div>
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
                        <th>ТТН</th>
                        <th>Доставка</th>
                        <th>Оплата</th>
                        <th>Дата</th>
                        <th class="text-center">Дії</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $o):
                        $pmtStmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM erp_payments WHERE order_id=?");
                        $pmtStmt->execute([$o['order_id']]);
                        $paid = (float)$pmtStmt->fetchColumn();
                        $debt = (float)$o['total'] - $paid;
                        $canEditRow = true;
                    ?>
                    <tr>
                        <td><?php echo (int)$o['order_id']; ?></td>
                        <td class="fw-bold"><?php echo escape($o['customer_name'] ?: ($o['firstname'] . ' ' . $o['lastname'])); ?></td>
                        <td><?php echo escape($o['email'] ?: '-'); ?></td>
                        <td><?php echo escape($o['telephone'] ?: '-'); ?></td>
                        <td class="text-end fw-bold"><?php echo formatMoney($o['total']); ?></td>
                        <td><?php $sn = strtolower($o['status_name']); echo '<span class="badge ' . ($statusClasses[$sn] ?? 'bg-secondary') . '">' . ($statusOptions[$sn] ?? $sn) . '</span>'; ?></td>
                        <td><?php echo $o['ttn_number'] ? '<code>' . escape($o['ttn_number']) . '</code>' : '-'; ?></td>
                        <td><?php $ds = $o['delivery_status'] ?: 'new'; echo '<span class="badge ' . ($deliveryStatusClasses[$ds] ?? 'bg-secondary') . '">' . ($deliveryStatusOptions[$ds] ?? $ds) . '</span>'; ?></td>
                        <td><?php echo $debt <= 0 ? '<span class="badge bg-success">Оплачено</span>' : '<span class="badge bg-danger">Не оплачено</span>'; ?></td>
                        <td><?php echo $o['order_date'] ? formatDateShort($o['order_date']) : formatDate($o['date_added']); ?></td>
                        <td class="text-center">
                            <a href="<?php echo BASE_URL; ?>/modules/orders.php?action=view&id=<?php echo $o['order_id']; ?>" class="btn btn-sm btn-outline-primary" title="Переглянути"><i class="bi bi-eye"></i></a>
                            <?php if ($canEditRow): ?>
                            <a href="<?php echo BASE_URL; ?>/modules/orders.php?action=edit&id=<?php echo $o['order_id']; ?>" class="btn btn-sm btn-outline-warning" title="Редагувати"><i class="bi bi-pencil"></i></a>
                            <?php endif; ?>
                            <?php if (isAdmin()): ?>
                            <a href="<?php echo BASE_URL; ?>/modules/orders.php?action=delete&id=<?php echo $o['order_id']; ?>" class="btn btn-sm btn-outline-danger" title="Видалити" onclick="return confirm('Видалити замовлення #<?php echo $o['order_id']; ?>?')"><i class="bi bi-trash"></i></a>
                            <?php endif; ?>
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
            <a href="<?php echo BASE_URL; ?>/modules/orders.php?action=create" class="btn btn-primary mt-3">Створити перше</a>
        </div>
        <?php endif; ?>
    </div>
    <?php if ($pagination['total_pages'] > 1): ?>
    <div class="card-footer"><?php echo renderPagination(BASE_URL . '/modules/orders.php?', $pagination); ?></div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
