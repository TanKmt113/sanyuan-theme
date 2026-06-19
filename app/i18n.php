<?php

/**
 * Đa ngôn ngữ (i18n) cho SANYUAN theme — lớp ngôn ngữ gắn vào pipeline render.
 *
 * Site render từ file mirror tĩnh + ACF injection + WooCommerce (xem routes.php,
 * products.php, categories.php, mirror-extra.php), KHÔNG đi qua content chuẩn của
 * WordPress — nên Polylang "trần" không tự dịch được phần thân trang. File này
 * thêm CHIỀU NGÔN NGỮ cho chính pipeline đó:
 *
 *  - current_lang(): ngôn ngữ hiện tại. Ưu tiên tiền tố URL /<lang>/ (chắc chắn
 *    đúng kể cả với route custom /m/ hay .html mà Polylang không gắn tiền tố),
 *    fallback pll_current_language(), cuối cùng là ngôn ngữ mặc định.
 *  - lang_read_relpath(): chọn biến thể mirror theo ngôn ngữ
 *    (public/site/<lang>/<file>), fallback file gốc tiếng Anh khi chưa có.
 *  - lang_page_id(): map 1 WP Page sang bản dịch CÙNG ngôn ngữ (để đọc ACF đúng
 *    ngôn ngữ — mỗi bản dịch trang là 1 post riêng nên field tự tách theo lang).
 *  - Filter acf/validate_post_id: ACF Options (Header/Footer/Thanh liên hệ) đọc/
 *    ghi theo ngôn ngữ — mặc định (en) giữ store 'options' cũ (không mất dữ liệu),
 *    ngôn ngữ phụ dùng 'options_<lang>'. Trong wp-admin, chọn ngôn ngữ ở thanh
 *    Polylang trên admin bar để sửa đúng bản.
 *  - lang_switch_html() / inject_lang_switch(): viết lại dropdown ngôn ngữ .lan của
 *    bản mirror TẠI CHỖ (giữ nguyên giao diện/CSS gốc), trỏ link sang URL bản dịch
 *    Polylang và chỉ liệt kê các ngôn ngữ đã cấu hình.
 *
 * An toàn khi Polylang vắng mặt: mọi hàm fallback về 1 ngôn ngữ (mặc định 'en').
 */

namespace App;

/** Ngôn ngữ mặc định (ngôn ngữ KHÔNG có tiền tố URL). */
function default_lang(): string
{
    return function_exists('pll_default_language')
        ? (pll_default_language() ?: 'en')
        : 'en';
}

/** Danh sách slug các ngôn ngữ đã cấu hình (rỗng nếu Polylang vắng). */
function lang_slugs(): array
{
    static $l = null;
    if ($l === null) {
        $l = function_exists('pll_languages_list')
            ? (array) pll_languages_list(['fields' => 'slug'])
            : [];
    }
    return $l;
}

/**
 * Ngôn ngữ hiện tại của request. Ưu tiên tiền tố URL /<lang>/ vì các route render
 * custom (template_redirect) phải biết ngôn ngữ ngay cả trên URL Polylang không
 * quản (vd /zh/m/...). Fallback pll_current_language(), rồi ngôn ngữ mặc định.
 */
function current_lang(): string
{
    $def = default_lang();

    $path = trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
    $seg  = ($path === '') ? '' : (string) strtok($path, '/');
    if ($seg !== '' && $seg !== $def && in_array($seg, lang_slugs(), true)) {
        return $seg;
    }

    if (function_exists('pll_current_language')) {
        $c = pll_current_language();
        if (is_string($c) && $c !== '') {
            return $c;
        }
    }

    return $def;
}

/** Có đang ở ngôn ngữ mặc định không. */
function is_default_lang(): bool
{
    return current_lang() === default_lang();
}

/** Tiền tố đường dẫn của ngôn ngữ hiện tại: '/' (mặc định) hoặc '/<lang>/'. */
function lang_base_path(): string
{
    return is_default_lang() ? '/' : '/' . current_lang() . '/';
}

