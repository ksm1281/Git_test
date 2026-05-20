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
    function syncOneProduct() {
        var id = document.getElementById('syncOneId');
        if (!id || !id.value || parseInt(id.value.replace(/\D/g, '')) < 1) { alert('Введіть ID товару'); return; }
        id.value = parseInt(id.value.replace(/\D/g, ''));
        var btn = event.target;
        btn.disabled = true;
        var orig = btn.innerHTML;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>...';

        fetch('<?php echo BASE_URL; ?>/api/sync-products.php?id=' + id.value)
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (d.error) {
                    alert('Помилка: ' + d.error);
                } else {
                    alert('OK! Синхронізовано: ' + d.name + ' (ID ' + d.product_id + ')');
                }
                loadSyncStatus();
            })
            .catch(function(e) {
                alert('Помилка: ' + e.message);
            })
            .finally(function() {
                btn.disabled = false;
                btn.innerHTML = orig;
            });
    }

    function syncProducts() {
        var btn = document.getElementById('syncBtn') || event.target;
        if (!btn) btn = document.querySelector('[onclick="syncProducts()"]');
        var orig = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Синхр...';

        fetch('<?php echo BASE_URL; ?>/api/sync-products.php')
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (d.error) {
                    alert('Помилка: ' + d.error);
                } else {
                    alert('OK! Синхронізовано ' + d.synced + ' з ' + d.total + ' товарів');
                }
                loadSyncStatus();
            })
            .catch(function(e) {
                alert('Помилка: ' + e.message);
            })
            .finally(function() {
                btn.disabled = false;
                btn.innerHTML = orig;
            });
    }

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
    </script>
</body>
</html>
