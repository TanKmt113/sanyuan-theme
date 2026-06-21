<?php

/**
 * Serve the ORIGINAL SANYUAN mirror, byte-for-byte, exactly like the local
 * static copy.
 *
 * The mirror lives untouched under public/site/ (with assets_img one level up
 * at public/assets_img, matching the original layout). We serve each page's
 * raw HTML and only inject a <base> tag pointing at that page's own directory
 * plus a small reveal stylesheet.
 *
 * Why <base> instead of rewriting paths: the 300.cn CMS runtime only rewrites
 * image URLs that start with "http" (it skips relative ones). The local copy
 * works precisely because its paths are relative, so the runtime leaves them
 * alone. Keeping the paths relative + a <base> reproduces that behaviour 1:1.
 */

namespace App;

/** Page-specific CSS bundled with the EN mirror (shared layout; zh mirrors reference missing CDN hashes). */
function mirror_lang_page_stylesheet(string $relpath): string
{
    static $map = [
        'index.html'                  => 'css/Home_403471a02b966c7734a7132d1252248b.minb1cb.css',
        'about.html'                  => 'css/about_dd60e8202254a2a23e663f82a5d36649.minb1cb.css',
        'about-acf.html'              => 'css/about_dd60e8202254a2a23e663f82a5d36649.minb1cb.css',
        'product.html'                => 'css/product_f6ae9a09a63049efb4dd82fe89af8f3b.minb1cb.css',
        'news.html'                   => 'css/news_b899781148defce9b41a1a4fbdc2e38f.minb1cb.css',
        'Support.html'                => 'css/Support_27b19577dab82f80325bd5cc28bca037.minb1cb.css',
        'concact.html'                => 'css/concact_087af3bde994ca1345aae23cd39feb93.minb1cb.css',
        'ESG.html'                    => 'css/ESG_2d8aead3fdabd0ab69bf567f0091b544.minb1cb.css',
        'CableCompliance.html'        => 'css/CableCompliance_f8fd7000e04678d13ccda639434cf70b.minb1cb.css',
        'CableLabOverview.html'       => 'css/CableLabOverview_40582e429c66ab136c5be7a695db96f2.minb1cb.css',
        'CableTestingInspection.html' => 'css/CableTestingInspection_14bcf3ce1e8fb026a85fe997167ef7db.minb1cb.css',
    ];

    return $map[basename($relpath)] ?? '';
}

/**
 * CN live mirrors use root-absolute asset URLs (/css/, /npublic/, /upload/) and
 * different bundle filenames. Remap them to the vendored EN assets under
 * public/site/ so <base href="…/public/site/"> resolves correctly.
 */
function mirror_normalize_lang_mirror_html(string $html, string $relpath): string
{
    $pageCss = mirror_lang_page_stylesheet($relpath);
    if ($pageCss !== '') {
        $html = preg_replace(
            '~href="/css/[^"]+\.min\.css[^"]*"~i',
            'href="' . $pageCss . '"',
            $html,
            1
        ) ?? $html;
    }

    $html = preg_replace(
        '~href="/css/site\.css[^"]*"~i',
        'href="css/siteb1cb.css"',
        $html
    ) ?? $html;

    $html = preg_replace(
        '~href="/npublic/libs/css/ceccbootstrap\.min\.css,global\.css[^"]*"~i',
        'href="npublic/libs/css/ceccbootstrap.min.css,globalb1cb.css"',
        $html
    ) ?? $html;

    $html = preg_replace(
        '~src="/npublic/libs/core/ceccjquery\.min\.js,require\.min\.js,lib\.min\.js,page\.min\.js[^"]*"~i',
        'src="npublic/libs/core/ceccjquery.min.js,require.min.js,lib.min.js,page.minb1cb.js"',
        $html
    ) ?? $html;

    $html = preg_replace(
        '~src="/npublic/commonjs/common\.min\.js[^"]*"~i',
        'src="npublic/commonjs/common.minb1cb.js"',
        $html
    ) ?? $html;

    // upload/*.js — prefer local *b1cb.js bundles when present.
    $html = preg_replace_callback(
        '~src="/upload/js/([a-f0-9]+)\.js([^"]*)"~i',
        static function (array $m): string {
            $base = $m[1];
            $tail = $m[2];
            $b1cb = get_theme_file_path('public/site/upload/js/' . $base . 'b1cb.js');

            return 'src="upload/js/' . $base . (is_readable($b1cb) ? 'b1cb' : '') . '.js' . $tail . '"';
        },
        $html
    ) ?? $html;

    // Remaining theme-local assets: drop the leading slash so <base> applies.
    $html = preg_replace('~href="/(npublic|css|upload)/~i', 'href="$1/', $html) ?? $html;
    $html = preg_replace('~src="/(npublic|css|upload)/~i', 'src="$1/', $html) ?? $html;

    // Strip CN mirror caption placeholders not mapped to ACF fields.
    $html = preg_replace(
        '~<p class="e_text-7[^"]*"[^>]*>\s*这里是占位文字\s*</p>~u',
        '',
        $html
    ) ?? $html;
    $html = preg_replace(
        '~<p class="e_text-3[12][^"]*"[^>]*>\s*</p>~u',
        '',
        $html
    ) ?? $html;

    return $html;
}

/** True when mirror HTML still carries CN-site root-absolute asset URLs. */
function mirror_needs_lang_normalize(string $html): bool
{
    return str_contains($html, 'href="/css/')
        || str_contains($html, 'href="/npublic/')
        || str_contains($html, 'src="/npublic/')
        || str_contains($html, 'lazy="https://');
}

/**
 * CN mirrors lazy-load via lazy="…" while src is a 1×1 placeholder (s.png).
 * Promote the real URL into src before we strip the lazy/needthumb hooks.
 */
function mirror_promote_lazy_src(string $html): string
{
    return preg_replace_callback(
        '/<img\b([^>]*?)>/i',
        static function (array $m): string {
            $tag = $m[0];
            if (! preg_match('/\blazy="([^"]+)"/i', $tag, $lazy)) {
                return $tag;
            }
            $lazyUrl = $lazy[1];
            if ($lazyUrl === '') {
                return $tag;
            }
            if (preg_match('/\bsrc="([^"]*)"/i', $tag, $src)) {
                $srcUrl = $src[1];
                if ($srcUrl !== ''
                    && ! preg_match('~(?:^|/|\\.\\./)npublic/img/s\\.png$~i', $srcUrl)) {
                    return $tag;
                }
            }
            if (preg_match('/\bsrc="/i', $tag)) {
                return preg_replace('/\bsrc="[^"]*"/i', 'src="' . esc_attr($lazyUrl) . '"', $tag, 1) ?? $tag;
            }

            return preg_replace('/<img\b/i', '<img src="' . esc_attr($lazyUrl) . '"', $tag, 1) ?? $tag;
        },
        $html
    ) ?? $html;
}

/**
 * Stub dead 300.cn CMS APIs + patch jQuery.ajax so mirror runtime does not 404/reject.
 */
function sanyuan_mirror_runtime_guard_scripts(): string
{
    $npublic = esc_url(get_theme_file_uri('public/site/npublic/'));

    return '<script id="sanyuan-mirror-guard">'
        . '(function(){'
        . 'window.__sanyuanNpublic=' . wp_json_encode($npublic) . ';'
        . 'function dead(u){if(!u||typeof u!=="string")return false;'
        . 'return/\/nportal\//.test(u)||/\/fwebapi\//.test(u)||/\/ndesigner\//.test(u)'
        . '||/\/thirdcode\//.test(u)||/\/icp(?:\\?|$)/.test(u);}'
        . 'var stub=\'{"code":200,"data":null}\';'
        . 'var pf=window.fetch;if(pf){window.fetch=function(i,o){'
        . 'var u=typeof i==="string"?i:(i&&i.url)||"";'
        . 'if(dead(u))return Promise.resolve(new Response(stub,{status:200,headers:{"Content-Type":"application/json"}}));'
        . 'return pf.apply(this,arguments);};}'
        . 'var xo=XMLHttpRequest.prototype.open,xs=XMLHttpRequest.prototype.send;'
        . 'XMLHttpRequest.prototype.open=function(m,u){this._sd=dead(u);return xo.apply(this,arguments);};'
        . 'XMLHttpRequest.prototype.send=function(){if(this._sd){var x=this;setTimeout(function(){'
        . 'try{x.readyState=4;x.status=200;x.responseText=stub;}catch(e){}'
        . 'if(x.onreadystatechange)x.onreadystatechange();if(x.onload)x.onload();},0);return;}'
        . 'return xs.apply(this,arguments);};'
        . 'window.__sanyuanPatchAjax=function(){if(!window.jQuery||window.jQuery._sanyuanAjax)return;'
        . 'var a=window.jQuery.ajax;window.jQuery.ajax=function(u,c){'
        . 'var url=typeof u==="string"?u:(u&&u.url)||"";'
        . 'if(typeof u==="object"&&u.url)url=u.url;'
        . 'if(dead(url))return window.jQuery.Deferred().resolve({code:200,data:null}).promise();'
        . 'return a.apply(this,arguments);};window.jQuery._sanyuanAjax=1;};'
        . 'if(window.jQuery)window.__sanyuanPatchAjax();'
        . 'else{var n=0,t=setInterval(function(){if(window.jQuery||++n>500){clearInterval(t);window.__sanyuanPatchAjax();}},0);}'
        . 'window.addEventListener("unhandledrejection",function(e){'
        . 'var r=e.reason;if(!r)return;'
        . 'var u=String(r.responseURL||r.url||(r.settings&&r.settings.url)||"");'
        . 'if(dead(u)||r.status===404||r.status===0||(r.readyState===4&&r.status>=400)){e.preventDefault();}});'
        . '})();</script>';
}

/** Inline script injected right after the RequireJS core bundle. */
function sanyuan_mirror_requirejs_fix_script(): string
{
    $siteRoot = untrailingslashit(get_theme_file_uri('public/site'));

    return '<script>(function(){var r=window.require||window.requirejs;'
        . 'if(r&&r.config){r.config({baseUrl:' . wp_json_encode($siteRoot . '/npublic/') . '});'
        . 'if(r.define){var s=function(){return{init:function(a,b,c,d){d&&d();}}};'
        . '["hidden","turnpage","turnpageAjax","rolling","marquee","clickLoad","scrollLoad","singleRolling","singleMarquee"]'
        . '.forEach(function(n){r.define("libs/widget/pageEffect/"+n,[],s);});}}'
        . 'window.__sanyuanPatchAjax&&window.__sanyuanPatchAjax();})();</script>';
}

