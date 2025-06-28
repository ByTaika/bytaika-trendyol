<?php
if (!current_user_can('manage_woocommerce')) {
    wp_die('Bu işlemi yapma yetkiniz yok.');
}
?>

<div class="wrap">
    <h1>Trendyol Stok Güncelleme</h1>

    <button id="start-stock-update" class="button button-primary">Stok Güncellemeyi Başlat</button>
    <progress id="krn-stock-progress" style="width: 100%; height: 25px; display: none; margin-top: 15px;"></progress>

    <div id="krn-stock-status" style="margin-top: 20px;">
        <p id="krn-main-status" style="font-weight: bold;"></p>
        <div id="krn-updated-list" style="margin-top: 20px;">
            <h3>Güncellenen Ürünler</h3>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const button = document.getElementById('start-stock-update');
            const progress = document.getElementById('krn-stock-progress');
            const mainStatus = document.getElementById('krn-main-status');
            const updatedList = document.getElementById('krn-updated-list');

            let page = 0;
            const perPage = 50;
            let totalProducts = null;
            let updatedCount = 0;
            let checkedCount = 0;

            button.addEventListener('click', function () {
                button.disabled = true;
                progress.style.display = 'block';
                progress.value = 0;

                page = 0;
                updatedCount = 0;
                checkedCount = 0;
                totalProducts = null;

                mainStatus.textContent = "Stok güncelleme başlatıldı...";
                updatedList.innerHTML = '<h3>Güncellenen Ürünler</h3>';

                processPage();
            });

            function processPage() {
                const url = '<?php echo admin_url("admin-ajax.php"); ?>?action=krn_trendyol_stock_update_step&page=' + page;

                fetch(url)
                    .then(res => res.json())
                    .then(data => {
                        if (totalProducts === null && data.total_products) {
                            totalProducts = data.total_products;
                            progress.max = totalProducts;
                        }

                        checkedCount += perPage;
                        if (checkedCount > totalProducts) checkedCount = totalProducts;

                        if (Array.isArray(data.updated_products) && data.updated_products.length > 0) {
                            updatedCount += data.updated_products.length;

                            const items = data.updated_products.map(name => '✔️ ' + name).join('<br>');
                            const block = document.createElement('div');
                            block.style.marginBottom = '10px';
                            block.innerHTML = items;
                            updatedList.appendChild(block);
                        }

                        mainStatus.textContent = `${checkedCount} ürün kontrol edildi – ${updatedCount} toplam güncelleme yapıldı.`;
                        progress.value = checkedCount;

                        if (!data.has_more || checkedCount >= totalProducts) {
                            mainStatus.innerHTML += " <span style='color:green;'>✅ Stok güncelleme tamamlandı!</span>";
                            button.disabled = false;
                        } else {
                            page++;
                            setTimeout(processPage, 2000); // 2 saniye bekleyerek bir sonraki sayfayı işle
                        }
                    })
                    .catch(err => {
                        mainStatus.innerHTML += "<br><span style='color:red;'>Hata: " + err.message + "</span>";
                        button.disabled = false;
                    });
            }
        });
    </script>
</div>
