<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../_helpers.php';
requireLogin();

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
    $deliveryCity = trim($_POST['delivery_city'] ?? '');
    $deliveryStreet = trim($_POST['delivery_street'] ?? '');
    $deliveryBuilding = trim($_POST['delivery_building'] ?? '');
    $deliveryApartment = trim($_POST['delivery_apartment'] ?? '');
    $deliveryOffice = trim($_POST['delivery_office'] ?? '');
    $deliveryAddress = $deliveryMethod === 'courier'
        ? trim("$deliveryStreet, буд. $deliveryBuilding" . ($deliveryApartment ? ", $deliveryApartment" : ''))
        : trim($deliveryCity . ($deliveryOffice ? ', від. ' . $deliveryOffice : ''));
    $orderDate = $_POST['order_date'] ?? date('Y-m-d');
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
            $stmt = $pdo->prepare("UPDATE erp_orders SET customer_name=?, email=?, telephone=?, status_name=?, erp_notes=?, payment_method=?, delivery_method=?, delivery_address=?, delivery_city=?, delivery_street=?, delivery_building=?, delivery_apartment=?, delivery_office=?, order_date=? WHERE order_id=?");
            $stmt->execute([$customerName, $email, $telephone, $statusName, $notes, $paymentMethod, $deliveryMethod, $deliveryAddress, $deliveryCity, $deliveryStreet, $deliveryBuilding, $deliveryApartment, $deliveryOffice, $orderDate, $orderId]);

            $oldProducts = $pdo->prepare("SELECT product_id, quantity FROM erp_order_products WHERE order_id=?");
            $oldProducts->execute([$orderId]);
            foreach ($oldProducts as $op) {
                $stmt = $pdo->prepare("INSERT INTO erp_stock_moves (product_id, type, quantity, notes, user_id) VALUES (?, 'return_in', ?, ?, ?)");
                $stmt->execute([$op['product_id'], $op['quantity'], 'Повернення з редагування замовлення #' . $orderId, $user['user_id']]);
            }
            $pdo->prepare("DELETE FROM erp_order_products WHERE order_id=?")->execute([$orderId]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO erp_orders (customer_name, email, telephone, status_name, total, erp_notes, payment_method, delivery_method, delivery_address, delivery_city, delivery_street, delivery_building, delivery_apartment, delivery_office, order_date) VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$customerName, $email, $telephone, $statusName, $notes, $paymentMethod, $deliveryMethod, $deliveryAddress, $deliveryCity, $deliveryStreet, $deliveryBuilding, $deliveryApartment, $deliveryOffice, $orderDate]);
            $orderId = (int)$pdo->lastInsertId();
        }

        $total = 0;
        for ($i = 0; $i < count($productIds); $i++) {
            $pid = (int)($productIds[$i] ?? 0);
            $qty = (float)($quantities[$i] ?? 0);
            $price = (float)($prices[$i] ?? 0);
            if ($pid <= 0 || $qty <= 0) continue;

            $lineTotal = $qty * $price;
            $total += $lineTotal;

            $costPrice = getProductCostPrice($pdo, $pid);
            $profit = $lineTotal - ($costPrice * $qty);

            $stmt = $pdo->prepare("INSERT INTO erp_order_products (order_id, product_id, name, quantity, price, total, cost_price, profit) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$orderId, $pid, '', $qty, $price, $lineTotal, $costPrice, $profit]);

            if (in_array($statusName, ['processed', 'completed', 'shipped', 'delivered'])) {
                $stmt = $pdo->prepare("INSERT INTO erp_stock_moves (product_id, type, quantity, cost_price, reference_type, reference_id, user_id, notes) VALUES (?, 'out', ?, ?, 'order', ?, ?, ?)");
                $stmt->execute([$pid, $qty, $costPrice, $orderId, $user['user_id'], 'Замовлення #' . $orderId]);
            }
        }

        $pdo->prepare("UPDATE erp_orders SET total=? WHERE order_id=?")->execute([$total, $orderId]);

        if ($payAmount > 0) {
            $stmt = $pdo->prepare("INSERT INTO erp_payments (order_id, amount, method, date, notes, user_id) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$orderId, $payAmount, $payMethod, $payDate, 'Оплата замовлення #' . $orderId, $user['user_id']]);
        }

        $pdo->commit();
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

if ($action === 'create' || $action === 'edit') {
    $isEdit = ($action === 'edit' && $orderId);
    $order = [];
    $items = [];

    $stmt = $pdo->query("SELECT product_id, name, model, sku, price_retail, price_wholesale, price_semi_wholesale FROM erp_products WHERE status = 1 ORDER BY name ASC LIMIT 1000");
    $products = $stmt->fetchAll();

    if ($isEdit) {
        $stmt = $pdo->prepare("SELECT * FROM erp_orders WHERE order_id=?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();
        if (!$order) { flashMessage('error', 'Замовлення не знайдено'); redirect(BASE_URL . '/modules/orders.php'); }
        $stmt = $pdo->prepare("SELECT * FROM erp_order_products WHERE order_id=?");
        $stmt->execute([$orderId]);
        $items = $stmt->fetchAll();
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
            <form method="post" action="?action=<?php echo $formAction; ?>" id="orderForm">
                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <label class="form-label required">Ім'я</label>
                        <input type="text" name="firstname" class="form-control" required value="<?php echo $isEdit ? explode(' ', trim($order['customer_name']))[0] : ''; ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Фамілія</label>
                        <input type="text" name="lastname" class="form-control" value="<?php echo $isEdit ? (strpos(trim($order['customer_name']), ' ') !== false ? substr(trim($order['customer_name']), strpos(trim($order['customer_name']), ' ') + 1) : '') : ''; ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Телефон</label>
                        <input type="tel" name="telephone" class="form-control" value="<?php echo $isEdit ? escape($order['telephone']) : ''; ?>" pattern="^\+?380[0-9]{9}$|^0[0-9]{9}$" title="Формат: +380XXXXXXXXX або 0XXXXXXXXX">
                        <small class="text-muted">+380XXXXXXXXX або 0XXXXXXXXX</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" value="<?php echo $isEdit ? escape($order['email']) : ''; ?>">
                    </div>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <label class="form-label">Спосіб доставки</label>
                        <select name="delivery_method" class="form-select" id="deliveryMethod" onchange="toggleDeliveryFields()">
                            <option value="">-- Виберіть --</option>
                            <option value="pickup" <?php echo $isEdit && $order['delivery_method'] === 'pickup' ? 'selected' : ''; ?>>Самовивіз</option>
                            <option value="courier" <?php echo $isEdit && $order['delivery_method'] === 'courier' ? 'selected' : ''; ?>>Кур'єр</option>
                            <option value="nova_poshta" <?php echo $isEdit && $order['delivery_method'] === 'nova_poshta' ? 'selected' : ''; ?>>Нова Пошта</option>
                            <option value="delivery" <?php echo $isEdit && $order['delivery_method'] === 'delivery' ? 'selected' : ''; ?>>Делівері</option>
                            <option value="ukrposhta" <?php echo $isEdit && $order['delivery_method'] === 'ukrposhta' ? 'selected' : ''; ?>>Укрпошта</option>
                        </select>
                    </div>
                    <div class="col-md-3 delivery-field delivery-courier">
                        <label class="form-label">Вулиця</label>
                        <input type="text" name="delivery_street" class="form-control" value="<?php echo $isEdit ? escape($order['delivery_street']) : ''; ?>" placeholder="Назва вулиці">
                    </div>
                    <div class="col-md-2 delivery-field delivery-courier">
                        <label class="form-label">Будинок</label>
                        <input type="text" name="delivery_building" class="form-control" value="<?php echo $isEdit ? escape($order['delivery_building']) : ''; ?>" placeholder="№">
                    </div>
                    <div class="col-md-2 delivery-field delivery-courier">
                        <label class="form-label">Квартира/офіс</label>
                        <input type="text" name="delivery_apartment" class="form-control" value="<?php echo $isEdit ? escape($order['delivery_apartment']) : ''; ?>" placeholder="№">
                    </div>
                    <div class="col-md-3 delivery-field delivery-post">
                        <label class="form-label">Місто</label>
                        <input type="text" name="delivery_city" class="form-control" value="<?php echo $isEdit ? escape($order['delivery_city']) : ''; ?>" placeholder="Місто">
                    </div>
                    <div class="col-md-3 delivery-field delivery-post">
                        <label class="form-label">Відділення</label>
                        <input type="text" name="delivery_office" class="form-control" value="<?php echo $isEdit ? escape($order['delivery_office']) : ''; ?>" placeholder="№ відділення / адреса">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Статус</label>
                        <select name="status_name" class="form-select">
                            <?php foreach ($statusOptions as $val => $label): ?>
                            <option value="<?php echo $val; ?>" <?php echo $isEdit && $order['status_name'] === $val ? 'selected' : ''; ?>><?php echo $label; ?></option>
                            <?php endforeach; ?>
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
                    <table class="table table-bordered" id="itemsTable">
                        <thead>
                            <tr>
                                <th style="width:40%;">Товар</th>
                                <th style="width:15%;">Кількість</th>
                                <th style="width:20%;">Ціна (<?php echo CURRENCY_CODE; ?>)</th>
                                <th style="width:20%;">Сума</th>
                                <th style="width:5%;"></th>
                            </tr>
                        </thead>
                        <tbody id="itemsBody">
                            <?php if ($isEdit && count($items) > 0): ?>
                                <?php foreach ($items as $item): ?>
                                <tr>
                                    <td>
                                        <select name="product_id[]" class="form-select product-select" required>
                                            <option value="">-- Виберіть --</option>
                                            <?php foreach ($products as $p): ?>
                                            <option value="<?php echo $p['product_id']; ?>" data-retail="<?php echo (float)$p['price_retail']; ?>" data-wholesale="<?php echo (float)$p['price_wholesale']; ?>" data-semi="<?php echo (float)$p['price_semi_wholesale']; ?>" <?php echo $item['product_id'] == $p['product_id'] ? 'selected' : ''; ?>><?php echo escape($p['name'] ?: 'ID: ' . $p['product_id']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td><input type="number" name="quantity[]" class="form-control" step="0.01" min="0.01" required value="<?php echo (float)$item['quantity']; ?>"></td>
                                    <td><input type="number" name="price[]" class="form-control price-input" step="0.01" min="0" required value="<?php echo (float)$item['price']; ?>"></td>
                                    <td><span class="line-total fw-bold"><?php echo number_format((float)$item['total'], 2); ?></span></td>
                                    <td><button type="button" class="btn btn-outline-danger btn-sm remove-item"><i class="bi bi-trash"></i></button></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                            <tr>
                                <td>
                                    <select name="product_id[]" class="form-select product-select" required>
                                        <option value="">-- Виберіть --</option>
                                        <?php foreach ($products as $p): ?>
                                        <option value="<?php echo $p['product_id']; ?>" data-retail="<?php echo (float)$p['price_retail']; ?>" data-wholesale="<?php echo (float)$p['price_wholesale']; ?>" data-semi="<?php echo (float)$p['price_semi_wholesale']; ?>"><?php echo escape($p['name'] ?: 'ID: ' . $p['product_id']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td><input type="number" name="quantity[]" class="form-control" step="0.01" min="0.01" required></td>
                                <td><input type="number" name="price[]" class="form-control price-input" step="0.01" min="0" required></td>
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

                <button type="submit" class="btn btn-primary mt-3"><i class="bi bi-save"></i> <?php echo $submitLabel; ?></button>
            </form>
        </div>
    </div>
    <script>
    function toggleDeliveryFields() {
        const method = document.getElementById('deliveryMethod')?.value;
        document.querySelectorAll('.delivery-field').forEach(el => el.style.display = 'none');
        if (method === 'courier') {
            document.querySelectorAll('.delivery-courier').forEach(el => el.style.display = 'block');
        } else if (method === 'nova_poshta' || method === 'delivery' || method === 'ukrposhta') {
            document.querySelectorAll('.delivery-post').forEach(el => el.style.display = 'block');
        }
    }
    toggleDeliveryFields();
    document.getElementById('addItem')?.addEventListener('click', function() {
        const tbody = document.getElementById('itemsBody');
        const firstRow = tbody.querySelector('tr');
        const newRow = firstRow.cloneNode(true);
        newRow.querySelectorAll('input').forEach(i => i.value = '');
        newRow.querySelector('select').selectedIndex = 0;
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
        if (e.target.classList.contains('price-input') || e.target.name.includes('quantity')) {
            const row = e.target.closest('tr');
            if (row) {
                const qty = parseFloat(row.querySelector('[name*="quantity"]').value) || 0;
                const price = parseFloat(row.querySelector('.price-input').value) || 0;
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

if ($action === 'view' && $orderId) {
    $stmt = $pdo->prepare("SELECT * FROM erp_orders WHERE order_id = ?");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    if (!$order) { flashMessage('error', 'Замовлення не знайдено'); redirect(BASE_URL . '/modules/orders.php'); }

    $stmt = $pdo->prepare("SELECT op.*, p.name as product_name FROM erp_order_products op LEFT JOIN erp_products p ON op.product_id = p.product_id WHERE op.order_id = ?");
    $stmt->execute([$orderId]);
    $items = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT * FROM erp_payments WHERE order_id = ? ORDER BY date_added ASC");
    $stmt->execute([$orderId]);
    $payments = $stmt->fetchAll();
    $totalPaid = 0;
    foreach ($payments as $pmt) { $totalPaid += (float)$pmt['amount']; }
    $balance = (float)$order['total'] - $totalPaid;
    $methodLabels = ['cash' => 'Готівка', 'card' => 'Картка', 'fop' => 'ФОП', 'invoice' => 'Рахунок', 'transfer' => 'Переказ'];

    $statusOptions = ['pending' => 'Очікує', 'approved' => 'Підтверджено', 'processed' => 'В обробці', 'shipped' => 'Відправлено', 'delivered' => 'Доставлено', 'cancelled' => 'Скасовано'];
    $statusClasses = ['pending' => 'bg-warning text-dark', 'approved' => 'bg-info', 'processed' => 'bg-primary', 'shipped' => 'bg-secondary', 'delivered' => 'bg-success', 'cancelled' => 'bg-danger'];
    $canEdit = in_array($order['status_name'], ['pending', 'approved']);

    include __DIR__ . '/../includes/header.php';
    ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0"><i class="bi bi-cart3"></i> Замовлення #<?php echo (int)$order['order_id']; ?></h4>
        <div>
            <a href="<?php echo BASE_URL; ?>/modules/orders.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Назад</a>
            <?php if ($canEdit): ?>
            <a href="<?php echo BASE_URL; ?>/modules/orders.php?action=edit&id=<?php echo $orderId; ?>" class="btn btn-warning btn-sm"><i class="bi bi-pencil"></i> Редагувати</a>
            <?php endif; ?>
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
                            <div><?php echo escape($order['telephone'] ?: '-'); ?></div>
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
                                    <td></td>
                                    <td></td>
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
                    ?>
                    <tr>
                        <td><?php echo (int)$o['order_id']; ?></td>
                        <td class="fw-bold"><?php echo escape($o['customer_name'] ?: ($o['firstname'] . ' ' . $o['lastname'])); ?></td>
                        <td><?php echo escape($o['email'] ?: '-'); ?></td>
                        <td><?php echo escape($o['telephone'] ?: '-'); ?></td>
                        <td class="text-end fw-bold"><?php echo formatMoney($o['total']); ?></td>
                        <td><?php $sn = strtolower($o['status_name']); echo '<span class="badge ' . ($statusClasses[$sn] ?? 'bg-secondary') . '">' . ($statusOptions[$sn] ?? $sn) . '</span>'; ?></td>
                        <td><?php echo $debt <= 0 ? '<span class="badge bg-success">Оплачено</span>' : '<span class="badge bg-danger">Не оплачено</span>'; ?></td>
                        <td><?php echo $o['order_date'] ? formatDateShort($o['order_date']) : formatDate($o['date_added']); ?></td>
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
            <a href="<?php echo BASE_URL; ?>/modules/orders.php?action=create" class="btn btn-primary mt-3">Створити перше</a>
        </div>
        <?php endif; ?>
    </div>
    <?php if ($pagination['total_pages'] > 1): ?>
    <div class="card-footer"><?php echo renderPagination(BASE_URL . '/modules/orders.php?', $pagination); ?></div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