/**
 * Build the processed HTML for a mirror page: original markup with a <base>
 * tag (matching the page's own directory), the dead lazy/thumbnail hooks
 * stripped, and the scroll-reveal CSS injected. Returns '' if the file is
 * missing. Pure string in/out so it can be used both by the .html router
 * (echo) and by a Blade page template ({!! !!}).
 */
function mirror_html(string $relpath): string
{
    // Đa ngôn ngữ: đọc biến thể mirror theo ngôn ngữ (public/site/<lang>/<file>),
    // fallback file gốc tiếng Anh khi chưa có. <base> vẫn tính theo $relpath GỐC
    // (dưới đây) để asset dùng chung (assets_img, npublic) resolve đúng dù file
    // bản dịch nằm ở thư mục khác — xem App\lang_read_relpath().
    $readPath = function_exists('App\\lang_read_relpath') ? lang_read_relpath($relpath) : $relpath;
    $file = get_theme_file_path('public/site/' . $readPath);

    if (! is_readable($file)) {
        return '';
    }

    $dir = trim(str_replace('\\', '/', dirname($relpath)), '.');
    $base = untrailingslashit(get_theme_file_uri('public/site'));
    if ($dir !== '' && $dir !== '/') {
        $base .= '/' . trim($dir, '/');
    }
    $base .= '/';

    $html = file_get_contents($file);

    // CN lazy images: real URL lives in lazy="…", src is only s.png — promote first.
    $html = mirror_promote_lazy_src($html);

    // Lang mirrors downloaded from the live CN site ship root-absolute /css/ paths.
    if ($readPath !== $relpath || mirror_needs_lang_normalize($html)) {
        $html = mirror_normalize_lang_mirror_html($html, $relpath);
    }

    // Strip the CMS lazy-load / thumbnail hooks. pl_readyload rewrites every
    // needthumb image to a thumbnail on the dead thefastimg CDN, blanking it;
    // removing the trigger leaves the real local src in place. src/alt/title
    // are untouched.
    $html = preg_replace('/\s+(?:lazy|needthumb|la)="[^"]*"/i', '', $html);

    // Point the main navigation at the real WordPress Pages (clean, root-
    // absolute URLs) instead of the raw .html mirror files. Without this the
    // <base> tag would resolve nav links to public/site/*.html — static files
    // that nginx serves UNPROCESSED (no dynamic runtime, broken images). Root-
    // absolute URLs ignore <base> and hit the WP Page → rendered via mirror_html.
    // Đa ngôn ngữ: nav trỏ về clean URL của ĐÚNG ngôn ngữ hiện tại
    // ($lp = '/' cho mặc định, '/zh/' cho tiếng Trung…).
    $lp  = function_exists('App\\lang_base_path') ? lang_base_path() : '/';
    $nav = [
        'index.html'                  => $lp,
        'about.html'                  => $lp . 'about/',
        'product.html'                => $lp . 'product/',
        'news.html'                   => $lp . 'news/',
        'Support.html'                => $lp . 'support/',
        'concact.html'                => $lp . 'contact/',
        'ESG.html'                    => $lp . 'esg/',
        'CableCompliance.html'        => $lp . 'cable-compliance/',
        'CableLabOverview.html'       => $lp . 'cable-lab-overview/',
        'CableTestingInspection.html' => $lp . 'cable-testing-inspection/',
    ];
    // Match each nav file with any "../" depth (subdirectory pages like
    // product_Details/ or product_list/ link as ../index.html, ../product.html…)
    // and an optional leading "/", so the logo, header menu and footer nav all
    // reach the clean WP pages instead of resolving via <base> to theme files.
    foreach ($nav as $file => $url) {
        $html = preg_replace(
            '~href="(?:\.\./|/)*' . preg_quote($file, '~') . '"~i',
            'href="' . $url . '"',
            $html
        );
    }
    // The "Contact" menu item links to concactd41d.html (with a #fragment/query),
    // at any "../" depth.
    $html = preg_replace('#href="(?:\.\./)*concactd41d\.html[^"]*"#i', 'href="' . $lp . 'contact/"', $html);

    // NOTE: product/category link rewriting is applied by sanyuan_finalize_links()
    // as the LAST step of each render path (not here): ACF page-field/chrome
    // injection can re-insert original (un-rewritten) fragments after mirror_html,
    // so the rewrite must run after all injection.

    // The page is rendered DYNAMICALLY: the original 300.cn runtime (RequireJS +
    // GSAP newAnimat) now boots on this host (see the baseUrl fix below + the
    // /npublic modules vendored under public/site/npublic), so the real
    // per-element scroll animations run exactly like local. We therefore do NOT
    // inject any reveal CSS/JS of our own — that would fight GSAP's transforms.
    // A tiny safety net only un-hides content if, after a few seconds, the
    // runtime never revealed it (e.g. a module failed to load), so nothing is
    // ever left permanently invisible.
    $safety = '<script>setTimeout(function(){'
        . 'document.querySelectorAll(\'[id^="c_static"] [class*="e_"]\').forEach(function(e){'
        . 'var c=getComputedStyle(e);'
        . 'if((c.visibility==="hidden"||parseFloat(c.opacity)===0)&&e.getBoundingClientRect().height>0){'
        . 'e.style.visibility="visible";e.style.opacity="1";}});},4000);</script>';

    // Inject <base> first (so relative URLs resolve like local) + runtime guard + safety net.
    $html = preg_replace(
        '/<head\b[^>]*>/i',
        '$0' . '<base href="' . esc_attr($base) . '">' . sanyuan_mirror_runtime_guard_scripts() . $safety,
        $html,
        1
    );

    // Fix the 300.cn RequireJS baseUrl. The core bundle configures
    // require({baseUrl: baseOrigin + "/npublic/"}) where baseOrigin is "" off
    // the original host, so every lazily-loaded module (swiper, cmsAjax,
    // pl_util, the language pack…) is fetched from the site ROOT /npublic/ and
    // 404s here — which then aborts RequireJS with a "Script error". The mirror
    // serves npublic from the theme, not the web root, so we re-point baseUrl at
    // it. Injected immediately AFTER the core bundle's <script> (so it overrides
    // the bundle's own config) and BEFORE common.min.js requests the modules.
    $siteRoot = untrailingslashit(get_theme_file_uri('public/site'));
    $fix = sanyuan_mirror_requirejs_fix_script();
    $html = preg_replace(
        '#(<script\b[^>]*\bsrc=["\'][^"\']*npublic/libs/core/ceccjquery[^"\']*["\'][^>]*>\s*</script>)#i',
        '$1' . $fix,
        $html,
        1
    );

    $html = sanyuan_strip_mirror_seo_tags($html);

    return inject_site_icon($html);
}

/** Serve mirror assets requested from site-root /npublic/… (original CMS absolute paths). */
function sanyuan_serve_npublic_static(): void
{
    $path = trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
    if ($path === '' || ! str_starts_with($path, 'npublic/')) {
        return;
    }
    if (strpos($path, '..') !== false || ! preg_match('~^npublic/[A-Za-z0-9_./-]+$~', $path)) {
        status_header(403);
        exit;
    }
    $file = get_theme_file_path('public/site/' . $path);
    if (! is_readable($file) || is_dir($file)) {
        return;
    }
    $types = [
        'js'   => 'application/javascript; charset=UTF-8',
        'css'  => 'text/css; charset=UTF-8',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'svg'  => 'image/svg+xml',
        'woff' => 'font/woff',
        'woff2'=> 'font/woff2',
    ];
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    header('Cache-Control: public, max-age=86400');
    readfile($file);
    exit;
}

/** Return empty JSON for dead 300.cn API paths still hit by the mirror runtime. */
function sanyuan_stub_dead_mirror_api(): void
{
    $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    if ($path === '/icp' || preg_match('#^/(nportal|ndesigner|thirdcode)/#', $path)) {
        header('Content-Type: application/json; charset=UTF-8');
        echo '{"code":200,"data":null}';
        exit;
    }
}

add_action('template_redirect', __NAMESPACE__ . '\\sanyuan_serve_npublic_static', -3);
add_action('template_redirect', __NAMESPACE__ . '\\sanyuan_stub_dead_mirror_api', -3);

/** Swap default mirror logo filenames (EN assets_img + ZH CDN) for an uploaded URL. */
function sanyuan_replace_mirror_logo_refs(string $html, string $logoUrl, string $assetStem): string
{
    if ($logoUrl === '' || $assetStem === '') {
        return $html;
    }
    $stem = preg_quote($assetStem, '~');
    $html = preg_replace(
        '~(?:\.\./)*assets_img/' . $stem . '[^"\'\s>]*~',
        $logoUrl,
        $html
    ) ?? $html;
    $html = preg_replace(
        '~https://omo-oss-image\.thefastimg\.com/[^"\'\s>]*' . $stem . '[^"\'\s>]*~',
        $logoUrl,
        $html
    ) ?? $html;

    return $html;
}

/** Hide mirror header logo images matching a known asset stem (partial filename). */
function sanyuan_hide_mirror_logo_refs(string $html, string $assetStem): string
{
    if ($assetStem === '') {
        return $html;
    }
    $stem = preg_quote($assetStem, '~');

    return preg_replace_callback(
        '~<img\b[^>]*\bsrc="(?:[^"]*(?:\.\./)*assets_img/' . $stem . '[^"]*|[^"]*thefastimg\.com/[^"]*' . $stem . '[^"]*)"[^>]*>~i',
        static function (array $m): string {
            $tag = $m[0];
            if (preg_match('~\bstyle="([^"]*)"~i', $tag, $sm)) {
                $style = rtrim($sm[1], ';') . ';display:none!important;visibility:hidden!important';

                return preg_replace('~\bstyle="[^"]*"~i', 'style="' . $style . '"', $tag, 1) ?? $tag;
            }

            return preg_replace('~<img\b~i', '<img style="display:none!important;visibility:hidden!important"', $tag, 1) ?? $tag;
        },
        $html
    ) ?? $html;
}

