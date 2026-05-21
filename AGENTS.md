# ERP/CRM – Session Context

## Project
PHP ERP/CRM on shared hosting. Manages stock, orders, invoices, customers, finances, OpenCart sync.

## Current State (v1.2.0)

### OpenCart Sync (`includes/opencart_api.php`)
- **Class**: `OpenCartDbClient` (active) – direct DB connection to `kofenada_kk` DB with prefix `oc_`
- **Class**: `OpenCartApiClient` (dead code, not used via `sync-products.php`)
- **Entry**: `api/sync-products.php` – routes `?code=` → `syncProductByCode`, `?id=` → `syncProduct`, empty → `syncProducts`
- **`getProduct($id)`**: single query → product + separate query → discounts from `oc_product_discount` (LIMIT 2, order by quantity)
- **`syncProducts($pdo)`**: paginated (100), then bulk-fetch discounts `WHERE product_id IN (...)` → build `$discMap`
- **Price logic**: `$discounts[0]` → semi, `$discounts[1]` → whole, fallback `$priceRetail`
- **Sync writes**: `erp_products` (upsert) + `erp_sync_log`

### Stock Page (`modules/stock.php`)
- **Products list**: ID, Name, Model, SKU, Stock Qty, Opt, Dribny Opt, Rozdrib, **Zakupivlya**, Diyi
- **Corrections** (`?action=corrections`): full page with list, edit modal (pencil), delete (trash, confirm)
- **Adjust modal**: product select dropdown, qty (+/-), cost_price (foreign currency), notes
- **Create product**: name + model

### Database Schema
- `erp_products`: product_id (PK), model, sku, name, image, price_wholesale, price_semi_wholesale, price_retail, **price_purchase** (v1.2.0), quantity, status, date_synced, date_added, date_modified
- `erp_stock_moves`: move_id (PK), product_id, type (in/out/return_in/return_out/adjustment), quantity, reference_type, reference_id, cost_price, notes, user_id, date_added
- `erp_sync_log`: log_id (PK), type, status, records_synced, message, date_added

### Known Issues / Open Questions
- edit_pricing.php referenced from `modules/pricing.php:57` but file does not exist
- OpenCartApiClient is dead code but still maintained (should refactor/remove eventually)
- No UI for editing `price_purchase` directly on stock page (only from OCR sync = 0)

### Git
- Remote: `https://github.com/ksm1281/Git_test.git`
- Branch: `main`
- `config.local.php` in `.gitignore` (real DB creds)
- Commit convention: `v{major}.{minor}.{patch}: {description}`

### Files Changed in v1.2.0
- `config.php` – price_purchase column, ALTER TABLE, renderPagination $pageParam, APP_VERSION→1.2.0
- `modules/stock.php` – purchase price column, corrections page (list/edit/delete), product select in adjust modal
- `includes/opencart_api.php` – price_purchase in sync, fixed PDO LIMIT/OFFSET PARAM_INT, separate discount query, fixed extra brace
- `includes/footer.php` – editCorrection() JS function
- `_helpers.php` – renderPagination $pageParam
- `includes/functions.php` – renderPagination $pageParam
- `api/sync-products.php` – ?code= support (syncProductByCode)
- `api/check-oc-db.php` – diagnostic output for custom tables
- `CHANGELOG.md` – v1.2.0 entry
