# Changelog

## [1.0.0] — 2026-05-18

### Виправлено
- **config.php**: BASE_URL тепер жорстко `/ERP` — виправлено подвоєння шляху (/modules/modules/) при навігації з модулів
- **modules/stock.php**: додано пропущений `<?php endforeach; ?>` — виправлено синтаксичну помилку PHP (Parse error: unexpected else)

### Додано
- **modules/pricing.php**: кнопка "Додати товар" з формою (назва, артикул, ціна) для ручного створення товарів