/** Replace mirror favicon.ico with the Site Icon set in Customizer → Site Identity. */
function inject_site_icon(string $html): string
{
    if (! function_exists('has_site_icon') || ! has_site_icon() || ! function_exists('wp_site_icon')) {
        return $html;
    }

    // Mirror ships a static favicon.ico resolved via <base>; drop it so WP icon wins.
    $html = preg_replace(
        '~<link\b[^>]*\bhref=["\'](?:\.\./)*favicon\.ico["\'][^>]*>~i',
        '',
        $html
    );

    ob_start();
    wp_site_icon();
    $icons = trim((string) ob_get_clean());
    if ($icons === '') {
        return $html;
    }

    $out = preg_replace('/<\/head>/i', $icons . "\n</head>", $html, 1);

    return is_string($out) ? $out : $html;
}

/**
 * Render the SANYUAN mirror pages from the template_redirect hook (a standard
 * WordPress hook that fires inside the request lifecycle). We render here
 * rather than from a Blade page template because Acorn 6 serves the response
 * through its own Laravel layer and never includes the theme's index.php, so a
 * page template would never run for these large pages.
 *
 *  - The front page is the real WordPress "Home" Page (Settings → Reading →
 *    static front page). We detect it with is_front_page() and render the
 *    mirror home, so the home is a managed WP Page, not a hard-coded path.
 *  - Every other /<path>.html maps to the matching mirror file at its original
 *    URL. Runs before redirect_canonical (priority 0).
 */
/**
 * Map of WordPress Page slug → mirror file. Each of these is a real WP Page
 * (managed in wp-admin, clean URL like /about) that the theme renders
 * dynamically from the corresponding mirror document — exactly like the Home
 * front page. Run app/setup-pages.php once to create the Pages.
 */
function mirror_pages(): array
{
    return [
        // 'about' and 'home' are added in sanyuan_page_files().
        'product'                    => 'product.html',
        'news'                       => 'news.html',
        'support'                    => 'Support.html',
        'contact'                    => 'concact.html',
        'esg'                        => 'ESG.html',
        'cable-compliance'           => 'CableCompliance.html',
        'cable-lab-overview'         => 'CableLabOverview.html',
        'cable-testing-inspection'   => 'CableTestingInspection.html',
    ];
}

/**
 * Managed pages: slug => mirror file. Each renders its ORIGINAL mirror markup
 * byte-for-byte, with ACF fragments injected on top (see sanyuan_inject_fields).
 * About serves about-acf.html (byte-identical to about.html); home is the
 * static front page (index.html).
 */
function sanyuan_page_files(): array
{
    return array_merge(
        ['home' => 'index.html', 'about' => 'about-acf.html'],
        mirror_pages()
    );
}

/**
 * Map a WP Page (any Polylang translation) to its canonical mirror slug.
 * Handles slug drift (e.g. contact-2) by scanning all translations in the group.
 */
function sanyuan_managed_mirror_slug(int $pageId): string
{
    $files = sanyuan_page_files();
    $ids   = [$pageId];
    if (function_exists('pll_get_post_translations')) {
        $ids = array_values(array_filter(array_map('intval', pll_get_post_translations($pageId))));
    }
    foreach ($ids as $id) {
        $slug = (string) get_post_field('post_name', $id);
        if (isset($files[$slug])) {
            return $slug;
        }
    }

    $enId = (function_exists('pll_get_post') && function_exists('default_lang'))
        ? (int) (pll_get_post($pageId, default_lang()) ?: $pageId)
        : $pageId;
    $slug = (string) get_post_field('post_name', $enId);
    if (preg_match('/^(.+)-\d+$/', $slug, $m) && isset($files[$m[1]])) {
        return $m[1];
    }

    return $slug;
}

/**
 * Final pass for any rendered mirror HTML: repoint the static product/category
 * file links at their managed WooCommerce URLs. Run LAST (after all ACF
 * injection), because inject_footer / sanyuan_inject_fields can re-insert the
 * original (un-rewritten) fragments. Idempotent — already-rewritten URLs no
 * longer match the raw product_Details/product_list patterns.
 */
function sanyuan_finalize_links(string $html): string
{
    if (function_exists('App\\sanyuan_rewrite_product_links')) {
        $html = sanyuan_rewrite_product_links($html);
    }
    if (function_exists('App\\sanyuan_rewrite_category_links')) {
        $html = sanyuan_rewrite_category_links($html);
    }
    // Remaining content mirror pages (news/compliance/highlights/SEO) -> /m/<path>/.
    if (function_exists('App\\sanyuan_rewrite_content_links')) {
        $html = sanyuan_rewrite_content_links($html);
    }
    return $html;
}

/**
 * WP page ID for ACF on a managed mirror page (Polylang-safe).
 * Front page uses queried object / page_on_front — get_page_by_path('home') is
 * unreliable with Polylang and can point at the wrong language copy.
 */
function managed_page_acf_id(string $slug): int
{
    $pid = 0;
    if ($slug === 'home') {
        if (function_exists('is_front_page') && is_front_page()) {
            $pid = (int) get_queried_object_id();
        }
        if ($pid <= 0) {
            $pid = (int) get_option('page_on_front');
        }
    }
    if ($pid <= 0 && function_exists('get_page_by_path')) {
        $page = get_page_by_path(sanyuan_page_slug($slug));
        $pid  = $page ? (int) $page->ID : 0;
    }
    if ($pid <= 0 && function_exists('is_page') && is_page()) {
        $qid = (int) get_queried_object_id();
        if ($qid > 0 && sanyuan_managed_mirror_slug($qid) === $slug) {
            $pid = $qid;
        }
    }
    if ($pid > 0 && function_exists('App\\lang_page_id')) {
        $pid = lang_page_id($pid);
    }

    return $pid;
}

/**
 * Render a managed page: original mirror shell + ACF injection. Empty fields clear
 * mirror fragments (no static fallback); nothing is fully rebuilt except curated blocks.
 */
function render_managed_page(string $slug, string $file): string
{
    $html = mirror_html($file);

    // Đa ngôn ngữ: lấy ID page của ĐÚNG ngôn ngữ hiện tại để đọc ACF (mỗi bản
    // dịch là 1 post riêng nên field tự tách theo ngôn ngữ).
    $pid = managed_page_acf_id($slug);

    if (function_exists('get_field')) {
        if ($pid > 0) {
            $html = sanyuan_inject_fields($html, $pid, $slug);
            $html = sanyuan_inject_lang_mirror_fields($html, $pid, $slug);
            if ($slug === 'home') {
                $html = sanyuan_inject_home_extras($html, $pid);
            }
            if ($slug === 'about') {
                $html = sanyuan_inject_about_hero($html, $pid);
            }
        }
        // Shared, edited-once-applies-everywhere chrome:
        $html = inject_header($html);  // logo + structured menu + search
        $html = inject_footer($html);  // product links + company + socials + copyright
        $html = inject_chrome($html);  // floating contact bar
    }

    // Home: drive the "Featured Product" grid from the chosen featured categories.
    if ($slug === 'home' && function_exists('App\\sanyuan_inject_featured')) {
        $html = sanyuan_inject_featured($html);
    }

    // Home + /news/: drive the "News & Events" lists from the `sy_news` posts.
    if (function_exists('App\\sanyuan_inject_news_for')) {
        $html = sanyuan_inject_news_for($slug, $html);
    }

    if ($slug === 'contact' && function_exists('App\\sanyuan_inject_contact_form')) {
        $html = sanyuan_inject_contact_form($html, $pid);
    }

    $html = sanyuan_apply_static_mirror_loops($slug, $html);

    if ($slug === 'home' && $pid > 0 && function_exists('App\\sanyuan_inject_home_static_loops')) {
        $html = sanyuan_inject_home_static_loops($html, $pid);
    }

    if (function_exists(__NAMESPACE__ . '\\is_default_lang') && ! is_default_lang()) {
        $html = mirror_strip_cn_placeholders($html);
    }

    return sanyuan_apply_wp_seo(sanyuan_finalize_links($html));
}

/**
 * Mirror pages with static e_loop-* card grids (ACF/mirror HTML, not live CMS APIs).
 * Disabling needjs stops /fwebapi/ refetch + singleRolling re-layout on /zh/.
 */
function sanyuan_static_mirror_loops(): array
{
    $certCss = '#c_static_001-1760411525813 .e_loop-15{visibility:visible!important;opacity:1!important}'
        . '#c_static_001-1760411525813 .e_loop-15 .p_list{display:flex!important;flex-wrap:nowrap!important;'
        . 'gap:10px;overflow-x:auto;align-items:center}'
        . '#c_static_001-1760411525813 .e_loop-15 .p_loopitem{flex:0 0 auto!important;max-width:none!important}';

    return [
        'home' => [
            [
                'section'   => 'c_static_001-1760411525813',
                'loop'      => 'e_loop-15',
                'extra_css' => $certCss,
            ],
            [
                'section' => 'c_static_001-1760425468148',
                'loop'    => 'e_loop-2',
                'cols'    => 3,
            ],
        ],
        'cable-lab-overview' => [[
            'section' => 'c_static_001_P_91516-17640333921610',
            'loop'    => 'e_loop-20',
            'cols'    => 5,
        ]],
    ];
}

/** Turn off the 300.cn list runtime inside one section loop block. */
function sanyuan_disable_section_loop_runtime(string $html, string $sectionId, string $loopClass): string
{
    [$a, $b] = section_bounds($html, $sectionId);
    if ($a === null || $b === null) {
        return $html;
    }

    $sec = substr($html, $a, $b - $a);
    $pat = '~(<div class="' . preg_quote($loopClass, '~') . '[^"]*"[^>]*\b)needjs="true"~';
    $sec = preg_replace($pat, '$1needjs="false"', $sec, 1) ?? $sec;
    $sec = preg_replace(
        '~<input type="hidden" name="_config" value="[^"]*"~',
        '<input type="hidden" name="_config" value=""',
        $sec,
        1
    ) ?? $sec;

    return substr($html, 0, $a) . $sec . substr($html, $b);
}