/**
 * Đường dẫn ĐỌC file mirror theo ngôn ngữ: nếu không phải ngôn ngữ mặc định và
 * tồn tại public/site/<lang>/<relpath> thì trả về biến thể đó, ngược lại trả về
 * file gốc (tiếng Anh). Lưu ý: nơi gọi vẫn tính <base> theo $relpath GỐC để asset
 * dùng chung (assets_img, npublic) resolve đúng.
 */
function lang_read_relpath(string $relpath): string
{
    if (is_default_lang()) {
        return $relpath;
    }
    $cand = current_lang() . '/' . ltrim($relpath, '/');
    return is_readable(get_theme_file_path('public/site/' . $cand)) ? $cand : $relpath;
}

/** Map 1 page sang bản dịch cùng ngôn ngữ hiện tại (fallback chính nó). */
function lang_page_id(int $pageId): int
{
    if ($pageId <= 0) {
        return $pageId;
    }
    if (function_exists('pll_get_post')) {
        $tid = pll_get_post($pageId, current_lang());
        if ($tid) {
            return (int) $tid;
        }
    }
    return $pageId;
}

/**
 * ACF Options theo ngôn ngữ. Mặc định ('option'/'options') giữ nguyên cho ngôn
 * ngữ mặc định → không đụng dữ liệu Header/Footer hiện có; ngôn ngữ phụ đọc/ghi
 * 'options_<lang>'. Dùng pll_current_language() trực tiếp để đúng cả trong wp-admin
 * (ngôn ngữ chọn ở thanh Polylang) lẫn front-end.
 */
add_filter('acf/validate_post_id', function ($post_id, $original) {
    // Trong wp-admin: KHÔNG đổi — mỗi trang Options khai báo post_id riêng
    // (en = 'option', zh = 'options_zh'; xem app/acf-pages.php) nên biên tập đúng
    // store mà không phụ thuộc việc dò admin-language của Polylang.
    if (is_admin()) {
        return $post_id;
    }
    // Front-end: đọc store theo ngôn ngữ hiện tại (lấy từ tiền tố URL — tin cậy).
    if ($original === 'option' || $original === 'options') {
        $cur = current_lang();
        $def = default_lang();
        if ($cur !== '' && $def !== '' && $cur !== $def) {
            $post_id = 'options_' . $cur;
        }
    }
    return $post_id;
}, 10, 2);

/** Nhãn NGẮN hiển thị trong ô thu gọn (.lan-select) của ngôn ngữ hiện tại. */
function lang_labels(): array
{
    return ['en' => 'EN', 'zh' => '中文', 'ar' => 'AR', 'es' => 'ES'];
}

/** Tên ĐẦY ĐỦ hiển thị trong danh sách thả xuống (.option-lan). */
function lang_names(): array
{
    return ['en' => 'English', 'zh' => '中文', 'ar' => 'العربية', 'es' => 'Español'];
}

/**
 * Ảnh cờ (trong public/assets_img) theo slug ngôn ngữ. Trùng đúng bộ cờ mà bản
 * mirror tĩnh dùng cho dropdown .lan nên giao diện không đổi. Thêm slug mới ở đây
 * khi cấu hình thêm ngôn ngữ trong Polylang (ar/es đã sẵn cờ để bật về sau).
 */
function lang_flags(): array
{
    return [
        'en' => 'cf50aeb6-2c0b-4b34-b7b8-7937596c5462_05c657.png',
        'zh' => '92d51bcb-94b7-49ce-baa8-20a025fe98be_a230a3.png',
        'ar' => 'cd8a07ff-9ea1-4365-99b1-93dc06b926e5_3f6c7a.png',
        'es' => '5c46c4bb-fe69-499a-9e4b-1196a9006994_bafec4.png',
    ];
}

/**
 * Dựng lại HTML dropdown ngôn ngữ .lan theo ĐÚNG cấu trúc/CSS của bản mirror
 * (.lan > .lan-select + .option-lan > ul > li > a > img + span), nhưng các mục
 * sinh từ Polylang: chỉ ngôn ngữ đã cấu hình, link là URL bản dịch của trang hiện
 * tại (fallback trang chủ ngôn ngữ đó), ô thu gọn phản ánh ngôn ngữ hiện tại.
 *
 * $assetPrefix là tiền tố tới thư mục asset của CHÍNH trang đang render ('../',
 * '../../', …) để ảnh cờ ../assets_img/… resolve đúng theo độ sâu URL. Trả '' khi
 * Polylang vắng hoặc không có ngôn ngữ nào để hiển thị.
 */
