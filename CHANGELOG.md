# Changelog

## [1.9.0] — 2026-06-07

### Змінено
- **modules/orders.php**: міграція таблиці товарів на Alpine.js — реактивні суми рядка й загального підсумку, автокомпліт, додавання/видалення рядків
- **modules/incoming.php**: така ж міграція на Alpine.js — видалено ~130 рядків старого vanilla JS коду
- **includes/header.php**: додано Alpine.js (CDN) та CSS `[x-cloak]`
- **includes/footer.php**: зареєстровано Alpine-компонент `itemsForm` (inline)
- **modules/incoming.php, modules/orders.php**: додано серверну валідацію — рядки без товару або з нульовою кількістю відхиляються з помилкою

## [1.8.1] — 2026-06-06

### Додано
- **modules/incoming.php**: `action=reopen` — повернення confirmed/cancelled накладної до статусу draft для редагування
- **modules/incoming.php**: кнопки "Відкрити як чернетку", "Підтвердити", "Скасувати" в списку накладних
- **modules/incoming.php**: замінено `<select>` з товарами на пошуковий автокомпліт (як у замовленнях)
- **modules/incoming.php**: сума оплати тепер автоматично підставляється (залишок), з можливістю правки

### Змінено
- **api/search-products.php**: прибрано фільтр `status = 1`, тепер шукає всі товари (як у списку складу)
- **modules/incoming.php**: виправлено кнопку олівця в списку — вела на view замість edit

## [1.8.0] — 2026-06-01

### Додано
- **modules/orders.php**: колонки `ttn_number` та `delivery_status` в БД, форму створення/редагування, список і сторінку перегляду замовлення
- **modules/orders.php**: обробник зміни статусу доставки (`action=delivery_status`)
- **modules/stock.php**: імпорт початкових залишків з CSV (колонки: артикул/модель/назва, кількість, ціна)
- **modules/stock.php**: імпорт з Google Таблиці — парсинг формату з 2-рядковими заголовками (ID, ціна, кількість)
- **modules/stock.php**: масове видалення корекцій (чекбокси + "Видалити вибрані")

### Змінено
- **config.php, modules/stock.php, index.php, api/push-prices.php**: `adjustment`-рухи тепер враховуються в розрахунку залишків (`stock_qty`)
- **modules/stock.php**: сортування товарів у вкладці "Товари" — спочатку ті, що з залишком, потім з 0
- **modules/orders.php**: кнопка "Редагувати" доступна для всіх статусів замовлень (не тільки pending/approved)
- **modules/stock.php**: імпорт Google Таблиці — матчинг по ID (колонка 5/2 залежно від формату)

## [1.7.0] — 2026-06-01

### Додано
- **api/push-prices.php**: новий API-ендпоінт — перераховує всі ціни на основі поточних курсів + націнок і відправляє на сайт OpenCart
- **includes/opencart_api.php**: методи `updateProductPrice()` та `pushPrices()` у `OpenCartDbClient` — запис цін прямо в БД OpenCart
- **modules/stock.php**: кнопка "Застосувати курси → Сайт" у вкладці Ціни (для адміна)
- **Категорії товарів**: таблиці `erp_categories` + `erp_product_categories`, управління категоріями (`?action=categories`), фільтр за категорією на всіх вкладках Складу
- **modules/stock.php**: колонка "Категорія" у списках товарів (Товари, Ціни); вибір категорій у модалці редагування товару
- **modules/stock.php**: пошуковий автокомпліт для вибору товару в модалці нової корекції (замість величезного `<select>`)

### Змінено
- **includes/opencart_api.php**: синхронізація OC→ERP більше не перезаписує `price_purchase` — закупівельна ціна залишається недоторканою для коректної статистики
- **modules/stock.php**: виправлено виклик неіснуючої функції `calcMarkupPrice` → `calculatePrice` (помилка вкладки Ціни)
- **includes/footer.php**: додано глобальну функцію `escapeHtml()` та оновлено `editProduct()` для підтримки категорій
- **config.php**: додано таблиці `erp_categories` та `erp_product_categories`, допоміжні функції `getCategories()`, `getProductCategoryIds()`, `getCategoryFilter()`, `getCategoryName()`

## [1.4.0] — 2026-05-23

### Додано
- **api/push-prices.php**: новий API-ендпоінт — перераховує всі ціни на основі поточних курсів + націнок і відправляє на сайт OpenCart
- **includes/opencart_api.php**: методи `updateProductPrice()` та `pushPrices()` у `OpenCartDbClient` — запис цін прямо в БД OpenCart
- **modules/stock.php**: кнопка "Застосувати курси → Сайт" у вкладці Ціни (для адміна)

### Змінено
- **includes/opencart_api.php**: синхронізація OC→ERP більше не перезаписує `price_purchase` — закупівельна ціна залишається недоторканою для коректної статистики
- **modules/stock.php**: виправлено виклик неіснуючої функції `calcMarkupPrice` → `calculatePrice` (помилка вкладки Ціни)

## [1.3.0] — 2026-05-22

### Додано
- **modules/stock.php**: кнопка "Редагувати товар" (✏️) у списку товарів — модальне вікно для зміни назви, моделі, SKU та цін
- **modules/stock.php**: обробник `action=edit_product` для збереження змін товару
- **includes/footer.php**: JS-функція `editProduct()` для заповнення модалки редагування товару

