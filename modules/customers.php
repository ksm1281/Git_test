<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../_helpers.php';
requireLogin();

$action = $_GET['action'] ?? '';

if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST' && isManager()) {
    $customerId = (int)($_POST['customer_id'] ?? 0);
    $companyName = trim($_POST['company_name'] ?? '');
    $edrpou = trim($_POST['edrpou'] ?? '');
    $legalAddress = trim($_POST['legal_address'] ?? '');
    $iban = trim($_POST['iban'] ?? '');
    $mfo = trim($_POST['mfo'] ?? '');

    if ($customerId) {
        $stmt = $pdo->prepare("UPDATE erp_customers SET company_name=?, edrpou=?, legal_address=?, iban=?, mfo=? WHERE customer_id=?");
        $stmt->execute([$companyName, $edrpou, $legalAddress, $iban, $mfo, $customerId]);
        flashMessage('success', 'Реквізити клієнта збережено');
    }
    redirect(BASE_URL . '/modules/customers.php?id=' . $customerId);
}

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$search = trim($_GET['search'] ?? '');

$where = '';
$params = [];
if ($search) {
    $where = "WHERE (c.firstname LIKE ? OR c.lastname LIKE ? OR c.email LIKE ? OR c.telephone LIKE ? OR c.company_name LIKE ? OR CAST(c.customer_id AS CHAR) LIKE ?)";
    $s = "%$search%";
    $params = [$s, $s, $s, $s, $s, $s];
}

if (isset($_GET['action']) && $_GET['action'] === 'add_note' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $customerId = (int)$_POST['customer_id'];
    $note = trim($_POST['note'] ?? '');
    $user = getUserData();
    if ($customerId && $note) {
        $stmt = $pdo->prepare("INSERT INTO erp_customer_notes (customer_id, user_id, note) VALUES (?, ?, ?)");
        $stmt->execute([$customerId, $user['user_id'], $note]);
        flashMessage('success', 'Нотатку додано');
    }
    redirect(BASE_URL . '/modules/customers.php?id=' . $customerId);
}

$countSql = "SELECT COUNT(*) FROM erp_customers c $where";
$stmt = $pdo->prepare($countSql);
$stmt->execute($params);
$total = $stmt->fetchColumn();
$pagination = paginate($total, $perPage, $page);

$sql = "SELECT c.*, (SELECT COUNT(*) FROM erp_customer_notes WHERE customer_id = c.customer_id) as notes_count FROM erp_customers c $where ORDER BY c.lastname ASC, c.firstname ASC LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$customers = $stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="bi bi-people"></i> Клієнти</h4>
    <form method="get" class="d-flex">
        <input type="search" name="search" class="form-control form-control-sm search-box me-2" placeholder="Пошук..." value="<?php echo escape($search); ?>">
        <button class="btn btn-sm btn-outline-primary"><i class="bi bi-search"></i></button>
    </form>
</div>

<div class="card">
    <div class="card-body p-0">
        <?php if (count($customers) > 0): ?>
        <div class="table-container">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Ім'я</th>
                        <th>Email</th>
                        <th>Телефон</th>
                        <th>Група</th>
                        <th class="text-end">Замовлень</th>
                        <th class="text-end">На суму</th>
                        <th class="text-center">Нотатки</th>
                        <th class="text-center">Дії</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($customers as $c): ?>
                    <tr>
                        <td><?php echo (int)$c['customer_id']; ?></td>
                        <td class="fw-bold"><?php echo escape($c['firstname'] . ' ' . $c['lastname']); ?></td>
                        <td><?php echo escape($c['email'] ?: '-'); ?></td>
                        <td><?php echo escape($c['telephone'] ?: '-'); ?></td>
                        <td><?php echo escape($c['group_name'] ?: '-'); ?></td>
                        <td class="text-end"><?php echo (int)$c['total_orders']; ?></td>
                        <td class="text-end"><?php echo formatMoney($c['total_spent']); ?></td>
                        <td class="text-center">
                            <?php if ($c['notes_count'] > 0): ?>
                            <span class="badge bg-info"><?php echo (int)$c['notes_count']; ?></span>
                            <?php else: ?>
                            <span class="text-muted">0</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <a href="<?php echo BASE_URL; ?>/modules/customers.php?id=<?php echo $c['customer_id']; ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="text-center py-5 text-muted">
            <i class="bi bi-people" style="font-size:3rem;"></i>
            <p class="mt-3 mb-0"><?php echo $search ? 'Нічого не знайдено' : 'Немає клієнтів'; ?></p>
        </div>
        <?php endif; ?>
    </div>
    <?php if ($pagination['total_pages'] > 1): ?>
    <div class="card-footer"><?php echo renderPagination(BASE_URL . '/modules/customers.php?', $pagination); ?></div>
    <?php endif; ?>
</div>

