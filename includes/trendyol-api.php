<?php
// Trendyol API bilgilerini al
function krn_trendyol_get_credentials() {
    return [
        'api_key'     => get_option('krn_trendyol_api_key'),
        'api_secret'  => get_option('krn_trendyol_api_secret'),
        'supplier_id' => '286628' // Kendi Trendyol ID'n
    ];
}

// API isteği gönder
function krn_trendyol_api_request($endpoint) {
    $creds = krn_trendyol_get_credentials();

    $url = "https://api.trendyol.com/sapigw/suppliers/{$creds['supplier_id']}/$endpoint";
    $headers = [
        'Authorization: Basic ' . base64_encode("{$creds['api_key']}:{$creds['api_secret']}"),
        'Content-Type: application/json'
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_FAILONERROR    => false,
    ]);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($response === false || $http_code >= 400) {
        $error = curl_error($ch) ?: "HTTP Hata Kodu: $http_code";
        curl_close($ch);
        return ['error' => $error];
    }

    curl_close($ch);

    return json_decode($response, true);
}

// Ürünleri içeri aktar (sayfa bazlı)
function krn_trendyol_import_products_batch($page = 0, $size = 10) {
    if (!class_exists('WC_Product')) {
        return ['message' => 'WooCommerce aktif değil.', 'has_more' => false];
    }

    $data = krn_trendyol_api_request("products?page=$page&size=$size");

    if (isset($data['error'])) {
        return ['message' => 'API hatası: ' . esc_html($data['error']), 'has_more' => false];
    }

    if (empty($data['content'])) {
        return ['message' => 'Yüklenecek başka ürün kalmadı.', 'has_more' => false];
    }

    $added = 0;

    foreach ($data['content'] as $item) {
        $sku = $item['barcode'] ?? $item['stockCode'] ?? $item['productCode'];
        if (!$sku || wc_get_product_id_by_sku($sku)) {
            continue;
        }

        $product = new WC_Product_Simple();
        $product->set_name($item['title'] ?? 'Adsız Ürün');
        $product->set_sku($sku);
        $product->set_price($item['salePrice'] ?? 0);
        $product->set_regular_price($item['listPrice'] ?? 0);
        $product->set_description($item['description'] ?? '');
        $product->set_manage_stock(true);
        $product->set_stock_quantity($item['quantity'] ?? 0);
        $product->update_meta_data('_krn_trendyol', '1');

        $product_id = $product->save();

        // Görsel ekle
        if (!empty($item['images'][0]['url'])) {
            $image_url = $item['images'][0]['url'];
            $attachment_id = media_sideload_image($image_url, $product_id, null, 'id');
            if (!is_wp_error($attachment_id)) {
                $product->set_image_id($attachment_id);
                $product->save();
            }
        }

        // Kategori ataması
        if (!empty($item['categoryName'])) {
            $cat_name = trim($item['categoryName']);
            $term = term_exists($cat_name, 'product_cat');

            if (!$term) {
                $term = wp_insert_term($cat_name, 'product_cat');
            }

            if (!is_wp_error($term)) {
                $term_id = is_array($term) ? $term['term_id'] : $term;
                wp_set_object_terms($product_id, intval($term_id), 'product_cat');
            }
        }

        $added++;
    }

    $has_more = count($data['content']) === $size;

    return [
        'message' => "$added ürün yüklendi. (Sayfa: $page)",
        'has_more' => $has_more
    ];
}

// Stok güncelle
function krn_trendyol_update_stocks() {
    if (!class_exists('WC_Product')) {
        return 'WooCommerce aktif değil.';
    }

    $products = wc_get_products(['limit' => -1]);
    $updated = 0;
    $errors = 0;

    foreach ($products as $product) {
        $sku = $product->get_sku();
        if (!$sku) continue;

        $stock_data = krn_trendyol_api_request("products?barcode=$sku");

        if (isset($stock_data['content'][0]['quantity'])) {
            $new_stock = $stock_data['content'][0]['quantity'];
            if ($product->get_stock_quantity() != $new_stock) {
                $product->set_stock_quantity($new_stock);
                $product->save();
                $updated++;
            }
        } else {
            $errors++;
            error_log("Stok güncellenemedi: $sku için veri bulunamadı veya Trendyol'da yok.");
        }
    }

    return "$updated ürünün stoku güncellendi. $errors ürün güncellenemedi.";
}
// İçe aktarılan ürünleri sil
function krn_trendyol_delete_all_imported() {
    $args = [
        'limit'      => -1,
        'meta_key'   => '_krn_trendyol',
        'meta_value' => '1',
    ];
    $products = wc_get_products($args);
    $deleted = 0;

    foreach ($products as $product) {
        $image_id = $product->get_image_id();
        if ($image_id) {
            wp_delete_attachment($image_id, true);
        }

        foreach ($product->get_gallery_image_ids() as $gid) {
            wp_delete_attachment($gid, true);
        }

        wp_delete_post($product->get_id(), true);
        $deleted++;
    }

    return "$deleted ürün ve görselleri silindi.";
}


function krn_trendyol_update_stocks_batch($page = 0, $per_page = 50) {
    if (!class_exists('WC_Product')) {
        return ['message' => 'WooCommerce aktif değil.', 'has_more' => false];
    }

    $offset = $page * $per_page;
    $args = [
        'limit'  => $per_page,
        'offset' => $offset,
        'orderby' => 'ID',
        'order'   => 'ASC',
    ];

    $products = wc_get_products($args);
    if (empty($products)) {
        return ['message' => 'Tüm ürünler kontrol edildi.', 'has_more' => false];
    }

    $updated = 0;
    $updated_products = [];

    foreach ($products as $product) {
        $sku = $product->get_sku();
        if (!$sku) continue;

        $stock_data = krn_trendyol_api_request("products?barcode=$sku");

        if (!empty($stock_data['content']) && isset($stock_data['content'][0]['quantity'])) {
            $new_stock = $stock_data['content'][0]['quantity'];
            if ($product->get_stock_quantity() != $new_stock) {
                $product->set_stock_quantity($new_stock);
                $product->save();
                $updated++;
                $updated_products[] = $product->get_name() . " (SKU: $sku)";
            }
        }
    }

    return [
        'message' => count($products) . " ürün kontrol edildi – $updated güncelleme yapıldı.",
        'updated_products' => $updated_products,
        'has_more' => count($products) === $per_page
    ];
}