/** Static flex grid fallback when CMS list JS is disabled. */
function sanyuan_inject_section_loop_layout_css(
    string $html,
    string $sectionId,
    string $loopClass,
    string $markerId,
    int $pcColumns = 0
): string {
    if (str_contains($html, 'id="' . $markerId . '"')) {
        return $html;
    }

    $css = '#' . $sectionId . ' .' . $loopClass . '{visibility:visible!important;opacity:1!important}'
        . '#' . $sectionId . ' .' . $loopClass . ' .p_list{display:flex!important;flex-wrap:wrap!important}';

    if ($pcColumns > 0) {
        $pct = (string) round(100 / $pcColumns, 4);
        $css .= '@media screen and (min-width:769px){#' . $sectionId . ' .' . $loopClass
            . ' .p_loopitem{flex:0 0 ' . $pct . '%!important;max-width:' . $pct
            . '%;min-width:0;box-sizing:border-box}}';
        $css .= '@media screen and (max-width:768px){#' . $sectionId . ' .' . $loopClass
            . ' .p_loopitem{flex:0 0 100%!important;max-width:100%;min-width:0}}';
        // Narrow flex columns + long ZH copy: allow shrink + wrap inside grey cards.
        $css .= '#' . $sectionId . ' .' . $loopClass . ' .p_loopitem .e_container-25,'
            . '#' . $sectionId . ' .' . $loopClass . ' .p_loopitem .e_container-21,'
            . '#' . $sectionId . ' .' . $loopClass . ' .p_loopitem .cbox-21-0'
            . '{min-width:0;max-width:100%;box-sizing:border-box}'
            . '#' . $sectionId . ' .' . $loopClass . ' .e_container-21{width:100%!important}'
            . '#' . $sectionId . ' .' . $loopClass . ' .e_container-21{padding-left:20px!important;padding-right:20px!important}'
            . '#' . $sectionId . ' .' . $loopClass . ' .e_text-37,'
            . '#' . $sectionId . ' .' . $loopClass . ' .e_text-23'
            . '{word-break:break-word;overflow-wrap:break-word;max-width:100%}';
    }

    return str_replace(
        '</head>',
        '<style id="' . $markerId . '">' . $css . '</style></head>',
        $html
    );
}

function sanyuan_apply_static_mirror_loops(string $slug, string $html): string
{
    foreach (sanyuan_static_mirror_loops()[$slug] ?? [] as $cfg) {
        $section = (string) ($cfg['section'] ?? '');
        $loop    = (string) ($cfg['loop'] ?? '');
        if ($section === '' || $loop === '') {
            continue;
        }
        $marker = 'sanyuan-loop-' . preg_replace('/[^a-zA-Z0-9_-]/', '', $section);
        $html   = sanyuan_disable_section_loop_runtime($html, $section, $loop);
        $html   = sanyuan_inject_section_loop_layout_css(
            $html,
            $section,
            $loop,
            $marker,
            (int) ($cfg['cols'] ?? 0)
        );
        $extraCss = (string) ($cfg['extra_css'] ?? '');
        if ($extraCss !== '') {
            $html = sanyuan_inject_head_style($html, $extraCss, $marker . '-extra');
        }
    }

    return $html;
}

/** Bounds [start, end] of a full <div id="…">…</div> section (nesting-aware).
 *  Returns [null, null] if not found. */
function section_bounds(string $html, string $id): array
{
    $a = strpos($html, '<div id="' . $id . '"');
    if ($a === false) {
        return [null, null];
    }
    $tagEnd = strpos($html, '>', $a);
    if ($tagEnd === false) {
        return [null, null];
    }
    $depth = 1;
    $k = $tagEnd + 1;
    $len = strlen($html);
    while ($k < $len) {
        $o = strpos($html, '<div', $k);
        $c = strpos($html, '</div>', $k);
        if ($c === false) {
            return [$a, null];
        }
        if ($o !== false && $o < $c) {
            $depth++;
            $k = $o + 4;
        } else {
            $depth--;
            if ($depth === 0) {
                return [$a, $c + 6];
            }
            $k = $c + 6;
        }
    }
    return [$a, null];
}

/** Strip CN mirror placeholders that are not backed by ACF fields. */
function mirror_strip_cn_placeholders(string $html): string
{
    $html = preg_replace(
        '~<p class="e_text-7[^"]*"[^>]*>\s*这里是占位文字\s*</p>~u',
        '',
        $html
    ) ?? $html;
    $html = preg_replace(
        '~<p class="e_text-3[12][^"]*"[^>]*>\s*</p>~u',
        '',
        $html
    ) ?? $html;

    return $html;
}

/** Replace the Nth rich-text or text node inside one mirror section. */
function sanyuan_replace_section_node(
    string $html,
    string $sectionId,
    string $kind,
    int $index,
    string $replacement
): string {
    [$a, $b] = section_bounds($html, $sectionId);
    if ($a === null || $b === null) {
        return $html;
    }

    $sec = substr($html, $a, $b - $a);
    $pattern = $kind === 'wysiwyg'
        ? '~(<div class="e_richText[^"]*"[^>]*>)\s*(.*?)(\s*</div>)~s'
        : '~(<p class="e_text[^"]*"[^>]*>)\s*(.*?)(\s*</p>)~s';

    $i = 0;
    $newSec = preg_replace_callback(
        $pattern,
        static function (array $m) use (&$i, $index, $replacement): string {
            if ($i++ !== $index) {
                return $m[0];
            }

            return $m[1] . $replacement . $m[3];
        },
        $sec
    ) ?? $sec;

    return substr($html, 0, $a) . $newSec . substr($html, $b);
}

/** Replace the Nth <img src> inside one mirror section; empty URL hides the slot. */
function sanyuan_replace_section_image(string $html, string $sectionId, int $index, string $url): string
{
    [$a, $b] = section_bounds($html, $sectionId);
    if ($a === null || $b === null) {
        return $html;
    }

    $sec = substr($html, $a, $b - $a);
    $i   = 0;
    $newSec = preg_replace_callback(
        '~(<img\b[^>]*\bsrc=")([^"]*)(")~i',
        static function (array $m) use (&$i, $index, $url): string {
            if ($i++ !== $index) {
                return $m[0];
            }
            if ($url !== '') {
                return $m[1] . esc_url($url) . $m[3];
            }
            $tag = $m[0];
            if (preg_match('~\bstyle="([^"]*)"~i', $tag, $sm)) {
                $style = rtrim($sm[1], ';') . ';display:none!important;visibility:hidden!important';

                return preg_replace('~\bstyle="[^"]*"~i', 'style="' . $style . '"', $tag, 1) ?? $tag;
            }

            return preg_replace('~<img\b~i', '<img style="display:none!important;visibility:hidden!important"', $tag, 1) ?? $tag;
        },
        $sec
    ) ?? $sec;

    return substr($html, 0, $a) . $newSec . substr($html, $b);
}

/**
 * Inject ACF onto non-default-language mirrors by section node index (EN JSON
 * originals do not exist in the ZH HTML file).
 */
function sanyuan_inject_lang_mirror_fields(string $html, int $pid, string $slug): string
{
    if (function_exists(__NAMESPACE__ . '\\is_default_lang') && is_default_lang()) {
        return $html;
    }

    $files = sanyuan_page_files();
    $file  = $files[$slug] ?? '';
    if ($file === '') {
        return $html;
    }

    $enPath = get_theme_file_path('public/site/' . $file);
    if (! is_readable($enPath)) {
        return $html;
    }

    $enHtml = (string) file_get_contents($enPath);
    $bySec  = [];
    foreach (page_fields_data($slug) as $field) {
        $section = (string) ($field['section'] ?? '');
        $key     = (string) ($field['key'] ?? '');
        if ($section === '' || $key === '') {
            continue;
        }
        $bySec[$section][] = $field;
    }

    $seedFile = get_theme_file_path('app/seed-mirror-zh-acf.php');
    if (is_readable($seedFile)) {
        require_once $seedFile;
    }

    foreach ($bySec as $sectionId => $fields) {
        [$enA, $enB] = section_bounds($enHtml, $sectionId);
        [$zhA, $zhB] = section_bounds($html, $sectionId);
        if ($enA === null || $enB === null || $zhA === null || $zhB === null) {
            continue;
        }

        $enSec = substr($enHtml, $enA, $enB - $enA);
        if (! function_exists('seed_zh_section_nodes')) {
            continue;
        }
        [$enRich, $enText] = seed_zh_section_nodes($enSec);

        foreach ($fields as $field) {
            $key  = (string) ($field['key'] ?? '');
            $orig = (string) ($field['original'] ?? '');
            $type = (string) ($field['type'] ?? 'text');
            if ($key === '' || $orig === '') {
                continue;
            }

            if ($type === 'image') {
                $idx = function_exists('seed_zh_match_image_index')
                    ? seed_zh_match_image_index($enSec, $orig)
                    : -1;
                if ($idx < 0) {
                    continue;
                }
                $url = sanyuan_acf_image_url(get_field($key, $pid));
                $html = sanyuan_replace_section_image($html, $sectionId, $idx, $url);
                continue;
            }

            $raw = get_field($key, $pid);
            $value = is_string($raw) && $raw !== ''
                ? (string) sanyuan_normalize_field_value($raw, $type)
                : '';

            if ($type === 'wysiwyg' && function_exists('seed_zh_match_richtext_index')) {
                $idx = seed_zh_match_richtext_index($enRich, $orig);
                if ($idx >= 0) {
                    $inner = $value !== '' && function_exists('seed_zh_format_wysiwyg')
                        ? seed_zh_format_wysiwyg($value)
                        : '';
                    $html = sanyuan_replace_section_node($html, $sectionId, 'wysiwyg', $idx, $inner);
                }
                continue;
            }

            if (function_exists('seed_zh_match_text_index')) {
                $idx = seed_zh_match_text_index($enText, $orig);
                if ($idx >= 0) {
                    $inner = $value !== '' && function_exists('seed_zh_format_text')
                        ? seed_zh_format_text($value)
                        : '';
                    $html = sanyuan_replace_section_node($html, $sectionId, 'text', $idx, $inner);
                }
            }
        }
    }

    return $html;
}

