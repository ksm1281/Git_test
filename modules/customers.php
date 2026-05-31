<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../_helpers.php';
requireLogin();

$action = $_GET['action'] ?? '';

if ($action === 'delete' && isManager()) {
    $customerId = (int)($_GET['id'] ?? 0);
    if ($customerId) {
        $pdo->prepare("DELETE FROM erp_customer_notes WHERE customer_id = ?")->execute([$customerId]);
        $pdo->prepare("DELETE FROM erp_orders WHERE customer_id = ?")->execute([$customerId]);
        $pdo->prepare("DELETE FROM erp_customers WHERE customer_id = ?")->execute([$customerId]);
        flashMessage('success', 'Клієнта видалено');
    }
    redirect(BASE_URL . '/modules/customers.php');
}

if ($action === 'delete_all' && isManager()) {
    $pdo->exec("DELETE FROM erp_customer_notes");
    $pdo->exec("DELETE FROM erp_orders WHERE customer_id IS NOT NULL");
    $pdo->exec("DELETE FROM erp_customers");
    flashMessage('success', 'Усіх клієнтів видалено');
    redirect(BASE_URL . '/modules/customers.php');
}

if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST' && isManager()) {
    $customerId = (int)($_POST['customer_id'] ?? 0);
    $patronymic = trim($_POST['patronymic'] ?? '');
    $companyName = trim($_POST['company_name'] ?? '');
    $edrpou = trim($_POST['edrpou'] ?? '');
    $legalAddress = trim($_POST['legal_address'] ?? '');
    $iban = trim($_POST['iban'] ?? '');
    $mfo = trim($_POST['mfo'] ?? '');
    $dateStart = trim($_POST['date_start'] ?? '');
    $dateEnd = trim($_POST['date_end'] ?? '');

    if ($customerId) {
        $stmt = $pdo->prepare("UPDATE erp_customers SET patronymic=?, company_name=?, edrpou=?, legal_address=?, iban=?, mfo=?, date_start=?, date_end=? WHERE customer_id=?");
        $stmt->execute([$patronymic, $companyName, $edrpou, $legalAddress, $iban, $mfo, $dateStart ?: null, $dateEnd ?: null, $customerId]);
        flashMessage('success', 'Дані клієнта збережено');
    }
    redirect(BASE_URL . '/modules/customers.php?id=' . $customerId);
}

