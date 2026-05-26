<?php
require_once __DIR__ . '/config.php';

$error = '';
$success = '';

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    $success = 'Ви успішно вийшли з системи.';
    header('Refresh: 2; url=login.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = 'Будь ласка, заповніть всі поля.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM erp_users WHERE username = ? AND status = 1 LIMIT 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['erp_user_id'] = $user['user_id'];
            $_SESSION['erp_username'] = $user['username'];
            $_SESSION['erp_role'] = $user['role'];
            logActivity($pdo, 'success', 'Успішний вхід: ' . $username, 'login');
            flashMessage('success', 'Вітаємо, ' . $user['username'] . '!');
            redirect(BASE_URL . '/index.php');
        } else {
            $error = 'Невірне ім\'я користувача або пароль.';
            logActivity($pdo, 'warning', 'Невдала спроба входу: ' . $username, 'login');
        }
    }
}

if (isLoggedIn() && !isset($_GET['action'])) {
    redirect(BASE_URL . '/index.php');
}
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Вхід - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        html, body { height: 100%; }
        body { display: flex; align-items: center; background: #f5f5f5; }
        .form-signin { width: 100%; max-width: 400px; padding: 40px; margin: auto; background: #fff; border-radius: 12px; box-shadow: 0 4px 24px rgba(0,0,0,0.08); }
        .form-signin .form-floating:focus-within { z-index: 2; }
        .form-signin h1 { font-size: 1.5rem; font-weight: 600; }
        .logo-icon { font-size: 3rem; color: #0d6efd; }
    </style>
</head>
<body>
    <main class="form-signin">
        <div class="text-center mb-4">
            <i class="bi bi-box-seam logo-icon"></i>
            <h1 class="mt-2"><?php echo APP_NAME; ?></h1>
            <p class="text-muted">Введіть ваші облікові дані</p>
        </div>

        <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo escape($error); ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
        <div class="alert alert-success"><?php echo escape($success); ?></div>
        <?php endif; ?>

        <form method="post">
            <div class="form-floating mb-3">
                <input type="text" class="form-control" id="username" name="username" placeholder="Ім'я користувача" required autofocus>
                <label for="username">Ім'я користувача</label>
            </div>
            <div class="form-floating mb-3">
                <input type="password" class="form-control" id="password" name="password" placeholder="Пароль" required>
                <label for="password">Пароль</label>
            </div>
            <button class="btn btn-primary w-100 py-2" type="submit">
                <i class="bi bi-box-arrow-in-right"></i> Увійти
            </button>
        </form>
        <p class="mt-3 mb-0 text-center text-muted small">
            <i class="bi bi-info-circle"></i> За замовчуванням: admin / admin
        </p>
    </main>
</body>
</html>
