<?php

/**
 * Thiết lập đa ngôn ngữ MỘT LẦN cho SANYUAN theme (Polylang).
 *
 * Chạy lại được nhiều lần (idempotent). Cách chạy:
 *   php -r 'define("WP_USE_THEMES",false); require "wp-load.php";
 *           require "wp-content/themes/sanyuan-theme/app/setup-i18n.php";'
 *
 * Việc nó làm:
 *   1. Thêm ngôn ngữ English (en) nếu chưa có; đặt en làm MẶC ĐỊNH (URL '/').
 *      Tiếng Trung (zh) đặt dưới '/zh/'. Bật dịch cho product + product_cat.
 *   2. Gán ngôn ngữ en cho mọi page/product/product_cat đang có (nội dung gốc là
 *      tiếng Anh). 16 page hiện đang bị gán nhầm zh → chuyển về en.
 *   3. Tạo bản dịch zh (rỗng) cho từng trang quản lý (home/about/product/…),
 *      liên kết với bản en. Bản zh để trống nên TỰ fallback về bản tiếng Anh cho
 *      tới khi điền nội dung / bỏ file mirror public/site/zh/.
 *
 * KHÔNG nhân bản 426 sản phẩm / 107 danh mục sang zh: link map đã có fallback về
 * bản mặc định nên trang /zh/ không vỡ link; muốn dịch sản phẩm/danh mục nào thì
 * tạo bản dịch của nó trong wp-admin (Polylang sẽ tự nâng link sang /zh/).
 */

if (! function_exists('pll_languages_list') || ! function_exists('PLL')) {
    echo "[setup-i18n] Polylang chưa kích hoạt — bỏ qua.\n";
    return;
}

$cli = (defined('WP_CLI') || PHP_SAPI === 'cli');
$log = function (string $m) use ($cli) {
    if ($cli) {
        echo '[setup-i18n] ' . $m . "\n";
    }
};

/* -------------------------------------------------------------------------
 | 1. Ngôn ngữ + tuỳ chọn Polylang
 * ---------------------------------------------------------------------- */
$model = PLL()->model;

$have = (array) pll_languages_list(['fields' => 'slug']);

// Thêm English nếu thiếu.
if (! in_array('en', $have, true)) {
    $res = $model->languages->add([
        'locale'     => 'en_US',
        'slug'       => 'en',
        'name'       => 'English',
        'rtl'        => false,
        'flag'       => 'us',
        'term_group' => 0,
    ]);
    if (is_wp_error($res)) {
        $log('LỖI thêm en: ' . $res->get_error_message());
    } else {
        $log('Đã thêm ngôn ngữ English (en).');
    }
} else {
    $log('Ngôn ngữ en đã có.');
}

// Thêm Chinese nếu thiếu (thường đã có sẵn).
$have = (array) pll_languages_list(['fields' => 'slug']);
if (! in_array('zh', $have, true)) {
    $res = $model->languages->add([
        'locale'     => 'zh_CN',
        'slug'       => 'zh',
        'name'       => '中文',
        'rtl'        => false,
        'flag'       => 'cn',
        'term_group' => 1,
    ]);
    $log(is_wp_error($res) ? ('LỖI thêm zh: ' . $res->get_error_message()) : 'Đã thêm ngôn ngữ 中文 (zh).');
}

// Tuỳ chọn: en mặc định, URL theo thư mục, ẩn tiền tố cho mặc định, bật dịch
// product + product_cat.
$opt = $model->options;
$opt['default_lang']  = 'en';
$opt['force_lang']    = 1;     // 1 = ngôn ngữ theo thư mục (/zh/)
$opt['hide_default']  = true;  // en không có tiền tố URL
$opt['redirect_lang'] = true;  // URL trang chủ dùng mã ngôn ngữ (/zh/) thay vì /zh/home/

$pt = (array) ($opt['post_types'] ?? []);
if (! in_array('product', $pt, true)) {
    $pt[] = 'product';
}
$opt['post_types'] = array_values($pt);

$tx = (array) ($opt['taxonomies'] ?? []);
if (! in_array('product_cat', $tx, true)) {
    $tx[] = 'product_cat';
}
$opt['taxonomies'] = array_values($tx);

$opt->save();
$model->clean_languages_cache();

$log('Tuỳ chọn: default=' . pll_default_language()
    . ' | dịch product=' . (pll_is_translated_post_type('product') ? 'yes' : 'NO')
    . ' | dịch product_cat=' . (pll_is_translated_taxonomy('product_cat') ? 'yes' : 'NO'));