if ($action === 'import' && $_SERVER['REQUEST_METHOD'] === 'POST' && isManager()) {
    $importResult = ['added' => 0, 'updated' => 0, 'errors' => [], 'rows' => 0];

    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $importResult['errors'][] = 'Помилка завантаження файлу';
    } else {
        $fh = fopen($_FILES['csv_file']['tmp_name'], 'r');
        if (!$fh) {
            $importResult['errors'][] = 'Не вдалося відкрити файл';
        } else {
            $bom = fread($fh, 3);
            if ($bom !== "\xEF\xBB\xBF") {
                rewind($fh);
            }

            $firstLine = fgets($fh);
            if ($firstLine === false) {
                $importResult['errors'][] = 'Порожній файл';
            } else {
                $commaCount = substr_count($firstLine, ',');
                $semicolonCount = substr_count($firstLine, ';');
                $tabCount = substr_count($firstLine, "\t");
                $delimiter = $semicolonCount >= $commaCount && $semicolonCount >= $tabCount ? ';' : ($tabCount >= $commaCount ? "\t" : ',');
                rewind($fh);
                $bomCheck = fread($fh, 3);
                if ($bomCheck !== "\xEF\xBB\xBF") rewind($fh);

                $headers = fgetcsv($fh, 0, $delimiter);
                if (!$headers) {
                    $importResult['errors'][] = 'Порожній файл';
                } else {
                    $headers = array_map('trim', $headers);
                    $headerMapLower = [];
                    foreach ($headers as $i => $h) {
                        $headerMapLower[mb_strtolower($h)] = $i;
                    }
                    $importResult['detected_headers'] = $headers;

                    $pdo->beginTransaction();
                    try {
                        $firstRowShown = false;
                        while (($row = fgetcsv($fh, 0, $delimiter)) !== false) {
                            $importResult['rows']++;

                            if (count($row) === 1 && trim($row[0]) === '') continue;
                            if (count($row) < count($headers)) continue;

                            if (!mb_check_encoding(implode('', $row), 'UTF-8')) {
                                for ($i = 0; $i < count($row); $i++) {
                                    if (is_string($row[$i]) && $row[$i] !== '') {
                                        $row[$i] = mb_convert_encoding($row[$i], 'UTF-8', 'CP1251');
                                    }
                                }
                            }

                            $get = function($name) use ($row, $headerMapLower) {
                                $idx = isset($headerMapLower[mb_strtolower($name)]) ? $headerMapLower[mb_strtolower($name)] : null;
                                return $idx !== null && isset($row[$idx]) ? trim($row[$idx]) : '';
                            };

                            $idKlient = $get('id_Klient') ?: $get('PastID') ?: $get('ID') ?: $get('id') ?: $get('Код');
                            $number = $get('Number') ?: $get('Phone') ?: $get('Telephone') ?: $get('Телефон');
                            $familya = $get('Familya') ?: $get('Surname') ?: $get('Прізвище') ?: $get('Фамилия');
                            $imya = $get('Imya') ?: $get('Name') ?: $get("Iм'я") ?: $get('Имя');
                            $otchestvo = $get('Otchestvo') ?: $get('Patronymic') ?: $get('По батькові') ?: $get('Отчество');

                            if (!$familya && !$imya && !$otchestvo) {
                                $pib = $get('ПІБ') ?: $get('ФИО') ?: $get('FullName') ?: $get('FIO') ?: $get('ПИБ') ?: $get('PIB');
                                if (!$pib) {
                                    $pib = $get('NazvUch') ?: $get('Company') ?: $get('Назва');
                                }
                                if ($pib) {
                                    $parts = preg_split('/\s+/', trim($pib));
                                    if (count($parts) >= 3) {
                                        $familya = $parts[0];
                                        $imya = $parts[1];
                                        $otchestvo = implode(' ', array_slice($parts, 2));
                                    } elseif (count($parts) === 2) {
                                        $familya = $parts[0];
                                        $imya = $parts[1];
                                    } elseif (count($parts) === 1) {
                                        $familya = $parts[0];
                                    }
                                }
                            }

                            if (!$familya && !$imya) continue;

                            if (!$firstRowShown && ($familya || $imya)) {
                                $importResult['sample_raw'] = 'count=' . count($row) . ' ' . json_encode($row, JSON_UNESCAPED_UNICODE);
                                $importResult['sample_row'] = array_combine($headers, array_slice($row, 0, count($headers)));
                                $importResult['parsed'] = [
                                    'Прізвище' => $familya ?: '(пусто)',
                                    'Ім\'я' => $imya ?: '(пусто)',
                                    'По батькові' => $otchestvo ?: '(пусто)',
                                ];
                                $firstRowShown = true;
                            }
                            $region = $get('Region') ?: $get('Область') ?: $get('Регион');
                            $city = $get('City') ?: $get('Місто') ?: $get('Город');
                            $ulitsa = $get('Ulitsa') ?: $get('Street') ?: $get('Вулиця') ?: $get('Улица');
                            $dom = $get('Dom') ?: $get('House') ?: $get('Будинок') ?: $get('Дом');
                            $etaj = $get('Etaj') ?: $get('Floor');
                            $ofis = $get('Ofis') ?: $get('Office') ?: $get('Квартира') ?: $get('Офис');
                            $tipUch = $get('TipUch') ?: $get('TaxType') ?: $get('Тип');
                            $nazvUch = $get('NazvUch') ?: $get('Company') ?: $get('Назва');
                            $dostavka = $get('Dostavka') ?: $get('Delivery') ?: $get('Доставка');
                            $dostavsik = $get('Dostavsik') ?: $get('DeliveryService') ?: $get('Перевізник');
                            $otdelenie = $get('Otdelenie') ?: $get('Branch') ?: $get('Відділення');
                            $otdelenieAdres = $get('OtdelenieAdres') ?: $get('BranchAddress') ?: $get('Адреса');
                            $dopInf = $get('Klient_Dop_inf') ?: $get('Dop_info') ?: $get('Info') ?: $get('Info');
                            $dateStart = convertUaDate($get('date_start') ?: $get('DateStart') ?: $get('Дата початку'));
                            $dateEnd = convertUaDate($get('date_end') ?: $get('DateEnd') ?: $get('Дата закінчення'));
                            $email = $get('Email') ?: $get('email') ?: $get('E-mail') ?: $get('Почта');

                            if (!$idKlient && !$number) continue;

                        $customerId = (int)$idKlient;

                        $digits = preg_replace('/\D/', '', $number);
                        $telephone = '';
                        if (strlen($digits) > 0) {
                            if (strpos($digits, '380') === 0) {
                                $telephone = $digits;
                            } else {
                                if ($digits[0] === '8') $digits = substr($digits, 1);
                                if (strpos($digits, '380') === 0) {
                                    $telephone = $digits;
                                } elseif (strpos($digits, '0') === 0) {
                                    $telephone = '380' . substr($digits, 1);
                                } else {
                                    $telephone = '380' . $digits;
                                }
                            }
                        }

                        $addressParts = array_filter([$region, $city, $ulitsa, $dom, $etaj, $ofis]);
                        $legalAddress = implode(', ', $addressParts);

                        $stmt = $pdo->prepare("SELECT customer_id FROM erp_customers WHERE telephone = ? LIMIT 1");
                        $stmt->execute([$telephone]);
                        $existing = $stmt->fetch();

                        if ($existing) {
                            $customerId = (int)$existing['customer_id'];
                            $stmt = $pdo->prepare("UPDATE erp_customers SET firstname=?, lastname=?, patronymic=?, email=?, telephone=CASE WHEN ?!='' THEN ? ELSE telephone END, group_name=?, company_name=?, legal_address=?, date_start=?, date_end=? WHERE customer_id=?");
                            $stmt->execute([$imya, $familya, $otchestvo, $email, $telephone, $telephone, $tipUch, $nazvUch, $legalAddress, $dateStart ?: null, $dateEnd ?: null, $customerId]);
                            $importResult['updated']++;
                        } elseif ($customerId) {
                            $stmt = $pdo->prepare("SELECT customer_id FROM erp_customers WHERE customer_id = ?");
                            $stmt->execute([$customerId]);
                            $existingById = $stmt->fetch();
                            if ($existingById) {
                                $stmt = $pdo->prepare("UPDATE erp_customers SET firstname=?, lastname=?, patronymic=?, email=?, telephone=CASE WHEN ?!='' THEN ? ELSE telephone END, group_name=?, company_name=?, legal_address=?, date_start=?, date_end=? WHERE customer_id=?");
                                $stmt->execute([$imya, $familya, $otchestvo, $email, $telephone, $telephone, $tipUch, $nazvUch, $legalAddress, $dateStart ?: null, $dateEnd ?: null, $customerId]);
                                $importResult['updated']++;
                            } else {
                                $stmt = $pdo->prepare("INSERT INTO erp_customers (customer_id, firstname, lastname, patronymic, email, telephone, group_name, company_name, legal_address, date_start, date_end, date_added) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                                $stmt->execute([$customerId, $imya, $familya, $otchestvo, $email, $telephone, $tipUch, $nazvUch, $legalAddress, $dateStart ?: null, $dateEnd ?: null]);
                                $importResult['added']++;
                            }
                        } else {
                            $stmt = $pdo->query("SELECT COALESCE(MAX(customer_id), 0) + 1 FROM erp_customers");
                            $customerId = (int)$stmt->fetchColumn();
                            $stmt = $pdo->prepare("INSERT INTO erp_customers (customer_id, firstname, lastname, patronymic, email, telephone, group_name, company_name, legal_address, date_start, date_end, date_added) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                            $stmt->execute([$customerId, $imya, $familya, $otchestvo, $email, $telephone, $tipUch, $nazvUch, $legalAddress, $dateStart ?: null, $dateEnd ?: null]);
                            $importResult['added']++;
                        }

                        $noteParts = [];
                        if ($dostavka) $noteParts[] = "Доставка: $dostavka";
                        if ($dostavsik) $noteParts[] = "Доставник: $dostavsik";
                        if ($otdelenie) $noteParts[] = "Відділення: $otdelenie";
                        if ($otdelenieAdres) $noteParts[] = "Адреса відділення: $otdelenieAdres";
                        if ($dopInf) $noteParts[] = "Дод. інфо: $dopInf";

                        if ($noteParts) {
                            $note = implode("\n", $noteParts);
                            $stmt = $pdo->prepare("INSERT INTO erp_customer_notes (customer_id, user_id, note) VALUES (?, ?, ?)");
                            $stmt->execute([$customerId, 0, $note]);
                        }
                    }
                    $pdo->commit();
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $importResult['errors'][] = 'Помилка імпорту: ' . $e->getMessage();
                }
            }
            fclose($fh);
        }
    }
    $showImportResult = $importResult;
    }
}

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$search = trim($_GET['search'] ?? '');

$where = '';
$params = [];
if ($search) {
    $where = "WHERE (c.firstname LIKE ? OR c.lastname LIKE ? OR c.patronymic LIKE ? OR c.email LIKE ? OR c.telephone LIKE ? OR c.company_name LIKE ? OR CAST(c.customer_id AS CHAR) LIKE ?)";
    $s = "%$search%";
    $params = [$s, $s, $s, $s, $s, $s, $s];
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
    <div class="d-flex gap-2">
        <?php if (isManager()): ?>
        <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#importModal"><i class="bi bi-upload"></i> Імпорт</button>
        <a href="<?php echo BASE_URL; ?>/modules/customers.php?action=delete_all" class="btn btn-sm btn-outline-danger" onclick="return confirm('Видалити ВСІХ клієнтів? Це незворотньо.')"><i class="bi bi-trash"></i> Очистити</a>
        <?php endif; ?>
        <form method="get" class="d-flex">
            <input type="search" name="search" class="form-control form-control-sm search-box me-2" placeholder="Пошук..." value="<?php echo escape($search); ?>">
            <button class="btn btn-sm btn-outline-primary"><i class="bi bi-search"></i></button>
        </form>
    </div>
</div>

<?php if (isset($showImportResult)): ?>
<div class="card mb-3 border-success">
    <div class="card-header bg-success text-white">
        <i class="bi bi-check-circle"></i> Результат імпорту
    </div>
    <div class="card-body">
        <p class="mb-2">Оброблено рядків: <strong><?php echo $showImportResult['rows']; ?></strong></p>
        <ul class="mb-0">
            <li class="text-success">Додано: <strong><?php echo $showImportResult['added']; ?></strong></li>
            <li class="text-primary">Оновлено: <strong><?php echo $showImportResult['updated']; ?></strong></li>
            <?php if (!empty($showImportResult['detected_headers'])): ?>
            <li>Знайдені колонки: <code><?php echo escape(implode(' | ', $showImportResult['detected_headers'])); ?></code></li>
            <?php endif; ?>
            <?php if (!empty($showImportResult['sample_row'])): ?>
            <li>Приклад першого рядка:
                <div class="small mt-1" style="max-height:120px;overflow-y:auto;background:#f8f9fa;padding:6px;border-radius:4px;">
                <?php foreach ($showImportResult['sample_row'] as $col => $val): ?>
                <span class="text-nowrap me-3"><strong><?php echo escape($col); ?>:</strong> <?php echo escape(mb_substr($val, 0, 60)); ?></span><br>
                <?php endforeach; ?>
                </div>
            </li>
            <?php endif; ?>
            <?php if (!empty($showImportResult['sample_raw'])): ?>
            <li>RAW fgetcsv (перший рядок):
                <div class="small mt-1" style="max-height:200px;overflow-y:auto;background:#f0f0f0;padding:6px;border-radius:4px;font-family:monospace;word-break:break-all;">
                <?php echo escape(mb_substr($showImportResult['sample_raw'], 0, 2000)); ?>
                </div>
            </li>
            <?php endif; ?>
            <?php if (!empty($showImportResult['parsed'])): ?>
            <li>Розпарсено з рядка:
                <div class="small mt-1" style="background:#fff3cd;padding:6px;border-radius:4px;">
                <?php foreach ($showImportResult['parsed'] as $field => $val): ?>
                <span class="text-nowrap me-3"><strong><?php echo escape($field); ?>:</strong> <?php echo escape($val); ?></span><br>
                <?php endforeach; ?>
                </div>
            </li>
            <?php endif; ?>
            <?php if ($showImportResult['errors']): ?>
            <li class="text-danger">Помилки:
                <ul>
                <?php foreach ($showImportResult['errors'] as $err): ?>
                    <li><?php echo escape($err); ?></li>
                <?php endforeach; ?>
                </ul>
            </li>
            <?php endif; ?>
        </ul>
    </div>
</div>
<?php endif; ?>

<?php if (!isset($_GET['id'])): ?>
<div class="card">
    <div class="card-body p-0">
        <?php if (count($customers) > 0): ?>
        <div class="table-container">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Прізвище</th>
                        <th>Ім'я</th>
                        <th>По батькові</th>
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
                        <td><?php echo escape($c['lastname'] ?: '-'); ?></td>
                        <td><?php echo escape($c['firstname'] ?: '-'); ?></td>
                        <td><?php echo escape($c['patronymic'] ?: '-'); ?></td>
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
                            <a href="<?php echo BASE_URL; ?>/modules/customers.php?id=<?php echo $c['customer_id']; ?>" class="btn btn-sm btn-outline-primary" title="Переглянути"><i class="bi bi-eye"></i></a>
                            <?php if (isManager()): ?>
                            <a href="<?php echo BASE_URL; ?>/modules/customers.php?action=delete&id=<?php echo $c['customer_id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Видалити клієнта?')" title="Видалити"><i class="bi bi-trash"></i></a>
                            <?php endif; ?>
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
<?php endif; ?>

<?php
if (isset($_GET['id'])) {
    $customerId = (int)$_GET['id'];
    $stmt = $pdo->prepare("SELECT * FROM erp_customers WHERE customer_id = ?");
    $stmt->execute([$customerId]);
    $customer = $stmt->fetch();
    if ($customer):
    $stmt = $pdo->prepare("SELECT cn.*, u.username FROM erp_customer_notes cn LEFT JOIN erp_users u ON cn.user_id = u.user_id WHERE cn.customer_id = ? ORDER BY cn.date_added DESC LIMIT 100");
    $stmt->execute([$customerId]);
    $notes = $stmt->fetchAll();
    $stmt = $pdo->prepare("SELECT * FROM erp_orders WHERE customer_id = ? ORDER BY date_added DESC LIMIT 10");
    $stmt->execute([$customerId]);
    $orders = $stmt->fetchAll();
?>
    <div class="card mt-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>Клієнт: <?php echo escape(trim(($customer['lastname'] ?? '') . ' ' . ($customer['firstname'] ?? '') . ' ' . ($customer['patronymic'] ?? ''))); ?></span>
            <div>
                <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#customerRequisitesModal"><i class="bi bi-pencil"></i> Реквізити</button>
                <a href="<?php echo BASE_URL; ?>/modules/customers.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> Назад</a>
            </div>
        </div>
        <div class="card-body">
            <div class="row g-3 mb-3">
                <div class="col-md-3">
                    <small class="text-muted">ПІБ</small>
                    <div><?php echo escape(trim(($customer['lastname'] ?? '') . ' ' . ($customer['firstname'] ?? '') . ' ' . ($customer['patronymic'] ?? ''))); ?></div>
                </div>
                <div class="col-md-2">
                    <small class="text-muted">Email</small>
                    <div><?php echo escape($customer['email'] ?: '-'); ?></div>
                </div>
                <div class="col-md-2">
                    <small class="text-muted">Телефон</small>
                    <div><?php echo escape($customer['telephone'] ?: '-'); ?></div>
                </div>
                <div class="col-md-2">
                    <small class="text-muted">Група</small>
                    <div><?php echo escape($customer['group_name'] ?: '-'); ?></div>
                </div>
                <div class="col-md-3">
                    <small class="text-muted">Всього замовлень</small>
                    <div class="fw-bold"><?php echo (int)$customer['total_orders']; ?> / <?php echo formatMoney($customer['total_spent']); ?></div>
                </div>
            </div>
            <?php if ($customer['date_start'] || $customer['date_end']): ?>
            <div class="row g-3 mb-3">
                <div class="col-md-3">
                    <small class="text-muted">Дата початку</small>
                    <div><?php echo $customer['date_start'] ? formatDate($customer['date_start']) : '-'; ?></div>
                </div>
                <div class="col-md-3">
                    <small class="text-muted">Дата закінчення</small>
                    <div><?php echo $customer['date_end'] ? formatDate($customer['date_end']) : '-'; ?></div>
                </div>
            </div>
            <?php endif; ?>

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
                        <label class="form-label">По батькові</label>
                        <input type="text" name="patronymic" class="form-control" value="<?php echo escape($customer['patronymic'] ?? ''); ?>">
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Дата початку</label>
                            <input type="date" name="date_start" class="form-control" value="<?php echo escape($customer['date_start'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Дата закінчення</label>
                            <input type="date" name="date_end" class="form-control" value="<?php echo escape($customer['date_end'] ?? ''); ?>">
                        </div>
                    </div>
                    <hr>
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

<?php if (isManager()): ?>
<div class="modal fade" id="importModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" action="?action=import" enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title">Імпорт клієнтів з CSV</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">CSV файл (UTF-8)</label>
                        <input type="file" name="csv_file" class="form-control" accept=".csv,.txt" required>
                    </div>
                    <small class="text-muted">
                        Очікувані колонки: id_Klient, Number, Familya, Imya, Otchestvo, Region, City, Ulitsa, Dom, Etaj, Ofis, TipUch, NazvUch, Dostavka, Dostavsik, Otdelenie, OtdelenieAdres, Klient_Dop_inf, date_start, date_end, Email<br>
                        Дублікати визначаються за номером телефону.
                    </small>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Скасувати</button>
                    <button type="submit" class="btn btn-success"><i class="bi bi-upload"></i> Імпортувати</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
