# Changelog

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