function lang_switch_html(string $assetPrefix = '../'): string
{
    if (! function_exists('pll_the_languages')) {
        return '';
    }
    $langs = pll_the_languages(['raw' => 1, 'hide_if_no_translation' => 0]);
    if (! is_array($langs) || ! $langs) {
        return '';
    }

    $flags  = lang_flags();
    $names  = lang_names();
    $labels = lang_labels();
    $def    = default_lang();
    $cur    = current_lang();

    $img = static function (string $slug, string $name) use ($assetPrefix, $flags): string {
        $file = $flags[$slug] ?? '';
        if ($file === '') {
            return '';
        }
        return '<img src="' . esc_attr($assetPrefix . 'assets_img/' . $file) . '" alt="'
            . esc_attr($name) . '" title="' . esc_attr($name) . '" la="la"/>';
    };

    $items = '';
    foreach ($langs as $key => $l) {
        $slug = is_string($key) ? $key : (string) ($l['slug'] ?? '');
        if ($slug === '') {
            continue;
        }
        $name = $names[$slug] ?? (is_string($l['name'] ?? null) ? (string) $l['name'] : ucfirst($slug));
        $url  = (! empty($l['url']) && is_string($l['url']))
            ? $l['url']
            : home_url($slug === $def ? '/' : '/' . $slug . '/');
        $items .= "\n\t\t\t<li>\n\t\t\t\t<a href=\"" . esc_url($url) . "\">\n\t\t\t\t\t"
            . $img($slug, $name) . "\n\t\t\t\t\t<span>" . esc_html($name)
            . "</span>\n\t\t\t\t</a>\n\t\t\t</li>";
    }
    if ($items === '') {
        return '';
    }

    // Ô thu gọn: cờ + nhãn ngắn của ngôn ngữ hiện tại.
    $curName  = $names[$cur] ?? ucfirst($cur);
    $curLabel = $labels[$cur] ?? strtoupper($cur);

    return '<div class="lan">' . "\n\t" . '<div class="lan-select">' . "\n\t\t"
        . $img($cur, $curName) . "\n\t\t<span>" . esc_html($curLabel) . "</span>\n\t</div>\n\t"
        . '<div class="option-lan">' . "\n\t\t<ul>" . $items . "\n\t\t</ul>\n\t</div>\n</div>";
}

/**
 * Thay dropdown ngôn ngữ .lan có sẵn trong bản mirror BẰNG bản dựng lại từ Polylang.
 * Giữ nguyên giao diện (dùng lại CSS .lan của mirror) nhưng sửa link chết
 * (index.html, ../cn.* , ../ara.* , ../es.*) thành URL bản dịch thật. Tiền tố asset
 * lấy từ chính khối cũ để ảnh cờ resolve đúng ở mọi độ sâu URL. No-op nếu trang
 * không có khối .lan hoặc không dựng được nút.
 */
function inject_lang_switch(string $html): string
{
    $pattern = '~<div class="lan">\s*<div class="lan-select">.*?</ul>\s*</div>\s*</div>~s';

    return preg_replace_callback($pattern, static function (array $m): string {
        $prefix = '../';
        if (preg_match('~src="([^"]*?)assets_img/~', $m[0], $pm)) {
            $prefix = $pm[1];
        }
        $switch = lang_switch_html($prefix);
        return $switch !== '' ? $switch : $m[0];
    }, $html, 1) ?? $html;
}

/**
 * Nginx fastcgi_cache (BT panel) often ignores WP nocache_headers() and serves a
 * stale HTML snapshot. ZH mirrors ship root-absolute /css/ + /npublic/ URLs; if
 * cache predates mirror_normalize_lang_mirror_html(), JS/CSS 404 and scroll/GSAP
 * animations never boot.
 */
add_action('template_redirect', static function (): void {
    if (is_default_lang() || headers_sent()) {
        return;
    }
    header('X-Accel-Expires: 0', true);
    header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true);
}, -5);
