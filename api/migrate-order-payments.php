<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../_helpers.php';

requireLogin();
if (!isAdmin()) {
    die('Недостатньо прав');
}

$fopAccount = $pdo->query("SELECT account_id FROM erp_cash_accounts WHERE type = 'fop' AND status = 1 LIMIT 1")->fetchColumn();
if (!$fopAccount) {
    die("Помилка: не знайдено ФОП рахунок. Спочатку створіть рахунок з типом 'fop' у налаштуваннях.");
}

$stmt = $pdo->query("
    SELECT p.*, o.total FROM erp_payments p
    JOIN erp_orders o ON p.order_id = o.order_id
    WHERE p.order_id IS NOT NULL
");
$payments = $stmt->fetchAll();

$created = 0;
$skipped = 0;
foreach ($payments as $pmt) {
    $check = $pdo->prepare("SELECT COUNT(*) FROM erp_transactions WHERE reference_type = 'order' AND reference_id = ?");
    $check->execute([$pmt['order_id']]);
    if ($check->fetchColumn() > 0) {
        $skipped++;
        continue;
    }
    $stmt = $pdo->prepare("INSERT INTO erp_transactions (account_id, type, amount, method, category, date, description, reference_type, reference_id, user_id) VALUES (?, 'in', ?, ?, 'Продажі', ?, ?, 'order', ?, ?)");
    $stmt->execute([
        $fopAccount,
        $pmt['amount'],
        $pmt['method'],
        $pmt['date'],
        'Оплата замовлення #' . $pmt['order_id'] . ' (історична)',
        $pmt['order_id'],
        $pmt['user_id'] ?: 1,
    ]);
    $created++;
}

echo "Створено транзакцій: {$created}, пропущено (вже існують): {$skipped}";