### Змінено
- **includes/opencart_api.php**: синхронізація з OpenCart більше НЕ перезаписує ціни (`price_wholesale`, `price_semi_wholesale`, `price_retail`) — оновлюються лише назва, модель, SKU, фото, кількість, статус
- **includes/opencart_api.php**: видалено непотрібні запити до `oc_product_discount` після відділення цін від синхронізації
- **includes/opencart_api.php**: додано `uk-ua` до пріоритету мови синхронізації (було: uk→ru→en, стало: uk→uk-ua→ru→en)
- **modules/orders.php**: додано автопошук товару (по перших літерах) з випадаючим списком та автопідстановкою ціни в формі замовлення
- **modules/stock.php**: додано вкладку **Ціни** — об'єднано сторінку ціноутворення зі складом; перенесено відображення курсів, націнок, правил ціноутворення та модалок
- **modules/pricing.php**: видалено (тепер все в stock.php)
- **includes/header.php**: прибрано пункт меню "Ціни" — тепер це вкладка на сторінці складу

## [1.2.0] — 2026-05-21

### Додано
- **config.php**: колонка `price_purchase` (вхідна закупівельна ціна) у таблицю `erp_products`
- **modules/stock.php**: колонка "Закупівля" у списку товарів; окрема сторінка корекцій залишків (`?action=corrections`) зі списком, редагуванням (олівець) та видаленням (корзина)
- **modules/stock.php**: вибір товару зі списку (select) та поле "Собівартість" у модалці створення корекції
- **includes/footer.php**: JS-функція `editCorrection()` для редагування корекцій
- **includes/opencart_api.php**: поле `price_purchase` у синхронізації (0 за замовчуванням); окремий запит для знижок (`oc_product_discount`) замість GROUP_CONCAT LEFT JOIN
- **api/sync-products.php**: підтримка пошуку товару за кодом (`?code=`)
- **config.php, _helpers.php, includes/functions.php**: renderPagination тепер приймає необов'язковий 3-й параметр `$pageParam`
- **api/check-oc-db.php**: діагностика OpenCart — вивід таблиць `tblProduct`, `tabTovar`, `tblPrice`

### Виправлено
- **includes/opencart_api.php**: PDO LIMIT/OFFSET тепер біндиться як `PDO::PARAM_INT` (виправлено помилку синтаксису SQL)
- **includes/opencart_api.php**: ціни з `oc_product_discount` тепер визначаються у двох окремих запитах (без ламання `p.price` через GROUP BY) — працює bulk-синхронізація
- **includes/opencart_api.php**: видалено зайву закриваючу дужку `}` (синтаксична помилка)

## [1.1.0] — 2026-05-19

### Додано
- **modules/orders.php**: повноцінне створення/редагування замовлень (action=create, edit, save)
- **modules/orders.php**: реєстрація оплат за замовленнями (action=pay)
- **modules/orders.php**: зміна статусу з автоматичним списанням/поверненням товару зі складу (action=status)
- **modules/orders.php**: видалення замовлення адміністратором (action=delete)
- **modules/orders.php**: колонка "Оплата" у списку замовлень (борг/сплачено)
- **modules/orders.php**: кнопка "Нове замовлення" у списку
- **modules/orders.php**: поля Ім'я + Фамілія замість одного "Клієнт"
- **modules/orders.php**: спосіб доставки (самовивіз, кур'єр, Нова Пошта, Делівері, Укрпошта) + адреса доставки
- **modules/orders.php**: валідація телефону (pattern +380/0XXXXXXXXX); поле "Примітки" розширено до textarea
- **modules/orders.php**: додано поле "Дата" у формі; спрощено статус оплати (Оплачено/Не оплачено) у списку
- **modules/orders.php**: розділено адресу доставки — кур'єр (вулиця, будинок, квартира/офіс) / пошта (місто, відділення) + JS перемикання полів
- **config.php**: додано колонки `order_date`, `delivery_city`, `delivery_street`, `delivery_building`, `delivery_apartment`, `delivery_office`
- **config.php**: міграція БД — auto_increment для `erp_orders` та `erp_order_products`; поле `order_id` у `erp_payments`; метод `transfer` у `erp_payments.method`; поля `payment_method`, `delivery_method`, `delivery_address` у `erp_orders`

## [1.0.0] — 2026-05-18

### Виправлено
- **config.php**: BASE_URL тепер жорстко `/ERP` — виправлено подвоєння шляху (/modules/modules/) при навігації з модулів
- **modules/stock.php**: додано пропущений `<?php endforeach; ?>` — виправлено синтаксичну помилку PHP (Parse error: unexpected else)

### Додано
- **config.local.php**: окремий файл для даних БД (не відстежується git, не затирається)
- **config.php**: тепер підключає `config.local.php` якщо існує; додано таблиці `erp_payments`, `erp_cash_accounts`, `erp_transactions`
- **includes/header.php**: додано пункт меню "Фінанси"
- **modules/suppliers.php**: додано колонку "Борг (UAH)" у списку постачальників
- **modules/stock.php**: кнопка "Додати товар" на сторінці Складу (перенесено з pricing.php)
- **modules/incoming.php**: додано вибір валюти UAH; виправлено курс при UAH; додано редагування чернеток; додано оплати постачальнику (готівка/картка/ФОП/рахунок)
- **modules/finance.php**: новий модуль Фінанси — каси (Основна каса, Розрахунковий рахунок, ФОП), надходження та витрати з категоріями та методами оплат

### Видалено
- **modules/pricing.php**: прибрано створення товару (не логічно для сторінки цін)
