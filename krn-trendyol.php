<?php
/*
Plugin Name: KRN - Trendyol WooCommerce Entegrasyonu
Plugin URI: https://bytaika.com/krn-trendyol
Description: Trendyol ürünlerini API üzerinden çekerek WooCommerce mağazanıza otomatik olarak aktarır. Ürün senkronizasyonu, cron tabanlı stok güncellemeleri ve gelişmiş yönetim paneli ile tam otomasyon sağlar.
Version: 1.2.2
Author: ByTaika Software Solutions
Author URI: https://web.bytaika.com
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html
Text Domain: krn-trendyol
Domain Path: /languages
Tags: woocommerce, trendyol, entegrasyon, ürün aktarımı, stok senkronizasyonu, cron, otomatik güncelleme
Requires at least: 5.8
Tested up to: 6.8.2
Requires PHP: 7.4
*/

if (!defined('ABSPATH')) exit;


require_once plugin_dir_path(__FILE__) . 'vendor/yahnis-elsts/plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$myUpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/ByTaika/bytaika-trendyol/',
    __FILE__,
    'bytaika-trendyol'
);



// Dosyaları dahil et
require_once plugin_dir_path(__FILE__) . 'includes/trendyol-api.php';

// Admin menüsü
add_action('admin_menu', function () {
    add_menu_page(
        'KRN - Trendyol',              // Sayfa başlığı
        'KRN - Trendyol',              // Menü ismi
        'manage_options',              // Yetki
        'krn_trendyol_settings',       // Slug
        function () {
            include plugin_dir_path(__FILE__) . 'admin/settings-page.php';
        },
        'dashicons-cart',              // İkon
        4                              // Pozisyon → WooCommerce'ten yukarıda olsun
    );

    add_submenu_page(
        'krn_trendyol_settings',
        'Toplu Ürün Aktarımı',
        'Ürün Aktarımı',
        'manage_options',
        'krn_trendyol_bulk_import',
        function () {
            include plugin_dir_path(__FILE__) . 'admin/bulk-import.php';
        }
    );

    add_submenu_page(
        'krn_trendyol_settings',
        'Trendyol Stok Güncelleme',
        'Stok Güncelleme',
        'manage_woocommerce',
        'krn-trendyol-stock-update',
        function () {
            include plugin_dir_path(__FILE__) . 'admin/krn-trendyol-stock-update.php';
        }
    );
});

// AJAX işlemi
add_action('wp_ajax_krn_trendyol_ajax_import_products', 'krn_trendyol_ajax_import_products');