/** Replace the inner HTML of the first element matching <tag ... class="…cls…">
 *  (nesting-aware). Used for unique footer text elements. */
function replace_el_inner(string $html, string $tag, string $cls, string $newInner): string
{
    if (! preg_match('~<' . $tag . '\b[^>]*class="[^"]*' . preg_quote($cls, '~') . '[^"]*"[^>]*>~', $html, $m, PREG_OFFSET_CAPTURE)) {
        return $html;
    }
    $st = $m[0][1] + strlen($m[0][0]);
    $depth = 1;
    $k = $st;
    $len = strlen($html);
    $open = '<' . $tag;
    $close = '</' . $tag . '>';
    while ($k < $len) {
        $o = strpos($html, $open, $k);
        $c = strpos($html, $close, $k);
        if ($c === false) {
            return $html;
        }
        if ($o !== false && $o < $c) {
            $depth++;
            $k = $o + strlen($open);
        } else {
            $depth--;
            if ($depth === 0) {
                return substr($html, 0, $st) . $newInner . substr($html, $c);
            }
            $k = $c + strlen($close);
        }
    }
    return $html;
}

/**
 * Inject the structured Footer (ACF Options "Footer"): company info, the product
 * link list (rendered into the e_loop-40 template), socials (into the share
 * widget) and copyright. Each part applied only when its field is set.
 */
function inject_footer(string $html): string
{
    // Scope ALL footer edits to the footer section only — the page also has a
    // header product mega-menu that reuses the same e_loop-40 / p_list / cbox-40
    // classes, so whole-page operations would hit the wrong list. Match the
    // footer's own <div>…</div> bounds (order-independent).
    [$i, $j] = section_bounds($html, 'c_static_001-17631106276690');
    if ($i === null || $j === null) {
        return $html;
    }
    $foot = substr($html, $i, $j - $i);

    $company = get_field('footer_company', 'option');
    if (is_string($company) && $company !== '') {
        $foot = replace_el_inner($foot, 'p', 'e_text-43', esc_html($company));
    } else {
        $foot = replace_el_inner($foot, 'p', 'e_text-43', '');
    }
    $address = get_field('footer_address', 'option');
    if (is_string($address) && $address !== '') {
        $foot = replace_el_inner($foot, 'p', 'e_text-44', esc_html($address));
    } else {
        $foot = replace_el_inner($foot, 'p', 'e_text-44', '');
    }
    // Email appears in both the mailto href and the visible text → swap string.
    $email = get_field('footer_email', 'option');
    if (is_string($email) && $email !== '') {
        $foot = str_replace('info@sanyuancable.com.cn', $email, $foot);
    } else {
        $foot = str_replace('info@sanyuancable.com.cn', '', $foot);
    }
    $copy = get_field('footer_copyright', 'option');
    if (is_string($copy) && $copy !== '') {
        $foot = replace_el_inner($foot, 'div', 'e_richText-34', $copy);
    } else {
        $foot = replace_el_inner($foot, 'div', 'e_richText-34', '');
    }

    // Product links → rebuild the p_list inside e_loop-40 (footer-scoped).
    $prods = get_field('footer_products', 'option');
    if (is_array($prods) && $prods) {
        $items = '';
        foreach ($prods as $p) {
            $label = isset($p['label']) ? esc_html((string) $p['label']) : '';
            $link  = (isset($p['link']) && is_string($p['link'])) ? $p['link'] : '';
            $items .= '<div class="cbox-40 p_loopitem"><p class="e_text-42 s_link">'
                    . '<a href="' . esc_attr($link) . '" target="_self">' . $label . '</a></p></div>';
        }
        $foot = replace_loop_plist($foot, 'e_loop-40', $items);
    } else {
        $foot = replace_loop_plist($foot, 'e_loop-40', '');
    }

    // Regional contacts → rebuild the p_list inside e_loop-59 (footer-scoped).
    $regions = get_field('footer_regions', 'option');
    if (is_array($regions) && $regions) {
        $icon = region_icon_svg();
        $items = '';
        foreach ($regions as $r) {
            $name = isset($r['name']) ? esc_html((string) $r['name']) : '';
            $link = (isset($r['link']) && is_string($r['link']) && $r['link'] !== '') ? $r['link'] : '/contact/';
            $items .= '<div class="cbox-59 p_loopitem"><div class="e_container-60 s_layout">'
                    . '<div class="cbox-60-0 p_item"><div class="e_container-66 s_layout">'
                    . '<div class="cbox-66-0 p_item"><p class="e_text-68 s_title">'
                    . '<a href="' . esc_attr($link) . '" target="_self">+</a></p></div>'
                    . '</div><div class="e_icon-63 s_title">' . $icon . '</div>'
                    . '<p class="e_text-64 s_title2">' . $name . '</p></div></div></div>';
        }
        $foot = replace_loop_plist($foot, 'e_loop-59', $items);
    } else {
        $foot = replace_loop_plist($foot, 'e_loop-59', '');
    }

    // Socials → rebuild the share widget inner.
    $socials = get_field('footer_socials', 'option');
    if (is_array($socials) && $socials) {
        $items = '';
        foreach ($socials as $sc) {
            $title = isset($sc['title']) ? (string) $sc['title'] : '';
            $url   = (isset($sc['url']) && is_string($sc['url'])) ? $sc['url'] : '';
            $icon  = (isset($sc['icon']) && is_string($sc['icon'])) ? $sc['icon'] : '';
            if ($icon === '') {
                continue;
            }
            $items .= '<a class="p_shareItem" title="' . esc_attr($title) . '" key="custom" href="'
                    . esc_url($url) . '" target="_blank"><img alt="' . esc_attr($title)
                    . '" class="p_img" src="' . esc_url($icon) . '" la="la"></a>';
        }
        $foot = replace_el_inner($foot, 'div', 'p_share horizontal', $items);
    } else {
        $foot = replace_el_inner($foot, 'div', 'p_share horizontal', '');
    }

    if (function_exists('App\\sanyuan_inject_footer_products_cta')) {
        $foot = sanyuan_inject_footer_products_cta($foot);
    }

    return substr($html, 0, $i) . $foot . substr($html, $j);
}

/** The globe SVG icon shown next to each regional contact (cached from file). */
function region_icon_svg(): string
{
    static $svg = null;
    if ($svg === null) {
        $f = get_theme_file_path('app/page-fields/_region-icon.svg');
        $svg = is_readable($f) ? file_get_contents($f) : '';
    }
    return $svg;
}

/** Replace the <div class="p_list"> inner that sits inside a given e_loop-*. */
function replace_loop_plist(string $html, string $loopClass, string $newInner): string
{
    $lp = strpos($html, $loopClass);
    if ($lp === false) {
        return $html;
    }
    $pl = strpos($html, '<div class="p_list">', $lp);
    if ($pl === false) {
        return $html;
    }
    $st = $pl + strlen('<div class="p_list">');
    $depth = 1;
    $k = $st;
    $len = strlen($html);
    while ($k < $len) {
        $o = strpos($html, '<div', $k);
        $c = strpos($html, '</div>', $k);
        if ($c === false) {
            return $html;
        }
        if ($o !== false && $o < $c) {
            $depth++;
            $k = $o + 4;
        } else {
            $depth--;
            if ($depth === 0) {
                return substr($html, 0, $st) . $newInner . substr($html, $c);
            }
            $k = $c + 6;
        }
    }
    return $html;
}

/** Replace p_list inner inside one section + e_loop-* block (avoids hitting the wrong loop on-page). */
function sanyuan_replace_section_loop_plist(
    string $html,
    string $sectionId,
    string $loopClass,
    string $newInner
): string {
    [$a, $b] = section_bounds($html, $sectionId);
    if ($a === null || $b === null) {
        return $html;
    }

    $sec = substr($html, $a, $b - $a);
    $out = replace_loop_plist($sec, $loopClass, $newInner);
    if ($out === $sec) {
        return $html;
    }

    return substr($html, 0, $a) . $out . substr($html, $b);
}

/** The original dropdown-arrow SVG shown on menu items that have a submenu. */
function header_menu_arrow(): string
{
    return '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="13" height="8" viewBox="0 0 13 8" fill="none"><path d="M6.50579 6.48788L0.941856 0.182718C0.726492 -0.0609053 0.377165 -0.0609053 0.161521 0.182718C-0.0538402 0.427149 -0.0538402 0.822988 0.161521 1.06737L6.0717 7.76405C6.08434 7.78232 6.09828 7.80021 6.11312 7.81696C6.32876 8.06101 6.67807 8.06101 6.89345 7.81696L12.8383 1.08031C13.0539 0.835942 13.0539 0.440103 12.8383 0.196051C12.6232 -0.047951 12.2739 -0.047951 12.0583 0.196051L6.50579 6.48788Z" fill="#FFFFFF"></path></svg>';
}

/** Render the menu repeater rows back into the ORIGINAL <ul class="p_level1Box">
 *  template (same classes/structure → original CSS + JS keep working). */
function build_header_menu(array $rows): string
{
    $out = '<ul class="p_level1Box">';
    foreach ($rows as $r) {
        $label = isset($r['label']) ? esc_html((string) $r['label']) : '';
        $href  = (isset($r['link']) && is_string($r['link'])) ? $r['link'] : '';
        $kids  = (isset($r['children']) && is_array($r['children'])) ? $r['children'] : [];
        $out .= '<li class="p_level1Item"><p class="p_menu1Item js_editor_click">'
              . '<a href="' . esc_url($href) . '" target=""><span>' . $label . '</span></a>'
              . ($kids ? header_menu_arrow() : '') . '</p>';
        if ($kids) {
            $out .= '<ul class="p_level2Box">';
            foreach ($kids as $c) {
                $cl = isset($c['label']) ? esc_html((string) $c['label']) : '';
                $ch = (isset($c['link']) && is_string($c['link'])) ? $c['link'] : '';
                $out .= '<li class="p_level2Item"><p class="p_menu2Item js_editor_click">'
                      . '<a href="' . esc_url($ch) . '" target="" ><span>' . $cl . '</span></a></p></li>';
            }
            $out .= '</ul>';
        }
        $out .= '</li>';
    }
    return $out . '</ul>';
}

/** Replace the original <ul class="p_level1Box"> … </ul> block (matched with
 *  nesting) with freshly-built menu markup. */
