<?php
require_once __DIR__ . '/../config.php';
requireLogin();

if (!isAdmin()) {
    flashMessage('error', 'Доступ заборонено. Тільки для адміністратора.');
    redirect(BASE_URL . '/index.php');
}

$action = $_GET['action'] ?? 'list';
$userId = (int)($_GET['id'] ?? 0);

if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $email = trim($_POST['email'] ?? '');
    $role = $_POST['role'] ?? 'staff';
    $status = (int)($_POST['status'] ?? 1);

    if (empty($username)) {
        flashMessage('error', 'Ім\'я користувача обов\'язкове');
        redirect(BASE_URL . '/modules/staff.php');
    }

    if ($userId) {
        if ($password) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE erp_users SET username=?, password=?, email=?, role=?, status=? WHERE user_id=?");
            $stmt->execute([$username, $hash, $email, $role, $status, $userId]);
        } else {
            $stmt = $pdo->prepare("UPDATE erp_users SET username=?, email=?, role=?, status=? WHERE user_id=?");
            $stmt->execute([$username, $email, $role, $status, $userId]);
        }
        flashMessage('success', 'Користувача оновлено');
    } else {
        if (empty($password)) {
            flashMessage('error', 'Пароль обов\'язковий для нового користувача');
            redirect(BASE_URL . '/modules/staff.php');
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO erp_users (username, password, email, role, status) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$username, $hash, $email, $role, $status]);
        flashMessage('success', 'Користувача додано');
    }
    redirect(BASE_URL . '/modules/staff.php');
}

if ($action === 'delete' && $userId && $userId !== (int)$_SESSION['erp_user_id']) {
    $stmt = $pdo->prepare("DELETE FROM erp_users WHERE user_id = ?");
    $stmt->execute([$userId]);
    flashMessage('success', 'Користувача видалено');
    redirect(BASE_URL . '/modules/staff.php');
}

$stmt = $pdo->query("SELECT * FROM erp_users ORDER BY role ASC, username ASC");
$users = $stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="bi bi-person-gear"></i> Персонал</h4>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#userModal"><i class="bi bi-plus-lg"></i> Додати</button>
</div>

<div class="card">
    <div class="card-body p-0">
        <?php if (count($users) > 0): ?>
        <div class="table-container">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Ім'я користувача</th>
                        <th>Email</th>
                        <th>Роль</th>
                        <th>Статус</th>
                        <th>Дата створення</th>
                        <th class="text-center">Дії</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                    <tr>
                        <td><?php echo (int)$u['user_id']; ?></td>
                        <td class="fw-bold"><?php echo escape($u['username']); ?></td>
                        <td><?php echo escape($u['email'] ?: '-'); ?></td>
                        <td>
                            <?php if ($u['role'] === 'admin'): ?>
                            <span class="badge bg-danger">Адмін</span>
                            <?php elseif ($u['role'] === 'manager'): ?>
                            <span class="badge bg-warning text-dark">Менеджер</span>
                            <?php else: ?>
                            <span class="badge bg-secondary">Співробітник</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $u['status'] ? '<span class="badge bg-success">Активний</span>' : '<span class="badge bg-danger">Заблоковано</span>'; ?></td>
                        <td><?php echo formatDate($u['date_added']); ?></td>
                        <td class="text-center">
                            <button class="btn btn-sm btn-outline-primary edit-user"
                                data-id="<?php echo $u['user_id']; ?>"
                                data-username="<?php echo escape($u['username']); ?>"
                                data-email="<?php echo escape($u['email']); ?>"
                                data-role="<?php echo $u['role']; ?>"
                                data-status="<?php echo (int)$u['status']; ?>"
                                title="Редагувати"><i class="bi bi-pencil"></i></button>
                            <?php if ((int)$u['user_id'] !== (int)$_SESSION['erp_user_id']): ?>
                            <a href="<?php echo BASE_URL; ?>/modules/staff.php?action=delete&id=<?php echo $u['user_id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Видалити користувача?')" title="Видалити"><i class="bi bi-trash"></i></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="text-center py-5 text-muted">
            <i class="bi bi-person-gear" style="font-size:3rem;"></i>
            <p class="mt-3 mb-0">Немає користувачів</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal fade" id="userModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" action="?action=save" id="userForm">
                <input type="hidden" name="user_id" id="editUserId" value="0">
                <div class="modal-header">
                    <h5 class="modal-title" id="userModalTitle">Новий користувач</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label required">Ім'я користувача</label>
                        <input type="text" name="username" id="editUsername" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" id="passwordLabel">Пароль</label>
                        <input type="password" name="password" id="editPassword" class="form-control" autocomplete="new-password">
                        <div class="form-text" id="passwordHelp">Залиште порожнім, щоб не змінювати</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" id="editEmail" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label required">Роль</label>
                        <select name="role" id="editRole" class="form-select">
                            <option value="staff">Співробітник</option>
                            <option value="manager">Менеджер</option>
                            <option value="admin">Адміністратор</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="status" id="editStatus" value="1" checked>
                            <label class="form-check-label" for="editStatus">Активний</label>
                        </div>
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

<script>
document.querySelectorAll('.edit-user').forEach(function(btn) {
    btn.addEventListener('click', function() {
        document.getElementById('editUserId').value = this.dataset.id;
        document.getElementById('editUsername').value = this.dataset.username;
        document.getElementById('editEmail').value = this.dataset.email;
        document.getElementById('editRole').value = this.dataset.role;
        document.getElementById('editStatus').checked = this.dataset.status === '1';
        document.getElementById('userModalTitle').textContent = 'Редагувати користувача';
        document.getElementById('passwordLabel').textContent = 'Новий пароль';
        document.getElementById('passwordHelp').style.display = 'block';
        document.getElementById('editPassword').required = false;
        new bootstrap.Modal(document.getElementById('userModal')).show();
    });
});

document.querySelector('[data-bs-target="#userModal"]')?.addEventListener('click', function() {
    document.getElementById('userForm').reset();
    document.getElementById('editUserId').value = '0';
    document.getElementById('editStatus').checked = true;
    document.getElementById('userModalTitle').textContent = 'Новий користувач';
    document.getElementById('passwordLabel').textContent = 'Пароль';
    document.getElementById('passwordHelp').style.display = 'none';
    document.getElementById('editPassword').required = true;
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
