<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../_helpers.php';
requireLogin();

$action = $_GET['action'] ?? 'list';
$id = (int)($_GET['id'] ?? 0);

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$search = trim($_GET['search'] ?? '');

if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $contactPerson = trim($_POST['contact_person'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $currency = $_POST['currency'] ?? 'USD';
    $notes = trim($_POST['notes'] ?? '');

    if (empty($name)) { flashMessage('error', 'Назва обов\'язкова'); redirect(BASE_URL . '/modules/suppliers.php'); }

    if ($id) {
        $stmt = $pdo->prepare("UPDATE erp_suppliers SET name=?, contact_person=?, phone=?, email=?, address=?, currency=?, notes=? WHERE supplier_id=?");
        $stmt->execute([$name, $contactPerson, $phone, $email, $address, $currency, $notes, $id]);
        flashMessage('success', 'Постачальника оновлено');
    } else {
        $stmt = $pdo->prepare("INSERT INTO erp_suppliers (name, contact_person, phone, email, address, currency, notes) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$name, $contactPerson, $phone, $email, $address, $currency, $notes]);
        flashMessage('success', 'Постачальника додано');
    }
    redirect(BASE_URL . '/modules/suppliers.php');
}

if ($action === 'toggle' && $id) {
    $stmt = $pdo->prepare("UPDATE erp_suppliers SET status = CASE WHEN status = 1 THEN 0 ELSE 1 END WHERE supplier_id = ?");
    $stmt->execute([$id]);
    flashMessage('success', 'Статус змінено');
    redirect(BASE_URL . '/modules/suppliers.php');
}

$where = '';
$params = [];
if ($search) {
    $where = "WHERE (name LIKE ? OR contact_person LIKE ? OR phone LIKE ? OR email LIKE ?)";
    $s = "%$search%";
    $params = [$s, $s, $s, $s];
}

$countSql = "SELECT COUNT(*) FROM erp_suppliers $where";
$stmt = $pdo->prepare($countSql);
$stmt->execute($params);
$total = $stmt->fetchColumn();
$pagination = paginate($total, $perPage, $page);

$sql = "SELECT * FROM erp_suppliers $where ORDER BY name ASC LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$suppliers = $stmt->fetchAll();

if ($action === 'edit' && $id) {
    $stmt = $pdo->prepare("SELECT * FROM erp_suppliers WHERE supplier_id = ?");
    $stmt->execute([$id]);
    $s = $stmt->fetch();
    if (!$s) { flashMessage('error', 'Постачальника не знайдено'); redirect(BASE_URL . '/modules/suppliers.php'); }
    include __DIR__ . '/../includes/header.php';
    ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0"><i class="bi bi-pencil"></i> Редагувати постачальника</h4>
        <a href="<?php echo BASE_URL; ?>/modules/suppliers.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Назад</a>
    </div>
    <div class="card">
        <div class="card-header">Дані постачальника</div>
        <div class="card-body">
            <form method="post" action="?action=save&id=<?php echo $id; ?>">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label required">Назва</label>
                        <input type="text" name="name" class="form-control" value="<?php echo escape($s['name']); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Контактна особа</label>
                        <input type="text" name="contact_person" class="form-control" value="<?php echo escape($s['contact_person']); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Телефон</label>
                        <input type="text" name="phone" class="form-control" value="<?php echo escape($s['phone']); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" value="<?php echo escape($s['email']); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Валюта</label>
                        <select name="currency" class="form-select">
                            <option value="USD" <?php echo $s['currency'] === 'USD' ? 'selected' : ''; ?>>USD</option>
                            <option value="EUR" <?php echo $s['currency'] === 'EUR' ? 'selected' : ''; ?>>EUR</option>
                            <option value="UAH" <?php echo $s['currency'] === 'UAH' ? 'selected' : ''; ?>>UAH</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Адреса</label>
                        <textarea name="address" class="form-control" rows="2"><?php echo escape($s['address']); ?></textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Примітки</label>
                        <textarea name="notes" class="form-control" rows="2"><?php echo escape($s['notes']); ?></textarea>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Зберегти</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <?php
    include __DIR__ . '/../includes/footer.php';
    exit;
}

if (!$id && $action === 'create') {
    include __DIR__ . '/../includes/header.php';
    ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0"><i class="bi bi-plus-lg"></i> Новий постачальник</h4>
        <a href="<?php echo BASE_URL; ?>/modules/suppliers.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Назад</a>
    </div>
    <div class="card">
        <div class="card-header">Дані постачальника</div>
        <div class="card-body">
            <form method="post" action="?action=save">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label required">Назва</label>
                        <input type="text" name="name" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Контактна особа</label>
                        <input type="text" name="contact_person" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Телефон</label>
                        <input type="text" name="phone" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Валюта</label>
                        <select name="currency" class="form-select"><option value="USD">USD</option><option value="EUR">EUR</option><option value="UAH">UAH</option></select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Адреса</label>
                        <textarea name="address" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Примітки</label>
                        <textarea name="notes" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Зберегти</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <?php
    include __DIR__ . '/../includes/footer.php';
    exit;
}

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="bi bi-truck"></i> Постачальники</h4>
    <div class="d-flex gap-2">
        <form method="get" class="d-flex">
            <input type="search" name="search" class="form-control form-control-sm search-box me-2" placeholder="Пошук..." value="<?php echo escape($search); ?>">
            <button class="btn btn-sm btn-outline-primary"><i class="bi bi-search"></i></button>
        </form>
        <a href="<?php echo BASE_URL; ?>/modules/suppliers.php?action=create" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> Додати</a>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <?php if (count($suppliers) > 0): ?>
        <div class="table-container">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Назва</th>
                        <th>Контакт</th>
                        <th>Телефон</th>
                        <th>Email</th>
                        <th>Валюта</th>
                        <th>Статус</th>
                        <th class="text-center">Дії</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($suppliers as $s): ?>
                    <tr>
                        <td class="fw-bold"><?php echo escape($s['name']); ?></td>
                        <td><?php echo escape($s['contact_person'] ?: '-'); ?></td>
                        <td><?php echo escape($s['phone'] ?: '-'); ?></td>
                        <td><?php echo escape($s['email'] ?: '-'); ?></td>
                        <td><?php echo escape($s['currency']); ?></td>
                        <td><?php echo $s['status'] ? '<span class="badge bg-success">Активний</span>' : '<span class="badge bg-secondary">Неактивний</span>'; ?></td>
                        <td class="text-center">
                            <a href="<?php echo BASE_URL; ?>/modules/suppliers.php?action=edit&id=<?php echo $s['supplier_id']; ?>" class="btn btn-sm btn-outline-primary" title="Редагувати"><i class="bi bi-pencil"></i></a>
                            <a href="<?php echo BASE_URL; ?>/modules/suppliers.php?action=toggle&id=<?php echo $s['supplier_id']; ?>" class="btn btn-sm btn-outline-<?php echo $s['status'] ? 'warning' : 'success'; ?>" title="<?php echo $s['status'] ? 'Деактивувати' : 'Активувати'; ?>"><i class="bi bi-<?php echo $s['status'] ? 'pause' : 'play'; ?>"></i></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="text-center py-5 text-muted">
            <i class="bi bi-truck" style="font-size:3rem;"></i>
            <p class="mt-3 mb-0"><?php echo $search ? 'Нічого не знайдено' : 'Немає постачальників'; ?></p>
        </div>
        <?php endif; ?>
    </div>
    <?php if ($pagination['total_pages'] > 1): ?>
    <div class="card-footer"><?php echo renderPagination(BASE_URL . '/modules/suppliers.php?', $pagination); ?></div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