function krn_trendyol_ajax_import_products() {
    $page = isset($_GET['page']) ? intval($_GET['page']) : 0;
    $perPage = 50;

    // Toplam ürün sayısını ilk çağrıda al
    static $total_products = null;
    if ($total_products === null) {
        $data = krn_trendyol_api_request("products?page=0&size=1");
        $total_products = isset($data['totalElements']) ? intval($data['totalElements']) : 0;
    }

    $data = krn_trendyol_api_request("products?page=$page&size=$perPage");

    $added_products = [];

    if (!empty($data['content'])) {
        foreach ($data['content'] as $item) {
            $sku = $item['barcode'] ?? $item['stockCode'] ?? $item['productCode'];
            if (!$sku || wc_get_product_id_by_sku($sku)) {
                continue; // Zaten varsa atla
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

            // Görsel ekleme
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

            $added_products[] = $product->get_name();
        }
    }

    wp_send_json([
        'total_products' => $total_products,
        'added_products' => $added_products,
        'has_more' => !empty($data['content']) && count($data['content']) === $perPage,
    ]);
}

add_action('wp_ajax_krn_trendyol_stock_update', 'krn_trendyol_stock_update_ajax');

function krn_trendyol_stock_update_ajax() {
    require_once plugin_dir_path(__FILE__) . 'includes/trendyol-api.php';
    $result = krn_trendyol_update_stocks();
    wp_send_json(['message' => $result]);
}

add_action('wp_ajax_krn_trendyol_stock_update_step', 'krn_trendyol_stock_update_step');

function krn_trendyol_stock_update_step() {
    $page = isset($_GET['page']) ? intval($_GET['page']) : 0;
    $perPage = 50;

    // Toplam ürün sayısını al - bunu API'den veya veritabanından çekmelisin
    // Örnek olarak Trendyol API'den tüm ürün sayısını al
    $data = krn_trendyol_api_request("products?page=0&size=1");
    $total_products = isset($data['totalElements']) ? intval($data['totalElements']) : 0;

    // Ürünleri sayfa sayfa çek
    $data = krn_trendyol_api_request("products?page=$page&size=$perPage");

    $updated_products = [];

    if (!empty($data['content'])) {
        foreach ($data['content'] as $product_data) {
            // Burada stok güncelleme işlemini yap, güncellenen ürünün adını $updated_products'a ekle
            // Örnek:
            $sku = $product_data['barcode'] ?? $product_data['stockCode'] ?? null;
            if (!$sku) continue;

            $wc_product_id = wc_get_product_id_by_sku($sku);
            if (!$wc_product_id) continue;

            $product = wc_get_product($wc_product_id);
            if (!$product) continue;

            $new_stock = $product_data['quantity'] ?? 0;

            if ($product->get_stock_quantity() != $new_stock) {
                $product->set_stock_quantity($new_stock);
                $product->save();
                $updated_products[] = $product->get_name();
            }
        }
    }

    // JSON çıktı
    wp_send_json([
        'total_products' => $total_products,
        'updated_products' => $updated_products,
        'has_more' => !empty($data['content']) && count($data['content']) === $perPage,
    ]);
}

add_action('wp_ajax_krn_test_api', 'krn_test_api_callback');

function krn_test_api_callback() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Yetkisiz erişim']);
    }

    $creds = krn_trendyol_get_credentials();
    $supplier_id = $creds['supplier_id'];
    $client_id = $creds['api_key'];
    $client_secret = $creds['api_secret'];

    if (!$supplier_id || !$client_id || !$client_secret) {
        wp_send_json_error(['message' => 'Eksik ayar var']);
    }

     $url = "https://apigw.trendyol.com/integration/product/sellers/{$supplier_id}/products?page=0&size=1";

    $response = wp_remote_get($url, [
        'headers' => [
            'Authorization' => 'Basic ' . base64_encode($client_id . ':' . $client_secret),
            'Content-Type'  => 'application/json'
        ],
        'timeout' => 20,
    ]);

    if (is_wp_error($response)) {
        wp_send_json_error(['message' => $response->get_error_message()]);
    }

    $body = wp_remote_retrieve_body($response);
    $json = json_decode($body, true);

    if (is_array($json) && isset($json['content'])) {
        wp_send_json_success();
    } else {
        wp_send_json_error(['message' => $json['message'] ?? 'Beklenmeyen yanıt']);
    }
}

// CSV dışa aktarım
add_action('wp_ajax_krn_export_products_csv', function () {
    if (!current_user_can('manage_options')) exit;

    $products = wc_get_products([
        'limit' => -1,
        'meta_key' => '_krn_trendyol',
        'meta_value' => '1',
    ]);

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="ecemshop_products.csv"');
    $output = fopen('php://output', 'w');

    fputcsv($output, ['Ürün Adı', 'SKU', 'Fiyat', 'Stok']);

    foreach ($products as $p) {
        fputcsv($output, [
            $p->get_name(),
            $p->get_sku(),
            $p->get_price(),
            $p->get_stock_quantity(),
        ]);
    }

    fclose($output);
    exit;
});

// Otomatik stok güncelleme için wp_cron tanımı
add_action('init', function () {
    if (!wp_next_scheduled('krn_trendyol_daily_stock_update')) {
        wp_schedule_event(time(), 'hourly', 'krn_trendyol_daily_stock_update');
    }
});

// Cron görevini çalıştır
add_action('krn_trendyol_daily_stock_update', function () {
    $auto = get_option('krn_trendyol_auto_stock');
    $hour = get_option('krn_trendyol_stock_hour');

    if ($auto !== 'yes' || !$hour) return;

    $current_hour = current_time('H:i');
    if ($current_hour === $hour) {
        krn_trendyol_update_stocks();
    }
});