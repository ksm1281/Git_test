    <script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('itemsForm', (config = {}) => ({
            items: [],
            searchResults: [],
            searchOpenIdx: -1,

            init() {
                const data = this.$el.dataset.items;
                this.items = data ? JSON.parse(data) : [];
                if (this.items.length === 0) {
                    this.items.push(this.emptyItem());
                }
            },

            emptyItem() {
                return { product_id: 0, name: '', qty: 1, price: 0, prices: {} };
            },

            get grandTotal() {
                return this.items.reduce((s, i) => s + (parseFloat(i.qty) || 0) * (parseFloat(i.price) || 0), 0);
            },

            addItem() {
                this.items.push(this.emptyItem());
                this.$nextTick(() => {
                    const rows = this.$el.querySelectorAll('[data-idx]');
                    const last = rows[rows.length - 1];
                    if (last) last.querySelector('.product-autocomplete')?.focus();
                });
            },

            removeItem(idx) {
                if (this.items.length > 1) this.items.splice(idx, 1);
            },

            async searchProduct(idx, q) {
                this.searchOpenIdx = idx;
                if (!q.trim()) { this.searchResults = []; return; }
                try {
                    const url = (config.searchUrl || '/api/search-products.php') + '?q=' + encodeURIComponent(q);
                    const r = await fetch(url);
                    const data = await r.json();
                    this.searchResults = data || [];
                    this.$nextTick(() => this.positionDropdown(idx));
                } catch (e) {
                    this.searchResults = [];
                }
            },

            positionDropdown(idx) {
                const row = this.$el.querySelector(`[data-idx="${idx}"]`);
                if (!row) return;
                const input = row.querySelector('.product-autocomplete');
                const dropdown = row.querySelector('.product-dropdown');
                if (!input || !dropdown) return;
                const rect = input.getBoundingClientRect();
                dropdown.style.position = 'fixed';
                dropdown.style.top = rect.bottom + 'px';
                dropdown.style.left = rect.left + 'px';
                dropdown.style.width = rect.width + 'px';
                dropdown.style.maxHeight = Math.min(200, window.innerHeight - rect.bottom - 20) + 'px';
            },

            selectProduct(product) {
                const idx = this.searchOpenIdx;
                if (idx < 0 || idx >= this.items.length) return;
                const item = this.items[idx];
                item.product_id = parseInt(product.product_id);
                item.name = product.name;
                item.prices = {
                    retail: parseFloat(product.price_retail) || 0,
                    semi: parseFloat(product.price_semi_wholesale) || 0,
                    wholesale: parseFloat(product.price_wholesale) || 0,
                };
                this.searchResults = [];
                this.searchOpenIdx = -1;
            },

            setPrice(idx, type) {
                const item = this.items[idx];
                if (item && item.prices && item.prices[type] != null) {
                    item.price = item.prices[type];
                }
            },
        }));

        Alpine.data('orderForm', () => ({
            items: [],
            searchResults: [],
            searchOpenIdx: -1,

            _searchUrl: '',
            _searchCustomersUrl: '',
            _npApiUrl: '',

            deliveryMethod: '',
            deliveryCost: 0,
            customerQuery: '',
            customerResults: [],
            customerOpen: false,

            npCityQuery: '',
            npCityHidden: '',
            npCityRef: '',
            npCityResults: [],
            npCityOpen: false,

            npWarehouseQuery: '',
            npWarehouseHidden: '',
            npWarehouseRef: '',
            npWarehouseResults: [],
            npWarehouseOpen: false,

            telephone: '',

            init() {
                const d = this.$el.dataset;
                this._searchUrl = d.searchUrl || '/api/search-products.php';
                this._searchCustomersUrl = d.searchCustomersUrl || '/api/search-customers.php';
                this._npApiUrl = d.npApiUrl || '/api/nova-poshta.php';
                this.deliveryMethod = d.deliveryMethod || '';
                this.customerQuery = d.customerName || '';
                this.npCityQuery = d.npCity || '';
                this.npCityHidden = d.npCity || '';
                this.npCityRef = d.npCityRef || '';
                this.npWarehouseQuery = d.npWarehouse || '';
                this.npWarehouseHidden = d.npWarehouse || '';
                this.npWarehouseRef = d.npWarehouseRef || '';
                this.telephone = d.telephone || '';
                this.deliveryCost = parseFloat(d.deliveryCost) || 0;

                const data = d.items;
                this.items = data ? JSON.parse(data) : [];
                if (this.items.length === 0) {
                    this.items.push(this.emptyItem());
                }
            },

            emptyItem() {
                return { product_id: 0, name: '', qty: 1, price: 0, prices: {} };
            },

            get grandTotal() {
                return this.items.reduce((s, i) => s + (parseFloat(i.qty) || 0) * (parseFloat(i.price) || 0), 0) + (parseFloat(this.deliveryCost) || 0);
            },

            addItem() {
                this.items.push(this.emptyItem());
                this.$nextTick(() => {
                    const rows = this.$el.querySelectorAll('[data-idx]');
                    const last = rows[rows.length - 1];
                    if (last) last.querySelector('.product-autocomplete')?.focus();
                });
            },

            removeItem(idx) {
                if (this.items.length > 1) this.items.splice(idx, 1);
            },

            async searchProduct(idx, q) {
                this.searchOpenIdx = idx;
                if (!q.trim()) { this.searchResults = []; return; }
                try {
                    const url = (this._searchUrl || '/api/search-products.php') + '?q=' + encodeURIComponent(q);
                    const r = await fetch(url);
                    const data = await r.json();
                    this.searchResults = data || [];
                    this.$nextTick(() => this.positionDropdown(idx));
                } catch (e) {
                    this.searchResults = [];
                }
            },

            positionDropdown(idx) {
                const row = this.$el.querySelector(`[data-idx="${idx}"]`);
                if (!row) return;
                const input = row.querySelector('.product-autocomplete');
                const dropdown = row.querySelector('.product-dropdown');
                if (!input || !dropdown) return;
                const rect = input.getBoundingClientRect();
                dropdown.style.position = 'fixed';
                dropdown.style.top = rect.bottom + 'px';
                dropdown.style.left = rect.left + 'px';
                dropdown.style.width = rect.width + 'px';
                dropdown.style.maxHeight = Math.min(200, window.innerHeight - rect.bottom - 20) + 'px';
            },

            selectProduct(product) {
                const idx = this.searchOpenIdx;
                if (idx < 0 || idx >= this.items.length) return;
                const item = this.items[idx];
                item.product_id = parseInt(product.product_id);
                item.name = product.name;
                item.costPrice = parseFloat(product.cost_price) || 0;
                item.prices = {
                    retail: parseFloat(product.price_retail) || 0,
                    semi: parseFloat(product.price_semi_wholesale) || 0,
                    wholesale: parseFloat(product.price_wholesale) || 0,
                };
                item.price = item.prices.retail || item.prices.semi || item.prices.wholesale || 0;
                this.searchResults = [];
                this.searchOpenIdx = -1;
            },

            setPrice(idx, type) {
                const item = this.items[idx];
                if (item && item.prices && item.prices[type] != null) {
                    item.price = item.prices[type];
                }
            },

            async searchCustomer() {
                const q = this.customerQuery.trim();
                if (q.length < 1) { this.customerResults = []; this.customerOpen = false; return; }
                try {
                    const r = await fetch(this._searchCustomersUrl + '?q=' + encodeURIComponent(q));
                    if (!r.ok) throw new Error('HTTP ' + r.status + ' ' + r.statusText);
                    const data = await r.json();
                    this.customerResults = data && !data.error ? data : [];
                    this.customerOpen = this.customerResults.length > 0;
                } catch (e) {
                    console.error('Customer search error:', e);
                    this.customerResults = [{ _error: 'Помилка: ' + e.message }];
                    this.customerOpen = true;
                }
            },

            selectCustomer(c) {
                this.customerQuery = ((c.firstname || '') + ' ' + (c.lastname || '')).trim();
                if (this.$refs.orderFirstname) this.$refs.orderFirstname.value = c.firstname || '';
                if (this.$refs.orderLastname) this.$refs.orderLastname.value = c.lastname || '';
                if (this.$refs.orderEmail) this.$refs.orderEmail.value = c.email || '';
                this.telephone = c.telephone || '';
                this.customerOpen = false;
            },

            closeCustomer() {
                setTimeout(() => { this.customerOpen = false; }, 200);
            },

            async searchNpCity() {
                const q = this.npCityQuery.trim();
                if (q.length < 1) { this.npCityResults = []; this.npCityOpen = false; return; }
                try {
                    const r = await fetch(this._npApiUrl + '?action=cities&q=' + encodeURIComponent(q));
                    const data = await r.json();
                    if (data.error) {
                        this.npCityResults = [{ _error: data.error }];
                        this.npCityOpen = true;
                        return;
                    }
                    this.npCityResults = data || [];
                    this.npCityOpen = this.npCityResults.length > 0;
                } catch (e) {
                    console.error('NP city error:', e);
                    this.npCityResults = [];
                    this.npCityOpen = false;
                }
            },

            selectNpCity(city) {
                this.npCityQuery = city.name;
                this.npCityHidden = city.name;
                this.npCityRef = city.ref;
                this.npCityOpen = false;
                this.$nextTick(() => {
                    const hidden = this.$el.querySelector('[name="np_warehouse"]');
                    if (hidden) {
                        const wrapper = hidden.closest('.position-relative');
                        const inp = wrapper ? wrapper.querySelector('input[type="text"]') : null;
                        if (inp) inp.focus();
                    }
                });
            },

            closeNpCity() {
                setTimeout(() => { this.npCityOpen = false; }, 200);
            },

            async searchNpWarehouse() {
                const q = this.npWarehouseQuery.trim();
                if (!this.npCityRef || q.length < 1) {
                    this.npWarehouseResults = [];
                    this.npWarehouseOpen = false;
                    return;
                }
                try {
                    const r = await fetch(this._npApiUrl + '?action=warehouses&city_ref='
                        + encodeURIComponent(this.npCityRef) + '&q=' + encodeURIComponent(q));
                    const data = await r.json();
                    if (data.error) {
                        this.npWarehouseResults = [{ _error: data.error }];
                        this.npWarehouseOpen = true;
                        return;
                    }
                    this.npWarehouseResults = data || [];
                    this.npWarehouseOpen = this.npWarehouseResults.length > 0;
                } catch (e) {
                    console.error('NP warehouse error:', e);
                    this.npWarehouseResults = [];
                    this.npWarehouseOpen = false;
                }
            },

                selectNpWarehouse(wh) {
                    this.npWarehouseQuery = wh.name;
                    this.npWarehouseHidden = wh.name;
                    this.npWarehouseRef = wh.ref || '';
                    this.npWarehouseOpen = false;
                },

            closeNpWarehouse() {
                setTimeout(() => { this.npWarehouseOpen = false; }, 200);
            },

            get messengerTelegram() {
                return this.telephone ? 'tg://resolve?phone=' + this._normalizePhone(this.telephone) : '#';
            },

            get messengerViber() {
                return this.telephone ? 'viber://chat?number=' + this._normalizePhone(this.telephone) : '#';
            },

            get hasPhone() {
                return !!this.telephone;
            },

            _normalizePhone(phone) {
                const digits = phone.replace(/\D/g, '');
                if (digits.length === 10 && digits.startsWith('0')) return '38' + digits;
                if (digits.length === 9) return '380' + digits;
                if (digits.startsWith('380')) return digits;
                return digits;
            },

            submitOrder() {
                const belowCost = this.items.some(item => item.costPrice > 0 && item.price < item.costPrice);
                if (belowCost && !confirm('Деякі товари продаються нижче собівартості. Продовжити?')) return;
                this.$el.submit();
            }
        }));

        Alpine.data('stockPage', (config = {}) => ({
            adjustProducts: config.products || [],

            editCategory: { id: 0, name: '', sort_order: 0 },
            editProduct: { product_id: 0, name: '', model: '', sku: '', category_ids: [], price_wholesale: '', price_semi_wholesale: '', price_retail: '', price_purchase: '' },
            editCorrection: { move_id: 0, product_id: 0, quantity: 0, cost_price: '', notes: '' },
            editPricing: { product_id: 0, name: '', mw: 0, ms: 0, mr: 0, use_custom: false, cpw: 0, cps: 0, cpr: 0, price_eur: 0 },

            adjustQuery: '',
            adjustResults: [],
            adjustSelectedId: 0,
            syncOneOpen: false,

            syncing: false,
            syncingOne: false,
            syncingCats: false,
            pushingPrices: false,
            selectAllPricing: false,
            selectAllCorr: false,

            openCategoryEdit(id, name, sort) {
                this.editCategory = { id: parseInt(id) || 0, name: name || '', sort_order: parseInt(sort) || 0 };
                bootstrap.Modal.getOrCreateInstance(this.$refs.categoryModal).show();
            },
            openProductEdit(data) {
                this.editProduct = {
                    product_id: parseInt(data.product_id) || 0,
                    name: data.name || '',
                    model: data.model || '',
                    sku: data.sku || '',
                    category_ids: Array.from(data.category_ids || []),
                    price_wholesale: data.price_wholesale ?? '',
                    price_semi_wholesale: data.price_semi_wholesale ?? '',
                    price_retail: data.price_retail ?? '',
                    price_purchase: data.price_purchase ?? ''
                };
                bootstrap.Modal.getOrCreateInstance(this.$refs.editProductModal).show();
            },
            openCorrectionEdit(data) {
                this.editCorrection = {
                    move_id: parseInt(data.move_id) || 0,
                    product_id: parseInt(data.product_id) || 0,
                    quantity: parseFloat(data.quantity) || 0,
                    cost_price: data.cost_price || '',
                    notes: data.notes || ''
                };
                bootstrap.Modal.getOrCreateInstance(this.$refs.editCorrectionModal).show();
            },
            openPricingEdit(btn) {
                this.editPricing = {
                    product_id: parseInt(btn.dataset.productId) || 0,
                    name: btn.dataset.name || '',
                    mw: parseFloat(btn.dataset.mw) || 0,
                    ms: parseFloat(btn.dataset.ms) || 0,
                    mr: parseFloat(btn.dataset.mr) || 0,
                    use_custom: btn.dataset.useCustom === '1',
                    cpw: parseFloat(btn.dataset.cpw) || 0,
                    cps: parseFloat(btn.dataset.cps) || 0,
                    cpr: parseFloat(btn.dataset.cpr) || 0,
                    price_eur: parseFloat(btn.dataset.priceEur) || 0
                };
                bootstrap.Modal.getOrCreateInstance(this.$refs.pricingModal).show();
            },
            openAdjust(productId) {
                this.adjustSelectedId = parseInt(productId) || 0;
                this.adjustQuery = '';
                this.adjustResults = [];
                if (productId) {
                    const p = this.adjustProducts.find(x => x.id === parseInt(productId));
                    if (p) this.adjustQuery = p.name + (p.model ? ' (' + p.model + ')' : '');
                }
                bootstrap.Modal.getOrCreateInstance(this.$refs.adjustModal).show();
            },
            searchAdjust() {
                const q = this.adjustQuery.toLowerCase().trim();
                if (!q) { this.adjustResults = []; return; }
                const filtered = this.adjustProducts.filter(p =>
                    (p.name && p.name.toLowerCase().includes(q)) ||
                    (p.model && p.model.toLowerCase().includes(q)) ||
                    (p.sku && p.sku.toLowerCase().includes(q))
                ).slice(0, 20);
                if (!filtered.some(p => p.id === this.adjustSelectedId)) {
                    this.adjustSelectedId = 0;
                }
                this.adjustResults = filtered;
            },
            selectAdjust(p) {
                this.adjustSelectedId = p.id;
                this.adjustQuery = p.name + (p.model ? ' (' + p.model + ')' : '');
                this.adjustResults = [];
            },
            async saveAdjust(e) {
                const form = e.target;
                const formData = new FormData(form);
                formData.set('ajax', '1');
                try {
                    const r = await fetch(form.action, { method: 'POST', body: formData });
                    const d = await r.json();
                    if (d.error) { alert(d.error); return; }
                    bootstrap.Modal.getInstance(this.$refs.adjustModal).hide();
                    location.reload();
                } catch (err) { alert('Помилка: ' + err.message); }
            },
            async saveCorrection(e) {
                const form = e.target;
                const formData = new FormData(form);
                formData.set('ajax', '1');
                try {
                    const r = await fetch(form.action, { method: 'POST', body: formData });
                    const d = await r.json();
                    if (d.error) { alert(d.error); return; }
                    bootstrap.Modal.getInstance(this.$refs.editCorrectionModal).hide();
                    location.reload();
                } catch (err) { alert('Помилка: ' + err.message); }
            },
            toggleSyncOneWrap() {
                this.syncOneOpen = !this.syncOneOpen;
            },
            toggleAllPricing(checked) {
                this.selectAllPricing = checked;
                document.querySelectorAll('.pricing-checkbox').forEach(cb => cb.checked = checked);
            },
            toggleAllCorr(checked) {
                this.selectAllCorr = checked;
                document.querySelectorAll('.corr-check').forEach(cb => cb.checked = checked);
            },

            toggleCategory(catId) {
                catId = parseInt(catId);
                const i = this.editProduct.category_ids.indexOf(catId);
                if (i >= 0) this.editProduct.category_ids.splice(i, 1);
                else this.editProduct.category_ids.push(catId);
            },
            async syncProducts() {
                if (this.syncing) return;
                this.syncing = true;
                try {
                    const r = await fetch(config.syncProductsUrl || '/api/sync-products.php');
                    const d = await r.json();
                    if (d.error) alert('Помилка: ' + d.error);
                    else { alert('OK! Синхронізовано ' + d.synced + ' з ' + d.total + ' товарів'); if (typeof loadSyncStatus === 'function') loadSyncStatus(); }
                } catch (e) { alert('Помилка: ' + e.message); }
                finally { this.syncing = false; }
            },
            async syncOneProduct() {
                if (this.syncingOne) return;
                const id = (this.$refs.syncOneId?.value || '').trim();
                if (!id) { alert('Введіть ID або код товару'); return; }
                this.syncingOne = true;
                try {
                    const r = await fetch((config.syncProductsUrl || '/api/sync-products.php') + '?code=' + encodeURIComponent(id));
                    const d = await r.json();
                    if (d.error) alert('Помилка: ' + d.error);
                    else { alert('OK! Синхронізовано: ' + d.name + ' (ID ' + d.product_id + ')'); if (typeof loadSyncStatus === 'function') loadSyncStatus(); }
                } catch (e) { alert('Помилка: ' + e.message); }
                finally { this.syncingOne = false; }
            },
            async syncCategories() {
                if (this.syncingCats) return;
                this.syncingCats = true;
                try {
                    const r = await fetch(config.syncCategoriesUrl || '/api/sync-categories.php');
                    const d = await r.json();
                    if (d.error) alert('Помилка: ' + d.error);
                    else { alert('OK! Синхронізовано ' + d.synced + ' категорій'); location.reload(); }
                } catch (e) { alert('Помилка: ' + e.message); }
                finally { this.syncingCats = false; }
            },
            async pushPrices() {
                if (this.pushingPrices) return;
                this.pushingPrices = true;
                const checked = Array.from(document.querySelectorAll('.pricing-checkbox:checked')).map(cb => parseInt(cb.value));
                const url = (config.pushPricesUrl || '/api/push-prices.php') + (checked.length > 0 ? '?selected=1' : '');
                const options = { method: 'POST', headers: { 'Content-Type': 'application/json' } };
                if (checked.length > 0) options.body = JSON.stringify({ product_ids: checked });
                try {
                    const r = await fetch(url, options);
                    const d = await r.json();
                    if (d.error) { alert('Помилка: ' + d.error); }
                    else {
                        let msg = '✅ Оновлено в ERP: ' + d.erp_updated + ' товарів\n✅ Відправлено на сайт: ' + d.updated + ' товарів';
                        if (d.errors && d.errors.length) msg += '\n⚠️ Помилки: ' + d.errors.slice(0, 3).join(', ');
                        msg += '\n\nКурси: USD ' + d.rates_used.USD + ', EUR ' + d.rates_used.EUR;
                        alert(msg);
                    }
                } catch (e) { alert('Помилка запиту: ' + e.message); }
                finally { this.pushingPrices = false; }
            }
        }));

        Alpine.data('paymentEdit', () => ({
            payment: { id: 0, amount: '', method: 'cash', date: '', notes: '' },

            openEdit(btn) {
                this.payment = {
                    id: parseInt(btn.dataset.id) || 0,
                    amount: btn.dataset.amount || '',
                    method: btn.dataset.method || 'cash',
                    date: btn.dataset.date || '',
                    notes: btn.dataset.notes || '',
                };
                new bootstrap.Modal(this.$refs.modal).show();
            }
        }));

        Alpine.data('analyticsPage', () => ({
            period: '',
            year: '',

            init() {
                this.period = this.$el.dataset.period || 'month';
                this.year = this.$el.dataset.year || '';

                const el = document.getElementById('chartData');
                if (!el) return;
                const data = JSON.parse(el.textContent);
                if (!data) return;

                new Chart(document.getElementById('analyticsChart'), {
                    type: 'bar',
                    data: {
                        labels: data.labels,
                        datasets: [{
                            label: 'Замовлення',
                            data: data.ordersData,
                            backgroundColor: 'rgba(13, 110, 253, 0.5)',
                            borderColor: 'rgba(13, 110, 253, 1)',
                            borderWidth: 1,
                            yAxisID: 'y'
                        }, {
                            label: 'Дохід (UAH)',
                            data: data.revenueData,
                            backgroundColor: 'rgba(25, 135, 84, 0.3)',
                            borderColor: 'rgba(25, 135, 84, 1)',
                            borderWidth: 2,
                            type: 'line',
                            yAxisID: 'y1'
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: { legend: { position: 'top' } },
                        scales: {
                            y: { beginAtZero: true, position: 'left', title: { display: true, text: 'Замовлення' } },
                            y1: { beginAtZero: true, position: 'right', title: { display: true, text: 'Дохід (UAH)' }, grid: { drawOnChartArea: false } }
                        }
                    }
                });

                new Chart(document.getElementById('statusPieChart'), {
                    type: 'doughnut',
                    data: {
                        labels: data.statusLabels,
                        datasets: [{
                            data: data.statusData,
                            backgroundColor: ['#0d6efd', '#198754', '#ffc107', '#dc3545', '#6c757d', '#0dcaf0']
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: { legend: { position: 'bottom' } }
                    }
                });
            },

            submitForm() {
                this.$el.querySelector('form').submit();
            }
        }));

        Alpine.data('settingsEdit', () => ({
            edit: {},

            init() {
                this.edit = this.$el.dataset.defaults ? JSON.parse(this.$el.dataset.defaults) : {};
            },

            openEdit(btn) {
                const d = btn.dataset;
                this.edit = {};
                for (const key in d) {
                    let val = d[key];
                    if (key === 'id' || key === 'sort' || key === 'sortOrder') val = parseInt(val) || 0;
                    else if (key === 'balance') val = parseFloat(val) || 0;
                    else if (key === 'status') val = val !== '0' && val !== 'false';
                    this.edit[key] = val;
                }
                new bootstrap.Modal(this.$refs.modal).show();
            },

            openNew() {
                this.edit = this.$el.dataset.defaults ? JSON.parse(this.$el.dataset.defaults) : {};
                new bootstrap.Modal(this.$refs.modal).show();
            }
        }));

        Alpine.data('npSenderSettings', () => ({
            senderCityQuery: '',
            senderCityRef: '',
            senderCityName: '',
            senderCityResults: [],
            senderCityOpen: false,
            _npApiUrl: '/api/nova-poshta.php',

            senderWarehouseQuery: '',
            senderWarehouseRef: '',
            senderWarehouseName: '',
            senderWarehouseResults: [],
            senderWarehouseOpen: false,
            senderWarehouseLoaded: false,

            init() {
                const d = this.$el.dataset;
                this._npApiUrl = d.npApiUrl || '/api/nova-poshta.php';
                this.senderCityRef = d.senderCityRef || '';
                this.senderWarehouseRef = d.senderWarehouseRef || '';
                this.senderWarehouseLoaded = !!this.senderWarehouseRef;
            },

            async searchSenderCity() {
                const q = this.senderCityQuery.trim();
                if (q.length < 1) { this.senderCityResults = []; this.senderCityOpen = false; return; }
                try {
                    const r = await fetch(this._npApiUrl + '?action=cities&q=' + encodeURIComponent(q));
                    const data = await r.json();
                    if (data.error) {
                        this.senderCityResults = [{ _error: data.error }];
                        this.senderCityOpen = true;
                        return;
                    }
                    this.senderCityResults = data || [];
                    this.senderCityOpen = this.senderCityResults.length > 0;
                } catch (e) {
                    this.senderCityResults = [];
                    this.senderCityOpen = false;
                }
            },

            selectSenderCity(city) {
                this.senderCityQuery = city.name;
                this.senderCityName = city.name;
                this.senderCityRef = city.ref;
                this.senderCityOpen = false;
            },

            closeSenderCity() {
                setTimeout(() => { this.senderCityOpen = false; }, 200);
            },

            async searchSenderWarehouse() {
                const q = this.senderWarehouseQuery.trim();
                if (!this.senderCityRef) {
                    this.senderWarehouseResults = [{ _error: 'Спочатку оберіть місто' }];
                    this.senderWarehouseOpen = true;
                    return;
                }
                if (q.length < 1) { this.senderWarehouseResults = []; this.senderWarehouseOpen = false; return; }
                try {
                    const r = await fetch(this._npApiUrl + '?action=warehouses&city_ref='
                        + encodeURIComponent(this.senderCityRef) + '&q=' + encodeURIComponent(q));
                    const data = await r.json();
                    if (data.error) {
                        this.senderWarehouseResults = [{ _error: data.error }];
                        this.senderWarehouseOpen = true;
                        return;
                    }
                    this.senderWarehouseResults = data || [];
                    this.senderWarehouseOpen = this.senderWarehouseResults.length > 0;
                } catch (e) {
                    this.senderWarehouseResults = [];
                    this.senderWarehouseOpen = false;
                }
            },

            selectSenderWarehouse(wh) {
                this.senderWarehouseQuery = wh.name;
                this.senderWarehouseName = wh.name;
                this.senderWarehouseRef = wh.ref || '';
                this.senderWarehouseLoaded = true;
                this.senderWarehouseOpen = false;
            },

            closeSenderWarehouse() {
                setTimeout(() => { this.senderWarehouseOpen = false; }, 200);
            },
        }));

        Alpine.data('paymentManager', () => ({
            filterType: '',
            addType: 'supplier',
            addSupplierId: '',
            editPayment: { id: 0, type: 'supplier', supplierId: '', invoiceId: '', orderId: '', amount: '', method: 'cash', date: '', notes: '' },

            init() {
                this.filterType = this.$el.dataset.filterType || '';
            },

            submitFilter() {
                this.$nextTick(() => this.$el.querySelector('form').submit());
            },

            filterInvoices(selectEl) {
                const val = selectEl.value;
                const invoiceSel = selectEl.closest('.modal-body').querySelector('[data-invoice-select]');
                if (!invoiceSel) return;
                for (let i = 1; i < invoiceSel.options.length; i++) {
                    invoiceSel.options[i].style.display = invoiceSel.options[i].dataset.supplier === val || !val ? '' : 'none';
                }
                if (invoiceSel.selectedIndex > 0 && invoiceSel.options[invoiceSel.selectedIndex].style.display === 'none') {
                    invoiceSel.value = '';
                }
            },

            openEdit(btn) {
                const d = btn.dataset;
                const isSupplier = d.type !== 'customer';
                this.editPayment = {
                    id: parseInt(d.id) || 0,
                    type: d.type || 'supplier',
                    supplierId: d.supplier || '',
                    invoiceId: d.invoice || '',
                    orderId: d.order || '',
                    amount: d.amount || '',
                    method: d.method || 'cash',
                    date: d.date || '',
                    notes: d.notes || '',
                };
                this.$nextTick(() => {
                    const sel = this.$refs.editModal.querySelector('[data-invoice-select]');
                    if (sel) this.filterInvoices(sel);
                    if (isSupplier && this.editPayment.invoiceId) {
                        const invSelect = this.$refs.editModal.querySelector('[data-invoice-select]');
                        if (invSelect) invSelect.value = this.editPayment.invoiceId;
                    }
                });
                new bootstrap.Modal(this.$refs.editModal).show();
            }
        }));
    });
    </script>
    </div>

    <footer class="bg-dark text-light py-3 mt-5">
        <div class="container-fluid">
            <div class="row">
                <div class="col">
                    <small><?php echo APP_NAME; ?> v<?php echo APP_VERSION; ?> &copy; <?php echo date('Y'); ?></small>
                </div>
                <div class="col text-end">
                    <small id="sync-status"></small>
                </div>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="<?php echo BASE_URL; ?>/assets/js/app.js"></script>
    <script>
    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    document.addEventListener('change', function(e) {
        if (e.target.classList.contains('category-filter')) {
            var url = new URL(window.location.href);
            if (e.target.value) {
                url.searchParams.set('category_id', e.target.value);
            } else {
                url.searchParams.delete('category_id');
            }
            url.searchParams.delete('pricing_page');
            url.searchParams.delete('page');
            window.location.href = url.toString();
        }
    });

    function loadSyncStatus() {
        var el = document.getElementById('sync-status');
        if (!el) return;
        fetch('<?php echo BASE_URL; ?>/api/sync-status.php')
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (d.has_sync) {
                    var icon = d.status === 'success' ? '✅' : (d.status === 'partial' ? '⚠️' : '❌');
                    el.innerHTML = icon + ' OC: ' + d.records_synced + ' товарів (' + d.date_added + ')';
                } else {
                    el.innerHTML = 'ℹ️ OC: ще не синхронізовано';
                }
            })
            .catch(function() {
                el.innerHTML = '';
            });
    }
    document.addEventListener('DOMContentLoaded', loadSyncStatus);

    (function() {
        var storageKey = 'erp_col_widths';
        var saved = {};
        try { saved = JSON.parse(localStorage.getItem(storageKey) || '{}'); } catch(e) {}

        function lockAllCols(table) {
            var heads = table.querySelectorAll('th');
            heads.forEach(function(th) {
                if (!th.style.width) {
                    th.style.width = th.offsetWidth + 'px';
                }
            });
            table.style.tableLayout = 'fixed';
        }

        document.querySelectorAll('table.table-product').forEach(function(table) {
            var id = table.id || 'tbl_' + Math.random().toString(36).slice(2, 6);
            if (!table.id) table.id = id;

            var savedCols = saved[id];
            if (savedCols) {
                table.style.tableLayout = 'fixed';
                var heads = table.querySelectorAll('th');
                heads.forEach(function(th, i) {
                    if (savedCols[i]) th.style.width = savedCols[i] + 'px';
                });
            }

            var heads = table.querySelectorAll('th');
            heads.forEach(function(th, i) {
                var handle = document.createElement('div');
                handle.style.cssText = 'position:absolute;right:0;top:0;bottom:0;width:5px;cursor:col-resize;z-index:1;';
                th.style.position = 'relative';
                th.appendChild(handle);

                var startX, startW;
                handle.addEventListener('mousedown', function(e) {
                    e.preventDefault();
                    lockAllCols(table);
                    startX = e.clientX;
                    startW = th.offsetWidth;
                    document.body.style.cursor = 'col-resize';
                    document.body.style.userSelect = 'none';

                    var onMove = function(ev) {
                        var diff = ev.clientX - startX;
                        var newW = Math.max(30, startW + diff);
                        th.style.width = newW + 'px';
                    };
                    var onUp = function() {
                        document.body.style.cursor = '';
                        document.body.style.userSelect = '';
                        var widths = {};
                        table.querySelectorAll('th').forEach(function(h, idx) {
                            widths[idx] = h.offsetWidth;
                        });
                        saved[id] = widths;
                        try { localStorage.setItem(storageKey, JSON.stringify(saved)); } catch(e) {}
                        document.removeEventListener('mousemove', onMove);
                        document.removeEventListener('mouseup', onUp);
                    };
                    document.addEventListener('mousemove', onMove);
                    document.addEventListener('mouseup', onUp);
                });
            });
        });
    })();
    </script>
</body>
</html>
