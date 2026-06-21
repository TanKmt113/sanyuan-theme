<?php

/**
 * Strip baked-in mirror SEO tags and expose wp_head()/wp_footer() on mirror renders
 * so plugins (Rank Math, Yoast, …) can output title, meta, schema, canonical, etc.
 */

namespace App;

/** Remove static mirror <title>, description, Open Graph, Twitter, canonical. */
function sanyuan_strip_mirror_seo_tags(string $html): string
{
    $html = preg_replace('#<title\b[^>]*>.*?</title>#is', '', $html, 1) ?? $html;
    $html = preg_replace('#<meta\b[^>]*\bname\s*=\s*["\']description["\'][^>]*>#is', '', $html) ?? $html;
    $html = preg_replace('#<meta\b[^>]*\bname\s*=\s*["\']keywords["\'][^>]*>#is', '', $html) ?? $html;
    $html = preg_replace('#<meta\b[^>]*\bproperty\s*=\s*["\']og:[^"\']*["\'][^>]*>#is', '', $html) ?? $html;
    $html = preg_replace('#<meta\b[^>]*\bname\s*=\s*["\']twitter:[^"\']*["\'][^>]*>#is', '', $html) ?? $html;
    $html = preg_replace('#<meta\b[^>]*\bproperty\s*=\s*["\']twitter:[^"\']*["\'][^>]*>#is', '', $html) ?? $html;
    $html = preg_replace('#<link\b[^>]*\brel\s*=\s*["\']canonical["\'][^>]*>#is', '', $html) ?? $html;

    return $html;
}

/** Capture wp_head() for injection into mirror HTML (Rank Math hooks here). */
function sanyuan_capture_wp_head(): string
{
    if (is_admin()) {
        return '';
    }
    ob_start();
    wp_head();

    return (string) ob_get_clean();
}

/** Capture wp_footer() for JSON-LD / deferred SEO output. */
function sanyuan_capture_wp_footer(): string
{
    if (is_admin()) {
        return '';
    }
    ob_start();
    wp_footer();

    return (string) ob_get_clean();
}

function sanyuan_inject_wp_head(string $html): string
{
    if (! apply_filters('sanyuan_enable_wp_seo', true)) {
        return $html;
    }
    $extra = sanyuan_capture_wp_head();
    if ($extra === '' || str_contains($html, '<!-- sanyuan-wp-head -->')) {
        return $html;
    }

    $inject = '<!-- sanyuan-wp-head -->' . $extra;

    return preg_replace('#</head>#i', $inject . '</head>', $html, 1) ?? $html;
}

function sanyuan_inject_wp_footer(string $html): string
{
    if (! apply_filters('sanyuan_enable_wp_seo', true)) {
        return $html;
    }
    $extra = sanyuan_capture_wp_footer();
    if ($extra === '' || str_contains($html, '<!-- sanyuan-wp-footer -->')) {
        return $html;
    }

    $inject = '<!-- sanyuan-wp-footer -->' . $extra;

    return preg_replace('#</body>#i', $inject . '</body>', $html, 1) ?? $html;
}

/** Final SEO pass before echo: plugin head + footer hooks on mirror shells. */
function sanyuan_apply_wp_seo(string $html): string
{
    return sanyuan_inject_wp_footer(sanyuan_inject_wp_head($html));
}
