
<?php
if (!current_user_can('manage_options')) return;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    update_option('krn_trendyol_api_key', sanitize_text_field($_POST['api_key']));
    update_option('krn_trendyol_api_secret', sanitize_text_field($_POST['api_secret']));
    update_option('krn_trendyol_refresh_token', sanitize_text_field($_POST['refresh_token']));
    update_option('krn_trendyol_auto_stock', isset($_POST['auto_stock']) ? 'yes' : 'no');
    update_option('krn_trendyol_stock_hour', sanitize_text_field($_POST['stock_hour'] ?? ''));
    echo '<div class="updated"><p>Ayarlar kaydedildi.</p></div>';
}

$api_key     = esc_attr(get_option('krn_trendyol_api_key'));
$api_secret  = esc_attr(get_option('krn_trendyol_api_secret'));
$refresh_token = esc_attr(get_option('krn_trendyol_refresh_token'));
$auto_stock  = get_option('krn_trendyol_auto_stock') === 'yes';
$stock_hour  = get_option('krn_trendyol_stock_hour');
?>

<div class="wrap">
    <h1>Trendyol API Ayarları (Yeni Sistem)</h1>
    <form method="post">
        <table class="form-table">
            <tr>
                <th>Client ID</th>
                <td><input type="text" name="api_key" value="<?= $api_key ?>" class="regular-text"></td>
            </tr>
            <tr>
                <th>Client Secret</th>
                <td><input type="text" name="api_secret" value="<?= $api_secret ?>" class="regular-text"></td>
            </tr>
            <tr>
                <th>Refresh Token</th>
                <td><input type="text" name="refresh_token" value="<?= $refresh_token ?>" class="regular-text"></td>
            </tr>
            <tr>
                <th>API Test</th>
                <td>
                    <button type="button" class="button" id="krn-test-api">Bağlantıyı Test Et</button>
                    <span id="krn-api-result" style="margin-left:10px;"></span>
                </td>
            </tr>
            <tr>
                <th scope="row">Otomatik Stok Güncelleme</th>
                <td>
                    <label><input type="checkbox" id="auto_stock" name="auto_stock" <?php checked($auto_stock); ?> /> Etkinleştir</label>
                </td>
            </tr>
            <tr id="stock_hour_row" style="display: <?= $auto_stock ? 'table-row' : 'none' ?>">
                <th scope="row">Güncelleme Saati</th>
                <td><input type="time" name="stock_hour" value="<?= esc_attr($stock_hour ?: '02:00') ?>" /></td>
            </tr>
        </table>
        <?php submit_button('Ayarları Kaydet'); ?>
    </form>
</div>

<script>
    document.getElementById('auto_stock').addEventListener('change', function () {
        document.getElementById('stock_hour_row').style.display = this.checked ? 'table-row' : 'none';
    });

    document.getElementById('krn-test-api').addEventListener('click', function () {
        const resultEl = document.getElementById('krn-api-result');
        resultEl.textContent = 'Test ediliyor...';
        fetch('<?= admin_url('admin-ajax.php?action=krn_test_api') ?>')
            .then(r => r.json())
            .then(d => {
                resultEl.textContent = d.success ? '✅ Başarılı bağlantı' : '❌ Başarısız: ' + d.message;
                resultEl.style.color = d.success ? 'green' : 'red';
            })
            .catch(() => {
                resultEl.textContent = '❌ Bağlantı hatası';
                resultEl.style.color = 'red';
            });
    });
</script>
