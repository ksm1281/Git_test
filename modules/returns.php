<?php
require_once __DIR__ . '/../config.php';
requireLogin();

$action = $_GET['action'] ?? 'list';
$returnId = (int)($_GET['id'] ?? 0);
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$statusFilter = $_GET['status'] ?? '';
$user = getUserData();

if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $orderId = (int)($_POST['order_id'] ?? 0);
    $productId = (int)($_POST['product_id'] ?? 0);
    $productName = trim($_POST['product_name'] ?? '');
    $quantity = (int)($_POST['quantity'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');
    $actionType = $_POST['action'] ?? 'refund';
    $restock = (int)($_POST['restock'] ?? 0);

    if (!$orderId || !$productId || $quantity <= 0) {
        flashMessage('error', 'Заповніть обов\'язкові поля');
        redirect(BASE_URL . '/modules/returns.php?action=create');
    }

    $stmt = $pdo->prepare("INSERT INTO erp_returns (order_id, product_id, product_name, quantity, reason, action, restock, status, user_id) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?)");
    $stmt->execute([$orderId, $productId, $productName, $quantity, $reason, $actionType, $restock, $user['user_id']]);
    flashMessage('success', 'Повернення зареєстровано');
    redirect(BASE_URL . '/modules/returns.php');
}

if ($action === 'approve' && $returnId && isManager()) {
    $pdo->prepare("UPDATE erp_returns SET status = 'approved' WHERE return_id = ?")->execute([$returnId]);
    flashMessage('success', 'Повернення схвалено');
    redirect(BASE_URL . '/modules/returns.php');
}

if ($action === 'reject' && $returnId && isManager()) {
    $pdo->prepare("UPDATE erp_returns SET status = 'rejected' WHERE return_id = ?")->execute([$returnId]);
    flashMessage('success', 'Повернення відхилено');
    redirect(BASE_URL . '/modules/returns.php');
}

if ($action === 'process' && $returnId && isManager()) {
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT * FROM erp_returns WHERE return_id = ? AND status = 'approved'");
        $stmt->execute([$returnId]);
        $ret = $stmt->fetch();

        if ($ret) {
            if ($ret['restock']) {
                $stmt = $pdo->prepare("INSERT INTO erp_stock_moves (product_id, type, quantity, reference_type, reference_id, user_id, notes) VALUES (?, 'return_in', ?, 'return', ?, ?, 'Повернення #' . ?)");
                $stmt->execute([$ret['product_id'], $ret['quantity'], $returnId, $user['user_id'], $returnId]);
            }
            $stmt = $pdo->prepare("UPDATE erp_returns SET status = 'processed', date_processed = NOW() WHERE return_id = ?");
            $stmt->execute([$returnId]);
        }
        $pdo->commit();
        flashMessage('success', 'Повернення оброблено');
    } catch (Exception $e) {
        $pdo->rollBack();
        flashMessage('error', 'Помилка: ' . $e->getMessage());
    }
    redirect(BASE_URL . '/modules/returns.php');
}

$where = [];
$params = [];
if ($statusFilter) {
    $where[] = "r.status = ?";
    $params[] = $statusFilter;
}
$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countSql = "SELECT COUNT(*) FROM erp_returns r $whereClause";
$stmt = $pdo->prepare($countSql);
$stmt->execute($params);
$total = $stmt->fetchColumn();
$pagination = paginate($total, $perPage, $page);