function replace_level1box(string $html, string $new): string
{
    $open = '<ul class="p_level1Box">';
    $i = strpos($html, $open);
    if ($i === false) {
        return $html;
    }
    $depth = 0;
    $k = $i;
    $len = strlen($html);
    while ($k < $len) {
        $o = strpos($html, '<ul', $k);
        $c = strpos($html, '</ul>', $k);
        if ($c === false) {
            return $html;
        }
        if ($o !== false && $o < $c) {
            $depth++;
            $k = $o + 3;
        } else {
            $depth--;
            if ($depth === 0) {
                return substr($html, 0, $i) . $new . substr($html, $c + 5);
            }
            $k = $c + 5;
        }
    }
    return $html;
}

/** Shared nav block id (mother-set header) — used in mirror CSS + mobile overrides. */
const SANYUAN_NAV_ID = 'c_navigation_146_P_531-17631105980710';

/**
 * Mirror mobile CSS hides .cbox-14-2 (.lan) and swaps to the white logo (2nd <img>).
 * When the sticky logo ACF is empty we hide that 2nd img — leaving no logo on mobile.
 */
function sanyuan_header_mobile_fix_css(bool $hasWhiteLogo): string
{
    $nav = '#' . SANYUAN_NAV_ID;
    $css = '@media screen and (max-width:768px){'
        . $nav . ' .e_container-14 .cbox-14-2{display:flex!important;position:absolute;right:60px;z-index:6;align-self:center}'
        . $nav . ' .lan{margin-right:0;width:auto;min-width:72px}'
        . '}';

    if ($hasWhiteLogo) {
        return $css;
    }

    $showMain = $nav . ' .e_image-15 img:nth-child(1){display:block!important;visibility:visible!important}';
    $hideWhite = $nav . ' .e_image-15 img:nth-child(2){display:none!important;visibility:hidden!important}';

    return $css
        . '@media screen and (max-width:768px){' . $showMain . $hideWhite . '}'
        . $nav . '.active ' . ltrim($showMain, $nav . ' ') . $nav . '.active ' . ltrim($hideWhite, $nav . ' ')
        . $nav . '.subpage ' . ltrim($showMain, $nav . ' ') . $nav . '.subpage ' . ltrim($hideWhite, $nav . ' ');
}

/**
 * Inject the structured Header (ACF Options "Header"): logo images, the menu
 * (rendered from the repeater into the original template), and the search box.
 * Empty option fields clear the matching mirror chrome (no static fallback).
 */
function inject_header(string $html): string
{
    $logo = shared_option_field('header_logo');
    if (is_string($logo) && $logo !== '') {
        $html = sanyuan_replace_mirror_logo_refs($html, $logo, '07104aab-2576-4c47-b760-26055b6ead50');
    } else {
        $html = sanyuan_hide_mirror_logo_refs($html, '07104aab-2576-4c47-b760-26055b6ead50');
    }

    $logoW = shared_option_field('header_logo_white');
    if (is_string($logoW) && $logoW !== '') {
        $html = sanyuan_replace_mirror_logo_refs($html, $logoW, 'b2c4607a-6ea5-4cae-8681-b6a07fe61250');
    } else {
        $html = sanyuan_hide_mirror_logo_refs($html, 'b2c4607a-6ea5-4cae-8681-b6a07fe61250');
    }

    // Replace the dead header search box with a native GET form to the
    // WooCommerce product search (/?s=…). The original markup (id="h_search" +
    // <a href="SEARCH.html">) is wired by the 300.cn runtime to a broken static
    // search page; dropping that id + link stops the runtime hijacking it, and a
    // real <form> submits reliably (Enter or click) regardless of the runtime.
    $ph = get_field('header_search_placeholder', 'option');
    $placeholder = (is_string($ph) && $ph !== '') ? $ph : '';
    $icon = '<svg t="1760434112876" class="icon" viewBox="0 0 1024 1024" version="1.1" xmlns="http://www.w3.org/2000/svg" p-id="4507"><path d="M512 858.3168c-194.816 0-352-166.2464-352-370.4832S317.184 117.3504 512 117.3504s352 166.2464 352 370.4832-157.184 370.4832-352 370.4832z m0-64c158.6688 0 288-136.8576 288-306.4832 0-169.6768-129.3312-306.4832-288-306.4832S224 318.1568 224 487.8336c0 169.6256 129.3312 306.4832 288 306.4832zM717.312 799.9488a32 32 0 0 1 46.4896-43.9808l91.4432 96.7168a32 32 0 0 1-46.4896 43.9808l-91.4432-96.768z" p-id="4508"></path></svg>';
    $form = '<div class="nav-search h_search">'
        . '<form action="/" method="get" role="search" style="display:flex;align-items:center;width:100%;height:100%;margin:0;gap:6px">'
        . '<input type="text" name="s" value="" placeholder="' . esc_attr($placeholder) . '" autocomplete="off" '
        . 'style="flex:1;min-width:0;border:0;background:transparent;outline:none;font-size:14px;color:#333;height:100%" />'
        . '<a class="seabtn" href="#" aria-label="Tìm kiếm" '
        . "onclick=\"var i=this.closest('form').querySelector('[name=s]');if((i.value||'').trim()){this.closest('form').submit();}return false;\">"
        . $icon . '</a></form></div>';
    $html = preg_replace('~<div class="nav-search h_search">.*?</div>~s', $form, $html, 1);

    if (get_field('header_search_show', 'option') === false) {
        $html = str_replace('</head>', '<style>.nav-search,.h_search{display:none!important}</style></head>', $html);
    }

    $rows = get_field('header_menu', 'option');
    if (is_array($rows) && $rows) {
        $html = replace_level1box($html, build_header_menu($rows));
    } else {
        $html = replace_level1box($html, '<ul class="p_level1Box"></ul>');
    }

    // Đa ngôn ngữ: chèn nút chuyển ngôn ngữ (EN | 中文). No-op nếu chỉ 1 ngôn ngữ.
    if (function_exists('App\\inject_lang_switch')) {
        $html = inject_lang_switch($html);
    }

    $hasWhiteLogo = is_string($logoW) && $logoW !== '';
    $html = sanyuan_inject_head_style($html, sanyuan_header_mobile_fix_css($hasWhiteLogo), 'sanyuan-header-mobile-fix');

    return $html;
}

/**
 * Inject the shared Header/Footer fragments (edited once on the "Header & Footer"
 * ACF Options page) into a page's markup. Same fragment-swap rules as
 * sanyuan_inject_fields, but values are read from the 'option' store so a single
 * edit applies to every page.
 */
function chrome_fields_supplement_meta(): array
{
    return [
        [
            'key'                => 'chrome_img_2',
            'type'               => 'image',
            'inject'             => 'css_bg',
            'css_selector'       => '#c_static_001-17641411302650',
            'original'           => '../../assets_img/a799d334-12c4-4561-aa6b-057ac83c8351_c49828.png',
            'label'              => 'Banner background image',
            'section'            => 'c_static_001-17641411302650',
        ],
        [
            'key'                => 'chrome_text_2',
            'type'               => 'text',
            'original'           => 'Contact with us',
            'label'              => 'Contact with us',
            'css_after_selector' => '#c_static_001-17641411302650 .e_button-7::after',
            'section'            => 'c_static_001-17641411302650',
        ],
        [
            'key'      => 'chrome_link_1',
            'type'     => 'url',
            'original' => 'concactd41d.html?#c_static_001-1762837518486',
            'label'    => 'Contact button link',
            'section'  => 'c_static_001-17641411302650',
        ],
    ];
}

/** Merge _chrome.json with supplement fields (deduped by key). */
function chrome_fields_merged(): array
{
    $base = chrome_fields_data();
    $seen = array_flip(array_column($base, 'key'));
    foreach (chrome_fields_supplement_meta() as $f) {
        $k = $f['key'] ?? '';
        if ($k !== '' && ! isset($seen[$k])) {
            $base[] = $f;
            $seen[$k] = true;
        }
    }

    return $base;
}

/** Gỡ whitespace thừa từ fragment HTML gốc (vd "\n        We are here for you\n"). */
function sanyuan_normalize_field_value(mixed $value, ?string $type = null): mixed
{
    if (! is_string($value) || $value === '') {
        return $value;
    }
    if ($type === 'wysiwyg' || $type === 'textarea') {
        return trim($value);
    }
    $t = html_entity_decode(wp_strip_all_tags($value), ENT_QUOTES, 'UTF-8');

    return trim(preg_replace('/\s+/u', ' ', $t));
}

/** Field ACF nội dung mirror (page / chrome / header-footer) cần chuẩn hóa whitespace. */
function sanyuan_acf_should_normalize(array $field): bool
{
    $type = $field['type'] ?? '';
    if (! in_array($type, ['text', 'textarea', 'wysiwyg'], true)) {
        return false;
    }
    $name = (string) ($field['name'] ?? '');
    if ($name === '' || str_starts_with($name, 'show_')) {
        return false;
    }
    if (preg_match('/^(chrome|[a-z0-9_-]+)_text_\d+$/', $name)) {
        return true;
    }
    static $options = [
        'header_search_placeholder',
        'footer_company',
        'footer_address',
        'footer_copyright',
        'footer_email',
    ];

    return in_array($name, $options, true);
}