/* -------------------------------------------------------------------------
 | 2. Gán ngôn ngữ en cho nội dung hiện có (gốc tiếng Anh)
 * ---------------------------------------------------------------------- */
// Pages: ép về en (kể cả các page đang bị gán nhầm zh). Bản dịch zh tạo ở bước 3.
$pageIds = get_posts(['post_type' => 'page', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids']);
$nPage = 0;
foreach ($pageIds as $pid) {
    if (pll_get_post_language($pid) !== 'en') {
        pll_set_post_language($pid, 'en');
        $nPage++;
    }
}
$log("Gán en cho $nPage page (tổng " . count($pageIds) . ').');

// Products + product_cat (và mọi đối tượng dịch được khác) đang CHƯA có ngôn ngữ
// → gán hàng loạt về ngôn ngữ MẶC ĐỊNH (en). Phải dùng API Polylang: sau khi bật
// dịch, get_posts/get_terms bị lọc theo ngôn ngữ nên KHÔNG thấy post/term chưa gán.
// set_language_in_mass() xử lý theo lô 1000 + đệ quy cho tới hết.
$model->set_language_in_mass(); // null = ngôn ngữ mặc định (en)

// Đếm kiểm tra: số product/product_cat đã mang ngôn ngữ en (qua taxonomy 'language').
global $wpdb;
$enProd = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->posts} p
       JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
       JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy='language'
       JOIN {$wpdb->terms} t ON t.term_id = tt.term_id AND t.slug=%s
      WHERE p.post_type='product' AND p.post_status='publish'",
    'en'
));
$log("Sau gán hàng loạt: product mang ngôn ngữ en = $enProd.");

/* -------------------------------------------------------------------------
 | 3. Tạo bản dịch zh cho các trang quản lý
 * ---------------------------------------------------------------------- */
// slug => tiêu đề (khớp app/setup-pages.php / sanyuan_page_files()).
$managed = [
    'home'                     => 'Home',
    'about'                    => 'About',
    'product'                  => 'Product',
    'news'                     => 'News',
    'support'                  => 'Support',
    'contact'                  => 'Contact',
    'esg'                      => 'ESG',
    'cable-compliance'         => 'Cable Compliance',
    'cable-lab-overview'       => 'Cable Lab Overview',
    'cable-testing-inspection' => 'Cable Testing & Inspection',
];

$nNew = 0;
foreach ($managed as $slug => $title) {
    $en = get_page_by_path($slug);
    if (! $en) {
        $log("Bỏ qua '$slug' — chưa có page en (chạy setup-pages.php trước).");
        continue;
    }
    $enId = (int) $en->ID;

    // Đã có bản zh?
    $zhId = pll_get_post($enId, 'zh');
    if ($zhId) {
        continue;
    }

    $zhId = wp_insert_post([
        'post_title'   => $title . ' (中文)',
        'post_status'  => 'publish',
        'post_type'    => 'page',
        'post_content' => '',
    ]);
    if (is_wp_error($zhId) || ! $zhId) {
        $log("LỖI tạo bản zh cho '$slug'.");
        continue;
    }
    pll_set_post_language($zhId, 'zh');
    pll_save_post_translations(['en' => $enId, 'zh' => (int) $zhId]);
    // Dùng CHUNG slug để URL đẹp /zh/<slug>/. Cập nhật trực tiếp DB để bỏ qua
    // wp_unique_post_slug (vốn thêm hậu tố -2); Polylang phân biệt bản dịch bằng
    // tiền tố /zh/ nên trùng slug khác ngôn ngữ là hợp lệ.
    $wpdb->update($wpdb->posts, ['post_name' => $slug], ['ID' => (int) $zhId]);
    clean_post_cache((int) $zhId);
    $nNew++;
    $log("Tạo bản zh cho '$slug' (#$zhId).");
}
$log("Đã tạo $nNew trang zh mới.");

/* -------------------------------------------------------------------------
 | 4. Đặt cờ flush rewrite rules
 * ---------------------------------------------------------------------- */
// KHÔNG flush trực tiếp ở CLI: theme không được load nên rule /m/, /zh/m/ của
// theme chưa đăng ký → flush sẽ làm mất chúng. Đặt cờ để theme tự flush ở request
// web kế tiếp (xem app/mirror-extra.php), khi mọi rule đã đăng ký đầy đủ.
update_option('sanyuan_rewrite_flush', 1);
$log('Đã đặt cờ flush rewrite (theme flush ở request web kế tiếp). HOÀN TẤT.');