$sql = "SELECT r.*, p.name as product_name FROM erp_returns r LEFT JOIN erp_products p ON r.product_id = p.product_id $whereClause ORDER BY r.date_added DESC LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$returns = $stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="bi bi-arrow-return-left"></i> Повернення</h4>
    <div class="d-flex gap-2">
        <form method="get" class="d-flex gap-2">
            <select name="status" class="form-select form-select-sm" style="width:auto;" onchange="this.form.submit()">
                <option value="">Усі статуси</option>
                <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>Очікує</option>
                <option value="approved" <?php echo $statusFilter === 'approved' ? 'selected' : ''; ?>>Схвалено</option>
                <option value="rejected" <?php echo $statusFilter === 'rejected' ? 'selected' : ''; ?>>Відхилено</option>
                <option value="processed" <?php echo $statusFilter === 'processed' ? 'selected' : ''; ?>>Оброблено</option>
            </select>
        </form>
        <a href="<?php echo BASE_URL; ?>/modules/returns.php?action=create" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> Нове повернення</a>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <?php if (count($returns) > 0): ?>
        <div class="table-container">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Замовлення</th>
                        <th>Товар</th>
                        <th class="text-center">К-сть</th>
                        <th>Причина</th>
                        <th>Дія</th>
                        <th>Повернення на склад</th>
                        <th>Статус</th>
                        <th>Дата</th>
                        <th class="text-center">Дії</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($returns as $r): ?>
                    <tr>
                        <td><?php echo (int)$r['return_id']; ?></td>
                        <td><a href="<?php echo BASE_URL; ?>/modules/orders.php?action=view&id=<?php echo $r['order_id']; ?>">#<?php echo (int)$r['order_id']; ?></a></td>
                        <td><?php echo escape($r['product_name'] ?: 'ID: ' . $r['product_id']); ?></td>
                        <td class="text-center"><?php echo (int)$r['quantity']; ?></td>
                        <td class="text-truncate" style="max-width:150px;"><?php echo escape($r['reason'] ?: '-'); ?></td>
                        <td><?php echo escape($r['action']); ?></td>
                        <td class="text-center"><?php echo $r['restock'] ? '<i class="bi bi-check-lg text-success"></i>' : '<i class="bi bi-x-lg text-muted"></i>'; ?></td>
                        <td><?php echo getStatusBadge($r['status']); ?></td>
                        <td><?php echo formatDate($r['date_added']); ?></td>
                        <td class="text-center">
                            <?php if ($r['status'] === 'pending' && isManager()): ?>
                            <a href="<?php echo BASE_URL; ?>/modules/returns.php?action=approve&id=<?php echo $r['return_id']; ?>" class="btn btn-sm btn-outline-success" title="Схвалити"><i class="bi bi-check-lg"></i></a>
                            <a href="<?php echo BASE_URL; ?>/modules/returns.php?action=reject&id=<?php echo $r['return_id']; ?>" class="btn btn-sm btn-outline-danger" title="Відхилити"><i class="bi bi-x-lg"></i></a>
                            <?php elseif ($r['status'] === 'approved' && isManager()): ?>
                            <a href="<?php echo BASE_URL; ?>/modules/returns.php?action=process&id=<?php echo $r['return_id']; ?>" class="btn btn-sm btn-outline-primary" onclick="return confirm('Підтвердити обробку повернення?')"><i class="bi bi-check2-all"></i> Обробити</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="text-center py-5 text-muted">
            <i class="bi bi-arrow-return-left" style="font-size:3rem;"></i>
            <p class="mt-3 mb-0"><?php echo $statusFilter ? 'Немає повернень з таким статусом' : 'Немає повернень'; ?></p>
        </div>
        <?php endif; ?>
    </div>
    <?php if ($pagination['total_pages'] > 1): ?>
    <div class="card-footer"><?php echo renderPagination(BASE_URL . '/modules/returns.php?', $pagination); ?></div>
    <?php endif; ?>
</div>

<?php if ($action === 'create'): ?>
<div class="card mt-3">
    <div class="card-header">Нове повернення</div>
    <div class="card-body">
        <form method="post" class="row g-3">
            <div class="col-md-3">
                <label class="form-label required">ID замовлення</label>
                <input type="number" name="order_id" class="form-control" required min="1">
            </div>
            <div class="col-md-3">
                <label class="form-label required">ID товару</label>
                <input type="number" name="product_id" class="form-control" required min="1">
            </div>
            <div class="col-md-3">
                <label class="form-label">Назва товару</label>
                <input type="text" name="product_name" class="form-control" placeholder="Необов'язково">
            </div>
            <div class="col-md-3">
                <label class="form-label required">Кількість</label>
                <input type="number" name="quantity" class="form-control" required min="1">
            </div>
            <div class="col-12">
                <label class="form-label required">Причина повернення</label>
                <textarea name="reason" class="form-control" rows="2" required></textarea>
            </div>
            <div class="col-md-4">
                <label class="form-label">Дія</label>
                <select name="action" class="form-select">
                    <option value="refund">Повернення коштів</option>
                    <option value="credit">Кредит</option>
                    <option value="replacement">Заміна</option>
                    <option value="none">Без дії</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">&nbsp;</label>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="restock" id="restock" value="1" checked>
                    <label class="form-check-label" for="restock">Повернути на склад</label>
                </div>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Зареєструвати повернення</button>
                <a href="<?php echo BASE_URL; ?>/modules/returns.php" class="btn btn-secondary">Скасувати</a>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