function inject_chrome(string $html): string
{
    $cssExtra = '';

    foreach (chrome_fields_merged() as $f) {
        $key  = $f['key'] ?? '';
        $orig = (string) ($f['original'] ?? '');
        if ($key === '' || $orig === '') {
            continue;
        }

        $value = get_field($key, 'option');

        if (($f['type'] ?? '') === 'image') {
            $file = basename($orig);
            if ($file === '') {
                continue;
            }
            $url    = sanyuan_acf_image_url($value);
            if ($url === '') {
                if (($f['inject'] ?? '') === 'css_bg') {
                    $sel = (string) ($f['css_selector'] ?? '#c_static_001-17641411302650');
                    $cssExtra .= $sel . '{background-image:none!important;}';
                } else {
                    $html = sanyuan_hide_mirror_images_with_basename($html, $file);
                }
                continue;
            }
            if (($f['inject'] ?? '') === 'css_bg') {
                $sel = (string) ($f['css_selector'] ?? '#c_static_001-17641411302650');
                $cssExtra .= $sel . '{background-image:url(' . esc_url($url) . ') !important;}';
                continue;
            }
            $html = preg_replace('~(?:\.\./)*assets_img/' . preg_quote($file, '~') . '~', $url, $html);
            continue;
        }

        if (($f['type'] ?? '') === 'url') {
            if (is_string($value) && $value !== '') {
                $html = str_replace('href="' . $orig . '"', 'href="' . esc_url($value) . '"', $html);
            } elseif (strpos($html, 'href="' . $orig . '"') !== false) {
                $html = str_replace('href="' . $orig . '"', 'href="#"', $html);
            }
            continue;
        }

        $afterSel = (string) ($f['css_after_selector'] ?? '');
        if (is_string($value) && $value !== '') {
            $value = (string) sanyuan_normalize_field_value($value, $f['type'] ?? 'text');
            if ($afterSel !== '') {
                $quoted = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
                $cssExtra .= $afterSel . '{content:"' . $quoted . '" !important;}';
            }
            if (strpos($html, $orig) !== false) {
                $html = str_replace($orig, $value, $html);
            }
        } elseif (($value === null || $value === '') && strpos($html, $orig) !== false) {
            // Field trống → gỡ fragment gốc khỏi mirror (không fallback nội dung cứng).
            $html = str_replace($orig, '', $html);
            if ($afterSel !== '') {
                $cssExtra .= $afterSel . '{content:"" !important;}';
            }
        }
    }

    // Header/Footer "Ẩn / Hiện" switches + CSS overrides (banner bg, button hover).
    $inlineCss = $cssExtra;
    foreach (array_keys(chrome_section_labels()) as $sid) {
        $show = get_field('show_' . $sid, 'option');
        if ($show === false || $show === 0 || $show === '0') {
            $inlineCss .= '#' . $sid . '{display:none!important}';
        }
    }
    if ($inlineCss !== '') {
        $html = str_replace('</head>', '<style>' . $inlineCss . '</style></head>', $html);
    }

    return $html;
}

/**
 * Replace a page's original fragments with ACF field values, in place. Each
 * entry in the page's JSON (app/page-fields/<slug>.json, or app/about-fields.json
 * for About) maps a field key to its exact original text/image fragment. A
 * fragment is swapped when its field has a value; empty fields clear the mirror
 * fragment (no static fallback). Also applies the per-section "Ẩn / Hiện" switches.
 */
function sanyuan_inject_fields(string $html, int $pid, string $slug): string
{
    $skipNewsList = $slug === 'news'
        ? array_flip(sanyuan_news_list_section_field_names())
        : [];

    foreach (page_fields_data($slug) as $f) {
        $key  = $f['key'] ?? '';
        $orig = (string) ($f['original'] ?? '');
        if ($key === '' || $orig === '') {
            continue;
        }
        // /news/ card grid comes from blog posts — never strip mirror card fragments here.
        if (isset($skipNewsList[$key])) {
            continue;
        }

        $value = get_field($key, $pid);

        if (($f['type'] ?? '') === 'image') {
            $file = basename($orig);
            if ($file === '') {
                continue;
            }
            $url = sanyuan_acf_image_url($value);
            if ($url === '') {
                $html = sanyuan_hide_mirror_images_with_basename($html, $file);
                continue;
            }
            $html = preg_replace(
                '~(?:\.\./)*assets_img/' . preg_quote($file, '~') . '~',
                $url,
                $html
            );
            continue;
        }

        // Text / raw-HTML fragment: exact string swap (no-op if value === orig).
        if (is_string($value) && $value !== '') {
            $value = (string) sanyuan_normalize_field_value($value, $f['type'] ?? 'text');
            if (strpos($html, $orig) !== false) {
                $html = str_replace($orig, $value, $html);
            }
        } elseif (($value === null || $value === '') && strpos($html, $orig) !== false) {
            $html = str_replace($orig, '', $html);
        }
    }

    // "Ẩn / Hiện": hide any section whose toggle is off.
    $hide = '';
    foreach (array_keys(page_section_labels($slug)) as $sid) {
        $show = get_field('show_' . $sid, $pid);
        if ($show === false || $show === 0 || $show === '0') {
            $hide .= '#' . $sid . '{display:none!important}';
        }
    }
    if ($hide !== '') {
        $html = str_replace('</head>', '<style>' . $hide . '</style></head>', $html);
    }

    return $html;
}

add_action('template_redirect', function () {
    // Front page → the WordPress "Home" Page → mirror index.html (+ ACF inject).
    if (is_front_page()) {
        nocache_headers();
        echo render_managed_page('home', 'index.html');
        exit;
    }

    // Other WordPress Pages → their mapped mirror document (clean URL, dynamic),
    // with ACF fragments injected. Each page looks identical to the original
    // until an editor fills a field in wp-admin.
    if (is_page()) {
        // Đa ngôn ngữ: resolve mirror slug from any translation (contact vs contact-2).
        $qid   = get_queried_object_id();
        $slug  = sanyuan_managed_mirror_slug((int) $qid);
        $files = sanyuan_page_files();
        if (isset($files[$slug]) && $slug !== 'home'
            && is_readable(get_theme_file_path('public/site/' . $files[$slug]))) {
            // Paginated managed pages (e.g. /news/page/2/) are not 404 when the
            // mirror list is filled server-side from the blog.
            if ($slug === 'news') {
                global $wp_query;
                if ($wp_query instanceof \WP_Query) {
                    $wp_query->is_404 = false;
                }
                status_header(200);
            }
            nocache_headers();
            echo render_managed_page($slug, $files[$slug]);
            exit;
        }
    }

    // Original .html URLs (the nav links in the mirror markup still point here).
    $path = trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');

    if ($path === '' || ! preg_match('/\.html$/i', $path)) {
        return;
    }

    if (! is_readable(get_theme_file_path('public/site/' . $path))) {
        return; // unknown page -> let WordPress 404
    }

    nocache_headers();
    echo sanyuan_apply_wp_seo(sanyuan_finalize_links(mirror_html($path)));
    exit;
}, 0);

/**
 * Render a mirror-styled product search results page. Uses a managed mirror page
 * as the shell (header + footer + chrome, all original look), replacing the
 * page content between the header and the shared footer/contact bar with a
 * server-rendered WooCommerce product grid (real WC images + clean permalinks).
 */
function render_search_results(string $kw): string
{
    $shell = mirror_html('concact.html');
    if ($shell === '') {
        return '';
    }
    if (function_exists('get_field')) {
        $shell = inject_header($shell);
        $shell = inject_footer($shell);
        $shell = inject_chrome($shell);
    }
    if (function_exists('sanyuan_finalize_links')) {
        $shell = sanyuan_finalize_links($shell);
    }

    // Build the results grid from WooCommerce products matching the keyword.
    $per   = 24;
    $paged = max(1, (int) ($_GET['paged'] ?? 1));
    $q = new \WP_Query([
        'post_type'      => 'product',
        'post_status'    => 'publish',
        's'              => $kw,
        'posts_per_page' => $per,
        'paged'          => $paged,
        'no_found_rows'  => false,
    ]);
    $cards = '';
    while ($q->have_posts()) {
        $q->the_post();
        $pid   = get_the_ID();
        $thumb = get_the_post_thumbnail_url($pid, 'medium');
        if (! $thumb && function_exists('wc_placeholder_img_src')) {
            $thumb = wc_placeholder_img_src('medium');
        }
        $cards .= '<a class="sy-sr__card" href="' . esc_url(get_permalink($pid)) . '">'
                . '<span class="sy-sr__thumb"><img src="' . esc_url((string) $thumb) . '" alt="'
                . esc_attr(get_the_title()) . '" loading="lazy"></span>'
                . '<span class="sy-sr__name">' . esc_html(get_the_title()) . '</span></a>';
    }
    $found = (int) $q->found_posts;
    $shown = (int) $q->post_count;
    $maxPg = (int) $q->max_num_pages;
    wp_reset_postdata();

    $countText = 'Tìm thấy ' . $found . ' sản phẩm';
    if ($found > 0 && $maxPg > 1) {
        $from = ($paged - 1) * $per + 1;
        $to   = $from + $shown - 1;
        $countText .= ' — hiển thị ' . $from . '–' . $to;
    }

    // Build the pager (windowed: first/last + current ±2, with ellipses).
    $pager = '';
    if ($maxPg > 1) {
        $url = function (int $n) use ($kw) {
            return esc_url('/?s=' . rawurlencode($kw) . '&paged=' . $n);
        };
        $link = function (int $n, string $label, bool $active = false, bool $disabled = false) use ($url) {
            if ($disabled) {
                return '<span class="sy-sr__pg is-disabled">' . $label . '</span>';
            }
            if ($active) {
                return '<span class="sy-sr__pg is-active">' . $label . '</span>';
            }
            return '<a class="sy-sr__pg" href="' . $url($n) . '">' . $label . '</a>';
        };
        $items = $link($paged - 1, '‹', false, $paged <= 1);
        $window = 2;
        $pages = [];
        for ($n = 1; $n <= $maxPg; $n++) {
            if ($n === 1 || $n === $maxPg || ($n >= $paged - $window && $n <= $paged + $window)) {
                $pages[] = $n;
            }
        }
        $prev = 0;
        foreach ($pages as $n) {
            if ($prev && $n - $prev > 1) {
                $items .= '<span class="sy-sr__pg is-gap">…</span>';
            }
            $items .= $link($n, (string) $n, $n === $paged);
            $prev = $n;
        }
        $items .= $link($paged + 1, '›', false, $paged >= $maxPg);
        $pager = '<nav class="sy-sr__pager" aria-label="Phân trang">' . $items . '</nav>';
    }

    $grid = $cards !== ''
        ? '<div class="sy-sr__grid">' . $cards . '</div>'
        : '<p class="sy-sr__empty">Không tìm thấy sản phẩm nào khớp với từ khoá của bạn.</p>';

    $block = '<style>'
        . '.sy-sr{max-width:1320px;margin:0 auto;padding:120px 24px 60px;min-height:55vh}'
        . '.sy-sr__head{margin:0 0 28px;border-bottom:1px solid #eee;padding-bottom:18px}'
        . '.sy-sr__head h1{font-size:30px;font-weight:800;color:#1d1d1d;margin:0 0 6px}'
        . '.sy-sr__head h1 b{color:#d40b1c}'
        . '.sy-sr__head p{color:#777;margin:0;font-size:15px}'
        . '.sy-sr__grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:24px}'
        . '.sy-sr__card{display:block;border:1px solid #eee;border-radius:10px;overflow:hidden;'
        . 'text-decoration:none;color:#1d1d1d;background:#fff;transition:box-shadow .2s,transform .2s}'
        . '.sy-sr__card:hover{box-shadow:0 12px 30px rgba(0,0,0,.12);transform:translateY(-3px)}'
        . '.sy-sr__thumb{display:block;aspect-ratio:580/420;background:#f6f6f6;overflow:hidden}'
        . '.sy-sr__thumb img{width:100%;height:100%;object-fit:cover;display:block}'
        . '.sy-sr__name{display:block;padding:14px 16px;font-size:15px;line-height:1.45;font-weight:600}'
        . '.sy-sr__empty{padding:48px 0;color:#888;font-size:18px}'
        . '.sy-sr__pager{display:flex;flex-wrap:wrap;gap:8px;justify-content:center;margin-top:44px}'
        . '.sy-sr__pg{min-width:42px;height:42px;padding:0 12px;display:inline-flex;align-items:center;'
        . 'justify-content:center;border:1px solid #e2e2e2;border-radius:8px;color:#1d1d1d;'
        . 'text-decoration:none;font-size:15px;font-weight:600;background:#fff;transition:all .15s}'
        . '.sy-sr__pg:hover{border-color:#d40b1c;color:#d40b1c}'
        . '.sy-sr__pg.is-active{background:#d40b1c;border-color:#d40b1c;color:#fff}'
        . '.sy-sr__pg.is-disabled{opacity:.4;pointer-events:none}'
        . '.sy-sr__pg.is-gap{border:0;pointer-events:none;font-weight:400;color:#999}'
        . '@media(max-width:600px){.sy-sr{padding:100px 16px 40px}.sy-sr__head h1{font-size:23px}}'
        . '</style>'
        . '<section class="sy-sr"><div class="sy-sr__head">'
        . '<h1>Kết quả tìm kiếm: <b>' . esc_html($kw) . '</b></h1>'
        . '<p>' . $countText . '</p></div>' . $grid . $pager . '</section>';

    // Swap the page content (first content section → shared footer/contact bar)
    // for the results block, keeping the header above and footer below intact.
    $cs = strpos($shell, '<div id="c_static_001-');
    $fc = strpos($shell, '<div id="c_static_001-17641411302650"');
    if ($cs !== false && $fc !== false && $cs < $fc) {
        $shell = substr($shell, 0, $cs) . $block . substr($shell, $fc);
    } else {
        $shell = preg_replace('#(<div id="c_static_001-17641411302650")#', $block . '$1', $shell, 1);
    }

    // Retitle via wp_head (Rank Math / title-tag) — mirror <title> already stripped.
    return sanyuan_apply_wp_seo($shell);
}