<?php
if (isset($_GET['id'])) {
    $customerId = (int)$_GET['id'];
    $stmt = $pdo->prepare("SELECT * FROM erp_customers WHERE customer_id = ?");
    $stmt->execute([$customerId]);
    $customer = $stmt->fetch();
    if ($customer):
    $stmt = $pdo->prepare("SELECT cn.*, u.username FROM erp_customer_notes cn LEFT JOIN erp_users u ON cn.user_id = u.user_id WHERE cn.customer_id = ? ORDER BY cn.date_added DESC");
    $stmt->execute([$customerId]);
    $notes = $stmt->fetchAll();
    $stmt = $pdo->prepare("SELECT * FROM erp_orders WHERE customer_id = ? ORDER BY date_added DESC LIMIT 10");
    $stmt->execute([$customerId]);
    $orders = $stmt->fetchAll();
?>
    <div class="card mt-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>Клієнт: <?php echo escape($customer['firstname'] . ' ' . $customer['lastname']); ?></span>
            <div>
                <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#customerRequisitesModal"><i class="bi bi-pencil"></i> Реквізити</button>
                <a href="<?php echo BASE_URL; ?>/modules/customers.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> Назад</a>
            </div>
        </div>
        <div class="card-body">
            <div class="row g-3 mb-3">
                <div class="col-md-3">
                    <small class="text-muted">Email</small>
                    <div><?php echo escape($customer['email'] ?: '-'); ?></div>
                </div>
                <div class="col-md-3">
                    <small class="text-muted">Телефон</small>
                    <div><?php echo escape($customer['telephone'] ?: '-'); ?></div>
                </div>
                <div class="col-md-3">
                    <small class="text-muted">Група</small>
                    <div><?php echo escape($customer['group_name'] ?: '-'); ?></div>
                </div>
                <div class="col-md-3">
                    <small class="text-muted">Всього замовлень</small>
                    <div class="fw-bold"><?php echo (int)$customer['total_orders']; ?> / <?php echo formatMoney($customer['total_spent']); ?></div>
                </div>
            </div>

            <?php if ($customer['company_name'] || $customer['edrpou']): ?>
            <div class="row g-3 mb-3 p-3 bg-light rounded">
                <div class="col-md-12"><strong>Реквізити</strong></div>
                <div class="col-md-4">
                    <small class="text-muted">Назва юр. особи</small>
                    <div><?php echo escape($customer['company_name'] ?: '-'); ?></div>
                </div>
                <div class="col-md-2">
                    <small class="text-muted">ЄДРПОУ / ІПН</small>
                    <div><?php echo escape($customer['edrpou'] ?: '-'); ?></div>
                </div>
                <div class="col-md-3">
                    <small class="text-muted">IBAN</small>
                    <div><?php echo escape($customer['iban'] ?: '-'); ?></div>
                </div>
                <div class="col-md-2">
                    <small class="text-muted">МФО</small>
                    <div><?php echo escape($customer['mfo'] ?: '-'); ?></div>
                </div>
                <div class="col-md-12">
                    <small class="text-muted">Юр. адреса</small>
                    <div><?php echo escape($customer['legal_address'] ?: '-'); ?></div>
                </div>
            </div>
            <?php endif; ?>

            <ul class="nav nav-tabs mb-3">
                <li class="nav-item"><a class="nav-link active" href="#orders-tab" data-bs-toggle="tab">Замовлення</a></li>
                <li class="nav-item"><a class="nav-link" href="#notes-tab" data-bs-toggle="tab">Нотатки (<?php echo count($notes); ?>)</a></li>
            </ul>
            <div class="tab-content">
                <div class="tab-pane active" id="orders-tab">
                    <?php if (count($orders) > 0): ?>
                    <div class="table-container">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th class="text-end">Сума</th>
                                    <th>Статус</th>
                                    <th>Дата</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($orders as $o): ?>
                                <tr>
                                    <td><a href="<?php echo BASE_URL; ?>/modules/orders.php?action=view&id=<?php echo $o['order_id']; ?>">#<?php echo (int)$o['order_id']; ?></a></td>
                                    <td class="text-end"><?php echo formatMoney($o['total']); ?></td>
                                    <td><?php echo getStatusBadge(strtolower($o['status_name'])); ?></td>
                                    <td><?php echo formatDate($o['date_added']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <p class="text-muted">Немає замовлень</p>
                    <?php endif; ?>
                </div>
                <div class="tab-pane" id="notes-tab">
                    <form method="post" action="?action=add_note" class="mb-3">
                        <input type="hidden" name="customer_id" value="<?php echo $customerId; ?>">
                        <div class="input-group">
                            <textarea name="note" class="form-control" rows="2" placeholder="Нова нотатка..." required></textarea>
                            <button class="btn btn-primary"><i class="bi bi-plus-lg"></i> Додати</button>
                        </div>
                    </form>
                    <?php if (count($notes) > 0): ?>
                    <div class="list-group">
                        <?php foreach ($notes as $n): ?>
                        <div class="list-group-item">
                            <div class="d-flex justify-content-between">
                                <small class="text-muted"><?php echo escape($n['username']); ?> &middot; <?php echo formatDate($n['date_added']); ?></small>
                            </div>
                            <p class="mb-0 mt-1"><?php echo nl2br(escape($n['note'])); ?></p>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <p class="text-muted">Немає нотаток</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
<?php
    endif;
}
?>

<?php if (isset($customer) && $customer): ?>
<div class="modal fade" id="customerRequisitesModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" action="?action=save">
                <input type="hidden" name="customer_id" value="<?php echo $customerId; ?>">
                <div class="modal-header">
                    <h5 class="modal-title">Реквізити клієнта</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Назва юр. особи / ФОП</label>
                        <input type="text" name="company_name" class="form-control" value="<?php echo escape($customer['company_name'] ?? ''); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ЄДРПОУ / ІПН</label>
                        <input type="text" name="edrpou" class="form-control" value="<?php echo escape($customer['edrpou'] ?? ''); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Юридична адреса</label>
                        <input type="text" name="legal_address" class="form-control" value="<?php echo escape($customer['legal_address'] ?? ''); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">IBAN</label>
                        <input type="text" name="iban" class="form-control" value="<?php echo escape($customer['iban'] ?? ''); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">МФО</label>
                        <input type="text" name="mfo" class="form-control" value="<?php echo escape($customer['mfo'] ?? ''); ?>">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Скасувати</button>
                    <button type="submit" class="btn btn-primary">Зберегти</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
