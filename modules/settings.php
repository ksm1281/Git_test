<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../_helpers.php';
requireLogin();

if (!isAdmin()) {
    flashMessage('error', 'Доступ заборонено. Тільки для адміністратора.');
    redirect(BASE_URL . '/index.php');
}

$tab = $_GET['tab'] ?? 'general';
$user = getUserData();

// --- Save General Settings ---
if ($tab === 'general' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $settings = [
        'app_name' => trim($_POST['app_name'] ?? 'ERP/CRM'),
        'currency_symbol' => trim($_POST['currency_symbol'] ?? '&#8372;'),
        'currency_code' => trim($_POST['currency_code'] ?? 'UAH'),
        'default_markup_wholesale' => (float)($_POST['default_markup_wholesale'] ?? 0),
        'default_markup_semi_wholesale' => (float)($_POST['default_markup_semi_wholesale'] ?? 0),
        'default_markup_retail' => (float)($_POST['default_markup_retail'] ?? 0),
        'auto_sync_enabled' => $_POST['auto_sync_enabled'] ?? '0',
        'sync_interval_minutes' => (int)($_POST['sync_interval_minutes'] ?? 60),
    ];
    foreach ($settings as $key => $value) {
        $stmt = $pdo->prepare("INSERT INTO erp_settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = ?");
        $stmt->execute([$key, $value, $value]);
    }

    if (!empty($_POST['usd_rate'])) {
        $rate = (float)$_POST['usd_rate'];
        $stmt = $pdo->prepare("INSERT INTO erp_exchange_rates (currency_from, currency_to, rate, source) VALUES ('USD', 'UAH', ?, 'manual')");
        $stmt->execute([$rate]);
    }
    if (!empty($_POST['eur_rate'])) {
        $rate = (float)$_POST['eur_rate'];
        $stmt = $pdo->prepare("INSERT INTO erp_exchange_rates (currency_from, currency_to, rate, source) VALUES ('EUR', 'UAH', ?, 'manual')");
        $stmt->execute([$rate]);
    }

    flashMessage('success', 'Налаштування збережено');
    redirect(BASE_URL . '/modules/settings.php?tab=general');
}

// --- Save Notifications Settings ---
if ($tab === 'notifications' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $settings = [
        'tg_bot_token' => trim($_POST['tg_bot_token'] ?? ''),
        'tg_chat_id' => trim($_POST['tg_chat_id'] ?? ''),
        'notify_on_new_order' => $_POST['notify_on_new_order'] ?? '0',
    ];
    foreach ($settings as $key => $value) {
        $stmt = $pdo->prepare("INSERT INTO erp_settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = ?");
        $stmt->execute([$key, $value, $value]);
    }
    flashMessage('success', 'Налаштування сповіщень збережено');
    redirect(BASE_URL . '/modules/settings.php?tab=notifications');
}

