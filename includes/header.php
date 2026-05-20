<?php
require_once __DIR__ . '/../config.php';
requireLogin();

$user = getUserData();
$flash = getFlashMessage();
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo BASE_URL; ?>/assets/css/style.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="<?php echo BASE_URL; ?>/index.php">
                <i class="bi bi-box-seam"></i> <?php echo APP_NAME; ?>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="mainNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'index.php' ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>/index.php">
                            <i class="bi bi-speedometer2"></i> Дашборд
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'stock.php' ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>/modules/stock.php">
                            <i class="bi bi-boxes"></i> Склад
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'incoming.php' ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>/modules/incoming.php">
                            <i class="bi bi-receipt"></i> Прихідні
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'suppliers.php' ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>/modules/suppliers.php">
                            <i class="bi bi-truck"></i> Постачальники
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'pricing.php' ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>/modules/pricing.php">
                            <i class="bi bi-currency-exchange"></i> Ціни
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'orders.php' ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>/modules/orders.php">
                            <i class="bi bi-cart3"></i> Замовлення
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'customers.php' ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>/modules/customers.php">
                            <i class="bi bi-people"></i> Клієнти
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'returns.php' ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>/modules/returns.php">
                            <i class="bi bi-arrow-return-left"></i> Повернення
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'analytics.php' ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>/modules/analytics.php">
                            <i class="bi bi-graph-up"></i> Аналітика
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'finance.php' ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>/modules/finance.php">
                            <i class="bi bi-wallet2"></i> Фінанси
                        </a>
                    </li>
                    <?php if (isAdmin()): ?>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'settings.php' ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>/modules/settings.php">
                            <i class="bi bi-gear-wide"></i> Налаштування
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $currentPage === 'staff.php' ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>/modules/staff.php">
                            <i class="bi bi-person-gear"></i> Персонал
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
                <ul class="navbar-nav">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                            <i class="bi bi-person-circle"></i> <?php echo escape($user['username']); ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/login.php?action=logout">
                                <i class="bi bi-box-arrow-right"></i> Вийти
                            </a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container-fluid py-3">
        <?php if ($flash): ?>
        <div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'danger'; ?> alert-dismissible fade show">
            <?php echo escape($flash['message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
