<?php
if (!current_user_can('manage_options')) return;
?>

<div class="wrap">
    <h1>KRN - Otomatik Ürün Aktarımı</h1>
    <p>Sistem, butona bastığınızda 50’şer 50’şer ürünleri arka arkaya yüklemeye başlayacaktır. Lütfen sayfayı kapatmayın.</p>

    <button id="start-import" class="button button-primary">Ürün Aktarımını Başlat</button>
    <progress id="krn-import-progress" max="100" value="0" style="width: 100%; height: 25px; display:none; margin-top: 15px;"></progress>
    <div id="krn-import-status" style="margin-top: 20px;">
        <p id="krn-import-main-status" style="font-weight:bold;"></p>
        <div id="krn-import-added-list" style="margin-top: 20px;">
            <h3>Eklenen Ürünler</h3>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const button = document.getElementById('start-import');
            const progress = document.getElementById('krn-import-progress');
            const mainStatus = document.getElementById('krn-import-main-status');
            const addedList = document.getElementById('krn-import-added-list');

            let page = 0;
            const perPage = 50;
            let totalProducts = null;
            let addedCount = 0;
            let processedCount = 0;

            button.addEventListener('click', function () {
                button.disabled = true;
                progress.style.display = 'block';
                progress.value = 0;

                page = 0;
                addedCount = 0;
                processedCount = 0;
                totalProducts = null;

                mainStatus.textContent = "Ürün aktarımı başlatıldı...";
                addedList.innerHTML = '<h3>Eklenen Ürünler</h3>';

                processPage();
            });

            function processPage() {
                const url = '<?php echo admin_url("admin-ajax.php"); ?>?action=krn_trendyol_ajax_import_products&page=' + page;

                fetch(url)
                    .then(res => res.json())
                    .then(data => {
                        if (totalProducts === null && data.total_products) {
                            totalProducts = data.total_products;
                            progress.max = totalProducts;
                        }

                        processedCount += perPage;
                        if (processedCount > totalProducts) processedCount = totalProducts;

                        if (Array.isArray(data.added_products) && data.added_products.length > 0) {
                            addedCount += data.added_products.length;

                            const items = data.added_products.map(name => '✅ ' + name).join('<br>');
                            const block = document.createElement('div');
                            block.style.marginBottom = '10px';
                            block.innerHTML = items;
                            addedList.appendChild(block);
                        }

                        mainStatus.textContent = `${processedCount} ürün kontrol edildi – ${addedCount} toplam eklendi.`;
                        progress.value = processedCount;

                        if (!data.has_more || processedCount >= totalProducts) {
                            mainStatus.innerHTML += " <span style='color:green;'>🎉 Ürün aktarımı tamamlandı!</span>";
                            button.disabled = false;
                        } else {
                            page++;
                            setTimeout(processPage, 2000);
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