// --- Save Cash Account ---
if ($tab === 'cash' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_account'])) {
    $accountId = (int)($_POST['account_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $type = $_POST['type'] ?? 'cash';
    $currency = $_POST['currency'] ?? 'UAH';
    $initialBalance = (float)($_POST['initial_balance'] ?? 0);
    $status = (int)($_POST['status'] ?? 1);

    if ($name) {
        if ($accountId) {
            $stmt = $pdo->prepare("UPDATE erp_cash_accounts SET name=?, type=?, currency=?, initial_balance=?, status=? WHERE account_id=?");
            $stmt->execute([$name, $type, $currency, $initialBalance, $status, $accountId]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO erp_cash_accounts (name, type, currency, initial_balance, status) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$name, $type, $currency, $initialBalance, $status]);
        }
        flashMessage('success', 'Рахунок збережено');
    }
    redirect(BASE_URL . '/modules/settings.php?tab=cash');
}

if ($tab === 'cash' && isset($_GET['delete']) && isAdmin()) {
    $accountId = (int)$_GET['delete'];
    $pdo->prepare("DELETE FROM erp_cash_accounts WHERE account_id=?")->execute([$accountId]);
    flashMessage('success', 'Рахунок видалено');
    redirect(BASE_URL . '/modules/settings.php?tab=cash');
}

// --- Save Delivery Method ---
if ($tab === 'delivery' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_delivery'])) {
    $methodId = (int)($_POST['method_id'] ?? 0);
    $code = trim($_POST['code'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $sortOrder = (int)($_POST['sort_order'] ?? 0);
    $status = (int)($_POST['status'] ?? 1);

    if ($code && $name) {
        if ($methodId) {
            $stmt = $pdo->prepare("UPDATE erp_delivery_methods SET code=?, name=?, sort_order=?, status=? WHERE method_id=?");
            $stmt->execute([$code, $name, $sortOrder, $status, $methodId]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO erp_delivery_methods (code, name, sort_order, status) VALUES (?, ?, ?, ?)");
            $stmt->execute([$code, $name, $sortOrder, $status]);
        }
        flashMessage('success', 'Спосіб доставки збережено');
    }
    redirect(BASE_URL . '/modules/settings.php?tab=delivery');
}

if ($tab === 'delivery' && isset($_GET['delete']) && isAdmin()) {
    $methodId = (int)$_GET['delete'];
    $pdo->prepare("DELETE FROM erp_delivery_methods WHERE method_id=?")->execute([$methodId]);
    flashMessage('success', 'Спосіб доставки видалено');
    redirect(BASE_URL . '/modules/settings.php?tab=delivery');
}

// --- Save Payment Method ---
if ($tab === 'payment' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_payment'])) {
    $methodId = (int)($_POST['method_id'] ?? 0);
    $code = trim($_POST['code'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $sortOrder = (int)($_POST['sort_order'] ?? 0);
    $status = (int)($_POST['status'] ?? 1);

    if ($code && $name) {
        if ($methodId) {
            $stmt = $pdo->prepare("UPDATE erp_payment_methods SET code=?, name=?, sort_order=?, status=? WHERE method_id=?");
            $stmt->execute([$code, $name, $sortOrder, $status, $methodId]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO erp_payment_methods (code, name, sort_order, status) VALUES (?, ?, ?, ?)");
            $stmt->execute([$code, $name, $sortOrder, $status]);
        }
        flashMessage('success', 'Спосіб оплати збережено');
    }
    redirect(BASE_URL . '/modules/settings.php?tab=payment');
}

if ($tab === 'payment' && isset($_GET['delete']) && isAdmin()) {
    $methodId = (int)$_GET['delete'];
    $pdo->prepare("DELETE FROM erp_payment_methods WHERE method_id=?")->execute([$methodId]);
    flashMessage('success', 'Спосіб оплати видалено');
    redirect(BASE_URL . '/modules/settings.php?tab=payment');
}

// --- User actions ---
if ($tab === 'users') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_user'])) {
        $userId = (int)($_POST['user_id'] ?? 0);
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $email = trim($_POST['email'] ?? '');
        $role = $_POST['role'] ?? 'staff';
        $status = (int)($_POST['status'] ?? 1);

        if ($username) {
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
                    redirect(BASE_URL . '/modules/settings.php?tab=users');
                }
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO erp_users (username, password, email, role, status) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$username, $hash, $email, $role, $status]);
                flashMessage('success', 'Користувача додано');
            }
        }
        redirect(BASE_URL . '/modules/settings.php?tab=users');
    }

    if (isset($_GET['delete_user'])) {
        $userId = (int)$_GET['delete_user'];
        if ($userId && $userId !== (int)$_SESSION['erp_user_id']) {
            $pdo->prepare("DELETE FROM erp_users WHERE user_id=?")->execute([$userId]);
            flashMessage('success', 'Користувача видалено');
        }
        redirect(BASE_URL . '/modules/settings.php?tab=users');
    }
}

// --- Logs: Clear ---
if ($tab === 'logs' && isset($_GET['clear']) && isAdmin()) {
    $pdo->exec("TRUNCATE TABLE erp_activity_log");
    flashMessage('success', 'Логи очищено');
    redirect(BASE_URL . '/modules/settings.php?tab=logs');
}

// --- Test Telegram ---
if ($tab === 'notifications' && isset($_GET['test_telegram'])) {
    $testMessage = "<b>Тестове сповіщення</b>\n"
        . "🕒 " . date('d.m.Y H:i') . "\n\n"
        . "Якщо ви бачите це повідомлення — Telegram-бот налаштовано правильно!";
    $sent = sendTelegramNotification($pdo, $testMessage);
    if ($sent) {
        flashMessage('success', 'Тестове повідомлення надіслано! Перевірте Telegram.');
    } else {
        flashMessage('error', 'Помилка надсилання. Перевірте токен та Chat ID.');
    }
    redirect(BASE_URL . '/modules/settings.php?tab=notifications');
}

// --- Data for views ---
$settings = [];
$stmt = $pdo->query("SELECT `key`, `value` FROM erp_settings");
foreach ($stmt as $row) {
    $settings[$row['key']] = $row['value'];
}

$rates = getCurrentRates($pdo);
$accounts = $pdo->query("SELECT * FROM erp_cash_accounts ORDER BY type ASC, name ASC")->fetchAll();
$deliveryMethods = $pdo->query("SELECT * FROM erp_delivery_methods ORDER BY sort_order ASC")->fetchAll();
$paymentMethods = $pdo->query("SELECT * FROM erp_payment_methods ORDER BY sort_order ASC")->fetchAll();
$users = $pdo->query("SELECT * FROM erp_users ORDER BY role ASC, username ASC")->fetchAll();

$tabs = [
    'general' => ['label' => 'Загальні', 'icon' => 'bi-gear'],
    'notifications' => ['label' => 'Сповіщення', 'icon' => 'bi-bell'],
    'logs' => ['label' => 'Логування', 'icon' => 'bi-journal-text'],
    'cash' => ['label' => 'Каси', 'icon' => 'bi-wallet2'],
    'delivery' => ['label' => 'Доставки', 'icon' => 'bi-truck'],
    'payment' => ['label' => 'Оплати', 'icon' => 'bi-credit-card'],
    'users' => ['label' => 'Користувачі', 'icon' => 'bi-people'],
];

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="bi bi-gear-wide"></i> Налаштування</h4>
</div>

<ul class="nav nav-tabs mb-3">
    <?php foreach ($tabs as $key => $t): ?>
    <li class="nav-item">
        <a class="nav-link <?php echo $tab === $key ? 'active' : ''; ?>" href="?tab=<?php echo $key; ?>">
            <i class="bi <?php echo $t['icon']; ?>"></i> <?php echo $t['label']; ?>
        </a>
    </li>
    <?php endforeach; ?>
</ul>

<?php if ($tab === 'general'): ?>
<div class="card">
    <div class="card-header">Загальні налаштування</div>
    <div class="card-body">
        <form method="post">
            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label">Назва системи</label>
                    <input type="text" name="app_name" class="form-control" value="<?php echo escape($settings['app_name'] ?? 'ERP/CRM'); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Символ валюти</label>
                    <input type="text" name="currency_symbol" class="form-control" value="<?php echo escape($settings['currency_symbol'] ?? '&#8372;'); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Код валюти</label>
                    <input type="text" name="currency_code" class="form-control" value="<?php echo escape($settings['currency_code'] ?? 'UAH'); ?>">
                </div>
            </div>

            <h6 class="fw-bold mb-2">Курси валют</h6>
            <div class="row g-3 mb-3">
                <div class="col-md-3">
                    <label class="form-label">USD → UAH</label>
                    <div class="input-group">
                        <input type="number" name="usd_rate" class="form-control" step="0.01" placeholder="<?php echo $rates['USD']; ?>">
                        <span class="input-group-text bg-light">поточний: <?php echo $rates['USD']; ?></span>
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">EUR → UAH</label>
                    <div class="input-group">
                        <input type="number" name="eur_rate" class="form-control" step="0.01" placeholder="<?php echo $rates['EUR']; ?>">
                        <span class="input-group-text bg-light">поточний: <?php echo $rates['EUR']; ?></span>
                    </div>
                </div>
            </div>

            <h6 class="fw-bold mb-2">Націнки за замовчуванням</h6>
            <div class="row g-3 mb-3">
                <div class="col-md-3">
                    <label class="form-label">Оптова, %</label>
                    <input type="number" name="default_markup_wholesale" class="form-control" step="0.1" value="<?php echo (float)($settings['default_markup_wholesale'] ?? 0); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Дрібнооптова, %</label>
                    <input type="number" name="default_markup_semi_wholesale" class="form-control" step="0.1" value="<?php echo (float)($settings['default_markup_semi_wholesale'] ?? 0); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Роздрібна, %</label>
                    <input type="number" name="default_markup_retail" class="form-control" step="0.1" value="<?php echo (float)($settings['default_markup_retail'] ?? 0); ?>">
                </div>
            </div>

            <h6 class="fw-bold mb-2">Реквізити постачальника (ФОП)</h6>
            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label">Назва ФОП</label>
                    <input type="text" name="supplier_name" class="form-control" value="<?php echo escape($settings['supplier_name'] ?? ''); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">ЄДРПОУ / ІПН</label>
                    <input type="text" name="supplier_edrpou" class="form-control" value="<?php echo escape($settings['supplier_edrpou'] ?? ''); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Телефон</label>
                    <input type="text" name="supplier_phone" class="form-control" value="<?php echo escape($settings['supplier_phone'] ?? ''); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">IBAN</label>
                    <input type="text" name="supplier_iban" class="form-control" value="<?php echo escape($settings['supplier_iban'] ?? ''); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Банк</label>
                    <input type="text" name="supplier_bank" class="form-control" value="<?php echo escape($settings['supplier_bank'] ?? ''); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">МФО</label>
                    <input type="text" name="supplier_mfo" class="form-control" value="<?php echo escape($settings['supplier_mfo'] ?? ''); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Свідоцтво №</label>
                    <input type="text" name="supplier_certificate" class="form-control" value="<?php echo escape($settings['supplier_certificate'] ?? ''); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Дата свідоцтва</label>
                    <input type="text" name="supplier_cert_date" class="form-control" value="<?php echo escape($settings['supplier_cert_date'] ?? ''); ?>">
                </div>
                <div class="col-md-5">
                    <label class="form-label">Адреса</label>
                    <input type="text" name="supplier_address" class="form-control" value="<?php echo escape($settings['supplier_address'] ?? ''); ?>">
                </div>
            </div>

            <h6 class="fw-bold mb-2">Синхронізація</h6>
            <div class="row g-3 mb-3">
                <div class="col-md-3">
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" name="auto_sync_enabled" id="autoSync" value="1" <?php echo ($settings['auto_sync_enabled'] ?? '0') === '1' ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="autoSync">Автосинхронізація</label>
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Інтервал (хв)</label>
                    <input type="number" name="sync_interval_minutes" class="form-control" value="<?php echo (int)($settings['sync_interval_minutes'] ?? 60); ?>">
                </div>
            </div>

            <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Зберегти</button>
        </form>
    </div>
</div>

<?php elseif ($tab === 'notifications'): ?>
<div class="card">
    <div class="card-header">Сповіщення (Telegram)</div>
    <div class="card-body">
        <form method="post">
            <p class="text-muted mb-3">Налаштування Telegram-бота для сповіщень про нові замовлення.</p>
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label">Bot Token</label>
                    <input type="text" name="tg_bot_token" class="form-control font-monospace" value="<?php echo escape($settings['tg_bot_token'] ?? ''); ?>" placeholder="123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11">
                    <div class="form-text">Отримайте у <a href="https://t.me/BotFather" target="_blank">@BotFather</a></div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Chat ID</label>
                    <input type="text" name="tg_chat_id" class="form-control" value="<?php echo escape($settings['tg_chat_id'] ?? ''); ?>" placeholder="-1001234567890">
                    <div class="form-text">ID чату або користувача. Дізнайтеся у <a href="https://t.me/userinfobot" target="_blank">@userinfobot</a></div>
                </div>
            </div>
            <div class="mb-3">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="notify_on_new_order" id="notifyOnNewOrder" value="1" <?php echo ($settings['notify_on_new_order'] ?? '0') === '1' ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="notifyOnNewOrder">Сповіщати про нові замовлення</label>
                </div>
            </div>
            <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Зберегти</button>
            <?php if (!empty($settings['tg_bot_token'] ?? '') && !empty($settings['tg_chat_id'] ?? '')): ?>
            <a href="?tab=notifications&test_telegram=1" class="btn btn-outline-info ms-2"><i class="bi bi-send"></i> Тест</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<?php elseif ($tab === 'logs'): ?>
<?php
    $logPage = max(1, (int)($_GET['log_page'] ?? 1));
    $logPerPage = 50;
    $logType = $_GET['log_type'] ?? '';
    $logWhere = '';
    $logParams = [];
    if ($logType) {
        $logWhere = 'WHERE l.type = ?';
        $logParams[] = $logType;
    }
    $logCount = $pdo->prepare("SELECT COUNT(*) FROM erp_activity_log l $logWhere");
    $logCount->execute($logParams);
    $logTotal = $logCount->fetchColumn();
    $logPagination = paginate($logTotal, $logPerPage, $logPage);

    $logSql = "SELECT l.*, u.username FROM erp_activity_log l LEFT JOIN erp_users u ON l.user_id = u.user_id $logWhere ORDER BY l.date_added DESC LIMIT {$logPagination['per_page']} OFFSET {$logPagination['offset']}";
    $logStmt = $pdo->prepare($logSql);
    $logStmt->execute($logParams);
    $logs = $logStmt->fetchAll();

    $logTypes = $pdo->query("SELECT DISTINCT type FROM erp_activity_log ORDER BY type")->fetchAll(PDO::FETCH_COLUMN);
?>
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-journal-text"></i> Логування</span>
        <div>
            <a href="?tab=logs&clear=1" class="btn btn-outline-danger btn-sm" onclick="return confirm('Очистити всі логи?')"><i class="bi bi-trash"></i> Очистити</a>
        </div>
    </div>
    <div class="card-body">
        <form method="get" class="row g-2 mb-3">
            <input type="hidden" name="tab" value="logs">
            <div class="col-auto">
                <select name="log_type" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">Усі типи</option>
                    <?php foreach ($logTypes as $lt): ?>
                    <option value="<?php echo escape($lt); ?>" <?php echo $logType === $lt ? 'selected' : ''; ?>><?php echo escape($lt); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>

        <?php if (count($logs) > 0): ?>
        <div class="table-container" style="max-height:500px;overflow-y:auto;">
            <table class="table table-sm table-hover mb-0">
                <thead>
                    <tr>
                        <th style="width:140px;">Час</th>
                        <th style="width:80px;">Тип</th>
                        <th style="width:100px;">Джерело</th>
                        <th>Повідомлення</th>
                        <th style="width:80px;">Користувач</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $l):
                        $typeClass = match($l['type']) {
                            'error' => 'text-danger',
                            'warning' => 'text-warning',
                            'success' => 'text-success',
                            default => 'text-muted'
                        };
                    ?>
                    <tr>
                        <td class="small"><?php echo date('d.m.Y H:i:s', strtotime($l['date_added'])); ?></td>
                        <td><span class="badge bg-<?php echo $l['type'] === 'error' ? 'danger' : ($l['type'] === 'warning' ? 'warning text-dark' : ($l['type'] === 'success' ? 'success' : 'secondary')); ?>"><?php echo escape($l['type']); ?></span></td>
                        <td class="small"><?php echo escape($l['source'] ?: '-'); ?></td>
                        <td class="small">
                            <?php echo escape($l['message']); ?>
                            <?php if ($l['data']): ?>
                            <button class="btn btn-sm btn-link py-0" onclick="alert(<?php echo htmlspecialchars(json_encode($l['data'], JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>)"><i class="bi bi-info-circle"></i></button>
                            <?php endif; ?>
                        </td>
                        <td class="small"><?php echo escape($l['username'] ?: '-'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($logPagination['total_pages'] > 1): ?>
        <div class="mt-2"><?php echo renderPagination(BASE_URL . '/modules/settings.php?tab=logs&', $logPagination, 'log_page'); ?></div>
        <?php endif; ?>
        <?php else: ?>
        <div class="text-center py-4 text-muted">
            <i class="bi bi-journal-text" style="font-size:2rem;"></i>
            <p class="mt-2 mb-0">Логів поки що немає</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($tab === 'cash'): ?>
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>Рахунки / Каси</span>
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#cashModal"><i class="bi bi-plus-lg"></i> Додати рахунок</button>
    </div>
    <div class="card-body p-0">
        <?php if (count($accounts) > 0): ?>
        <div class="table-container">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Назва</th>
                        <th>Тип</th>
                        <th>Валюта</th>
                        <th class="text-end">Початковий баланс</th>
                        <th>Статус</th>
                        <th class="text-center">Дії</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($accounts as $a):
                        $typeLabels = ['cash' => 'Готівка', 'bank' => 'Банк', 'fop' => 'ФОП'];
                    ?>
                    <tr>
                        <td class="fw-bold"><?php echo escape($a['name']); ?></td>
                        <td><?php echo $typeLabels[$a['type']] ?? $a['type']; ?></td>
                        <td><?php echo escape($a['currency']); ?></td>
                        <td class="text-end"><?php echo formatMoney($a['initial_balance']); ?></td>
                        <td><?php echo $a['status'] ? '<span class="badge bg-success">Активний</span>' : '<span class="badge bg-danger">Неактивний</span>'; ?></td>
                        <td class="text-center">
                            <button class="btn btn-sm btn-outline-primary edit-cash"
                                data-id="<?php echo $a['account_id']; ?>"
                                data-name="<?php echo escape($a['name']); ?>"
                                data-type="<?php echo $a['type']; ?>"
                                data-currency="<?php echo escape($a['currency']); ?>"
                                data-balance="<?php echo (float)$a['initial_balance']; ?>"
                                data-status="<?php echo (int)$a['status']; ?>"
                                title="Редагувати"><i class="bi bi-pencil"></i></button>
                            <a href="?tab=cash&delete=<?php echo $a['account_id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Видалити рахунок?')" title="Видалити"><i class="bi bi-trash"></i></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="text-center py-4 text-muted">
            <i class="bi bi-wallet2" style="font-size:2rem;"></i>
            <p class="mt-2 mb-0">Немає рахунків</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal fade" id="cashModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="account_id" id="editAccountId" value="0">
                <input type="hidden" name="save_account" value="1">
                <div class="modal-header">
                    <h5 class="modal-title" id="cashModalTitle">Новий рахунок</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label required">Назва</label>
                        <input type="text" name="name" id="editCashName" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label required">Тип</label>
                        <select name="type" id="editCashType" class="form-select">
                            <option value="cash">Готівка</option>
                            <option value="bank">Банк</option>
                            <option value="fop">ФОП</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label required">Валюта</label>
                        <select name="currency" id="editCashCurrency" class="form-select">
                            <option value="UAH">UAH</option>
                            <option value="USD">USD</option>
                            <option value="EUR">EUR</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Початковий баланс</label>
                        <input type="number" name="initial_balance" id="editCashBalance" class="form-control" step="0.01" value="0">
                    </div>
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="status" id="editCashStatus" value="1" checked>
                            <label class="form-check-label">Активний</label>
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

<?php elseif ($tab === 'delivery'): ?>
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>Способи доставки</span>
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#deliveryModal"><i class="bi bi-plus-lg"></i> Додати</button>
    </div>
    <div class="card-body p-0">
        <?php if (count($deliveryMethods) > 0): ?>
        <div class="table-container">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Код</th>
                        <th>Назва</th>
                        <th class="text-center">Порядок</th>
                        <th>Статус</th>
                        <th class="text-center">Дії</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($deliveryMethods as $m): ?>
                    <tr>
                        <td><code><?php echo escape($m['code']); ?></code></td>
                        <td class="fw-bold"><?php echo escape($m['name']); ?></td>
                        <td class="text-center"><?php echo (int)$m['sort_order']; ?></td>
                        <td><?php echo $m['status'] ? '<span class="badge bg-success">Активно</span>' : '<span class="badge bg-danger">Вимкнено</span>'; ?></td>
                        <td class="text-center">
                            <button class="btn btn-sm btn-outline-primary edit-delivery"
                                data-id="<?php echo $m['method_id']; ?>"
                                data-code="<?php echo escape($m['code']); ?>"
                                data-name="<?php echo escape($m['name']); ?>"
                                data-sort="<?php echo (int)$m['sort_order']; ?>"
                                data-status="<?php echo (int)$m['status']; ?>"
                                title="Редагувати"><i class="bi bi-pencil"></i></button>
                            <a href="?tab=delivery&delete=<?php echo $m['method_id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Видалити спосіб доставки?')" title="Видалити"><i class="bi bi-trash"></i></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="text-center py-4 text-muted">
            <i class="bi bi-truck" style="font-size:2rem;"></i>
            <p class="mt-2 mb-0">Немає способів доставки</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal fade" id="deliveryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="method_id" id="editDeliveryId" value="0">
                <input type="hidden" name="save_delivery" value="1">
                <div class="modal-header">
                    <h5 class="modal-title" id="deliveryModalTitle">Новий спосіб доставки</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label required">Код</label>
                        <input type="text" name="code" id="editDeliveryCode" class="form-control" required pattern="[a-z_]+" title="Тільки латинські літери та знак підкреслення">
                    </div>
                    <div class="mb-3">
                        <label class="form-label required">Назва</label>
                        <input type="text" name="name" id="editDeliveryName" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Порядок сортування</label>
                        <input type="number" name="sort_order" id="editDeliverySort" class="form-control" value="0">
                    </div>
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="status" id="editDeliveryStatus" value="1" checked>
                            <label class="form-check-label">Активно</label>
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

<?php elseif ($tab === 'payment'): ?>
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>Способи оплати</span>
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#paymentModal"><i class="bi bi-plus-lg"></i> Додати</button>
    </div>
    <div class="card-body p-0">
        <?php if (count($paymentMethods) > 0): ?>
        <div class="table-container">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Код</th>
                        <th>Назва</th>
                        <th class="text-center">Порядок</th>
                        <th>Статус</th>
                        <th class="text-center">Дії</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($paymentMethods as $m): ?>
                    <tr>
                        <td><code><?php echo escape($m['code']); ?></code></td>
                        <td class="fw-bold"><?php echo escape($m['name']); ?></td>
                        <td class="text-center"><?php echo (int)$m['sort_order']; ?></td>
                        <td><?php echo $m['status'] ? '<span class="badge bg-success">Активно</span>' : '<span class="badge bg-danger">Вимкнено</span>'; ?></td>
                        <td class="text-center">
                            <button class="btn btn-sm btn-outline-primary edit-payment"
                                data-id="<?php echo $m['method_id']; ?>"
                                data-code="<?php echo escape($m['code']); ?>"
                                data-name="<?php echo escape($m['name']); ?>"
                                data-sort="<?php echo (int)$m['sort_order']; ?>"
                                data-status="<?php echo (int)$m['status']; ?>"
                                title="Редагувати"><i class="bi bi-pencil"></i></button>
                            <a href="?tab=payment&delete=<?php echo $m['method_id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Видалити спосіб оплати?')" title="Видалити"><i class="bi bi-trash"></i></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="text-center py-4 text-muted">
            <i class="bi bi-credit-card" style="font-size:2rem;"></i>
            <p class="mt-2 mb-0">Немає способів оплати</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal fade" id="paymentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="method_id" id="editPaymentId" value="0">
                <input type="hidden" name="save_payment" value="1">
                <div class="modal-header">
                    <h5 class="modal-title" id="paymentModalTitle">Новий спосіб оплати</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label required">Код</label>
                        <input type="text" name="code" id="editPaymentCode" class="form-control" required pattern="[a-z_]+" title="Тільки латинські літери та знак підкреслення">
                    </div>
                    <div class="mb-3">
                        <label class="form-label required">Назва</label>
                        <input type="text" name="name" id="editPaymentName" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Порядок сортування</label>
                        <input type="number" name="sort_order" id="editPaymentSort" class="form-control" value="0">
                    </div>
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="status" id="editPaymentStatus" value="1" checked>
                            <label class="form-check-label">Активно</label>
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

<?php elseif ($tab === 'users'): ?>
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>Користувачі</span>
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#userModal"><i class="bi bi-plus-lg"></i> Додати</button>
    </div>
    <div class="card-body p-0">
        <?php if (count($users) > 0): ?>
        <div class="table-container">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Ім'я</th>
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
                            <a href="?tab=users&delete_user=<?php echo $u['user_id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Видалити користувача?')" title="Видалити"><i class="bi bi-trash"></i></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="text-center py-4 text-muted">
            <i class="bi bi-people" style="font-size:2rem;"></i>
            <p class="mt-2 mb-0">Немає користувачів</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal fade" id="userModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="user_id" id="editUserId" value="0">
                <input type="hidden" name="save_user" value="1">
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
                            <input class="form-check-input" type="checkbox" name="status" id="editUserStatus" value="1" checked>
                            <label class="form-check-label">Активний</label>
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
<?php endif; ?>

<script>
document.querySelectorAll('.edit-cash').forEach(function(btn) {
    btn.addEventListener('click', function() {
        document.getElementById('editAccountId').value = this.dataset.id;
        document.getElementById('editCashName').value = this.dataset.name;
        document.getElementById('editCashType').value = this.dataset.type;
        document.getElementById('editCashCurrency').value = this.dataset.currency;
        document.getElementById('editCashBalance').value = this.dataset.balance;
        document.getElementById('editCashStatus').checked = this.dataset.status === '1';
        document.getElementById('cashModalTitle').textContent = 'Редагувати рахунок';
        new bootstrap.Modal(document.getElementById('cashModal')).show();
    });
});
document.querySelector('[data-bs-target="#cashModal"]')?.addEventListener('click', function() {
    document.getElementById('editAccountId').value = '0';
    document.getElementById('editCashName').value = '';
    document.getElementById('editCashType').value = 'cash';
    document.getElementById('editCashCurrency').value = 'UAH';
    document.getElementById('editCashBalance').value = '0';
    document.getElementById('editCashStatus').checked = true;
    document.getElementById('cashModalTitle').textContent = 'Новий рахунок';
});

document.querySelectorAll('.edit-delivery').forEach(function(btn) {
    btn.addEventListener('click', function() {
        document.getElementById('editDeliveryId').value = this.dataset.id;
        document.getElementById('editDeliveryCode').value = this.dataset.code;
        document.getElementById('editDeliveryName').value = this.dataset.name;
        document.getElementById('editDeliverySort').value = this.dataset.sort;
        document.getElementById('editDeliveryStatus').checked = this.dataset.status === '1';
        document.getElementById('deliveryModalTitle').textContent = 'Редагувати спосіб доставки';
        new bootstrap.Modal(document.getElementById('deliveryModal')).show();
    });
});
document.querySelector('[data-bs-target="#deliveryModal"]')?.addEventListener('click', function() {
    document.getElementById('editDeliveryId').value = '0';
    document.getElementById('editDeliveryCode').value = '';
    document.getElementById('editDeliveryName').value = '';
    document.getElementById('editDeliverySort').value = '0';
    document.getElementById('editDeliveryStatus').checked = true;
    document.getElementById('deliveryModalTitle').textContent = 'Новий спосіб доставки';
});

document.querySelectorAll('.edit-payment').forEach(function(btn) {
    btn.addEventListener('click', function() {
        document.getElementById('editPaymentId').value = this.dataset.id;
        document.getElementById('editPaymentCode').value = this.dataset.code;
        document.getElementById('editPaymentName').value = this.dataset.name;
        document.getElementById('editPaymentSort').value = this.dataset.sort;
        document.getElementById('editPaymentStatus').checked = this.dataset.status === '1';
        document.getElementById('paymentModalTitle').textContent = 'Редагувати спосіб оплати';
        new bootstrap.Modal(document.getElementById('paymentModal')).show();
    });
});
document.querySelector('[data-bs-target="#paymentModal"]')?.addEventListener('click', function() {
    document.getElementById('editPaymentId').value = '0';
    document.getElementById('editPaymentCode').value = '';
    document.getElementById('editPaymentName').value = '';
    document.getElementById('editPaymentSort').value = '0';
    document.getElementById('editPaymentStatus').checked = true;
    document.getElementById('paymentModalTitle').textContent = 'Новий спосіб оплати';
});

document.querySelectorAll('.edit-user').forEach(function(btn) {
    btn.addEventListener('click', function() {
        document.getElementById('editUserId').value = this.dataset.id;
        document.getElementById('editUsername').value = this.dataset.username;
        document.getElementById('editEmail').value = this.dataset.email;
        document.getElementById('editRole').value = this.dataset.role;
        document.getElementById('editUserStatus').checked = this.dataset.status === '1';
        document.getElementById('userModalTitle').textContent = 'Редагувати користувача';
        document.getElementById('passwordLabel').textContent = 'Новий пароль';
        document.getElementById('passwordHelp').style.display = 'block';
        document.getElementById('editPassword').required = false;
        new bootstrap.Modal(document.getElementById('userModal')).show();
    });
});
document.querySelector('[data-bs-target="#userModal"]')?.addEventListener('click', function() {
    document.getElementById('editUserId').value = '0';
    document.getElementById('editUsername').value = '';
    document.getElementById('editEmail').value = '';
    document.getElementById('editRole').value = 'staff';
    document.getElementById('editUserStatus').checked = true;
    document.getElementById('userModalTitle').textContent = 'Новий користувач';
    document.getElementById('passwordLabel').textContent = 'Пароль';
    document.getElementById('passwordHelp').style.display = 'none';
    document.getElementById('editPassword').required = true;
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