// Product search results — runs before WooCommerce "coming soon" (priority -1),
// so results render even while the store is in coming-soon mode.
add_action('template_redirect', function () {
    if (is_admin() || (defined('DOING_AJAX') && DOING_AJAX)) {
        return;
    }
    $s = isset($_GET['s']) ? trim((string) wp_unslash($_GET['s'])) : '';
    if ($s === '') {
        return;
    }
    nocache_headers();
    status_header(200); // a paged=N beyond range would otherwise inherit WP's 404
    echo render_search_results($s);
    exit;
}, -1);

// Thanh liên hệ nổi: bổ sung field + label trên input (tránh cột trái trống).
add_action('acf/init', function () {
    if (! function_exists('acf_is_local_field_group')
        || ! function_exists('acf_get_fields')
        || ! function_exists('acf_add_local_field_group')
        || ! acf_is_local_field_group('group_sanyuan_chrome')) {
        return;
    }

    $group = acf_get_local_field_group('group_sanyuan_chrome');
    $fields = acf_get_fields('group_sanyuan_chrome');
    if (! is_array($group) || ! is_array($fields)) {
        return;
    }

    $hasImg2 = (bool) array_filter($fields, fn ($f) => ($f['name'] ?? '') === 'chrome_img_2');
    $layoutOk = ($group['label_placement'] ?? '') === 'top';

    if ($hasImg2 && $layoutOk) {
        return;
    }

    $labels = [
        'chrome_img_2'  => 'Banner background image',
        'chrome_text_1' => 'Heading',
        'chrome_text_2' => 'Contact button text',
        'chrome_img_1'  => 'Contact button arrow icon',
        'chrome_link_1' => 'Contact button link',
    ];

    if (! $hasImg2) {
        $extra = [
            [
                'key'           => 'field_chrome_img_2',
                'name'          => 'chrome_img_2',
                'label'         => $labels['chrome_img_2'],
                'type'          => 'image',
                'return_format' => 'url',
                'preview_size'  => 'medium',
                'instructions'  => 'Left banner background.',
            ],
            [
                'key'          => 'field_chrome_text_2',
                'name'         => 'chrome_text_2',
                'label'        => $labels['chrome_text_2'],
                'type'         => 'text',
                'placeholder'  => 'Contact with us',
                'instructions' => 'Hover label shown beside the button.',
            ],
            [
                'key'          => 'field_chrome_link_1',
                'name'         => 'chrome_link_1',
                'label'        => $labels['chrome_link_1'],
                'type'         => 'url',
                'placeholder'  => 'concactd41d.html?#c_static_001-1762837518486',
                'instructions' => 'Link opened when the button is clicked.',
            ],
        ];

        $tabIdx = $t1Idx = $i1Idx = null;
        foreach ($fields as $i => $f) {
            if (($f['key'] ?? '') === 'field_tab_c_static_001-17641411302650') {
                $tabIdx = $i;
            }
            if (($f['name'] ?? '') === 'chrome_text_1') {
                $t1Idx = $i;
            }
            if (($f['name'] ?? '') === 'chrome_img_1') {
                $i1Idx = $i;
            }
        }
        if ($tabIdx !== null) {
            if ($i1Idx !== null) {
                array_splice($fields, $i1Idx + 1, 0, [$extra[2]]);
            }
            if ($t1Idx !== null) {
                array_splice($fields, $t1Idx + 1, 0, [$extra[1]]);
            }
            array_splice($fields, $tabIdx + 1, 0, [$extra[0]]);
        }
    }

    foreach ($fields as &$f) {
        $name = $f['name'] ?? '';
        if (! isset($labels[$name])) {
            continue;
        }
        $f['label'] = $labels[$name];
        if ($name === 'chrome_text_1') {
            $f['type'] = 'text';
            $f['placeholder'] = 'We are here for you';
            $f['instructions'] = 'Để trống = giữ nguyên bản gốc.';
            unset($f['rows']);
        }
        if ($name === 'chrome_img_1') {
            $f['instructions'] = 'Arrow icon inside the button.';
        }
    }
    unset($f);

    foreach (acf_get_fields('group_sanyuan_chrome') as $f) {
        if (! empty($f['key'])) {
            acf_remove_local_field($f['key']);
        }
    }
    acf_remove_local_field_group('group_sanyuan_chrome');

    $location = $group['location'] ?? [];
    if ($location === [] && defined('App\\CHROME_OPTIONS_SLUG')) {
        $location = [[['param' => 'options_page', 'operator' => '==', 'value' => CHROME_OPTIONS_SLUG]]];
    }

    acf_add_local_field_group([
        'key'             => 'group_sanyuan_chrome',
        'title'           => $group['title'] ?? 'Floating contact bar — site-wide',
        'fields'          => $fields,
        'location'        => $location,
        'menu_order'      => $group['menu_order'] ?? 10,
        'style'           => $group['style'] ?? 'default',
        'label_placement' => 'top',
    ]);
}, 20);

add_filter('acf/load_value', function ($value, $post_id, $field) {
    if (! is_array($field) || ! sanyuan_acf_should_normalize($field)) {
        return $value;
    }

    return sanyuan_normalize_field_value($value, $field['type'] ?? null);
}, 10, 3);

add_filter('acf/update_value', function ($value, $post_id, $field) {
    if (! is_array($field) || ! sanyuan_acf_should_normalize($field)) {
        return $value;
    }

    return sanyuan_normalize_field_value($value, $field['type'] ?? null);
}, 10, 3);

add_filter('acf/prepare_field', function ($field) {
    if (! is_array($field) || ! sanyuan_acf_should_normalize($field)) {
        return $field;
    }
    if (! empty($field['placeholder']) && is_string($field['placeholder'])) {
        $field['placeholder'] = (string) sanyuan_normalize_field_value(
            $field['placeholder'],
            $field['type'] ?? 'text'
        );
    }

    return $field;
});

/**
 * One-off CLI alternative: repair missing News thumbnails via HTTP (runs as www
 * so wp-content/uploads is writable). Token = hash_hmac('sha256', 'repair-news-thumbs', AUTH_KEY).
 */
add_action('init', function () {
    if (($_GET['sanyuan_repair_news_thumbs'] ?? '') !== '1') {
        return;
    }
    $token = (string) ($_GET['token'] ?? '');
    $expect = hash_hmac('sha256', 'repair-news-thumbs', AUTH_KEY);
    if ($token === '' || ! hash_equals($expect, $token)) {
        status_header(403);
        exit('Forbidden');
    }
    if (! function_exists('App\\sanyuan_repair_news_thumbnails')) {
        require_once get_theme_file_path('app/import-news.php');
    }
    nocache_headers();
    header('Content-Type: text/plain; charset=utf-8');
    echo "Repairing missing News featured images...\n" . str_repeat('-', 60) . "\n";
    $summary = sanyuan_repair_news_thumbnails(function (string $line) {
        echo $line . "\n";
    });
    echo str_repeat('-', 60) . "\n";
    printf(
        "Done. total=%d fixed=%d failed=%d skipped=%d\n",
        $summary['total'],
        $summary['fixed'],
        $summary['failed'],
        $summary['skipped']
    );
    exit;
}, 0);
