<?php

/**
 * One-off importer: turn the static product mirror files
 * (public/site/product_Details/<id>.html) into real WooCommerce products.
 *
 * Idempotent — keyed on the `_sanyuan_pid` meta — so it can be re-run safely to
 * pick up new/changed mirror files. NOT loaded at runtime; a tool script
 * (tools/run-import.php) requires this file and calls sanyuan_import_products().
 *
 * Inquiry catalog: products are simple + virtual (no shipping), no price. The
 * front end renders them via App\render_product() at their original URL.
 *
 * Relies on the extraction helpers in app/products.php (sanyuan_extract_product
 * et al.), which the active theme loads.
 */

namespace App;

/** Find a product by original id regardless of status (for idempotent upsert). */
function sanyuan_find_product_any_status(string $pid): ?\WP_Post
{
    $q = get_posts([
        'post_type'   => 'product',
        'post_status' => 'any',
        'numberposts' => 1,
        'meta_key'    => SANYUAN_PID_META,
        'meta_value'  => $pid,
    ]);
    return $q ? $q[0] : null;
}

/**
 * Sideload a LOCAL image (under public/assets_img) into the media library and
 * return its attachment id. Idempotent via the `_sanyuan_src` meta (filename),
 * so re-imports reuse the existing attachment instead of duplicating.
 */
function sanyuan_attach_local_image(string $srcPath, int $postId, string $title): int
{
    if (! is_readable($srcPath)) {
        return 0;
    }
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $base = basename($srcPath);

    $existing = get_posts([
        'post_type'   => 'attachment',
        'post_status' => 'inherit',
        'numberposts' => 1,
        'fields'      => 'ids',
        'meta_key'    => '_sanyuan_src',
        'meta_value'  => $base,
    ]);
    if ($existing) {
        return (int) $existing[0];
    }

    $upload = wp_upload_bits($base, null, file_get_contents($srcPath));
    if (! empty($upload['error']) || empty($upload['file'])) {
        return 0;
    }

    $type = wp_check_filetype($upload['file']);
    $attId = wp_insert_attachment([
        'post_mime_type' => $type['type'] ?: 'image/jpeg',
        'post_title'     => $title !== '' ? $title : $base,
        'post_content'   => '',
        'post_status'    => 'inherit',
    ], $upload['file'], $postId);

    if (is_wp_error($attId) || ! $attId) {
        return 0;
    }

    wp_update_attachment_metadata($attId, wp_generate_attachment_metadata($attId, $upload['file']));
    update_post_meta($attId, '_sanyuan_src', $base);

    return (int) $attId;
}

/**
 * Create or update one WC product from extracted mirror data.
 * Returns ['pid','id','action','image','datasheet'] or ['pid','error'].
 */
function sanyuan_import_product(array $d): array
{
    if (! function_exists('wc_get_product')) {
        return ['pid' => $d['pid'], 'error' => 'WooCommerce inactive'];
    }
    if ($d['title'] === '') {
        return ['pid' => $d['pid'], 'error' => 'no title parsed'];
    }

    $existing = sanyuan_find_product_any_status($d['pid']);
    $product  = $existing ? wc_get_product($existing->ID) : new \WC_Product_Simple();
    if (! $product) {
        return ['pid' => $d['pid'], 'error' => 'could not instantiate product'];
    }

    $product->set_name($d['title']);
    $product->set_status('publish');
    $product->set_catalog_visibility('visible');
    $product->set_virtual(true);                 // inquiry: no shipping
    $product->set_description($d['description']); // long: Application + spec tables
    $product->set_short_description($d['subtitle']);
    if (! $existing) {
        $product->set_slug(sanitize_title($d['title'] . '-' . $d['pid']));
    }

    $id = $product->save();
    if (! $id || is_wp_error($id)) {
        return ['pid' => $d['pid'], 'error' => 'save failed'];
    }

    update_post_meta($id, SANYUAN_PID_META, $d['pid']);
    if ($d['meta_desc'] !== '') {
        update_post_meta($id, '_sanyuan_meta_desc', $d['meta_desc']);
    }

    $imgId = 0;
    if (! empty($d['image_local'])) {
        $imgId = sanyuan_attach_local_image($d['image_local'], $id, $d['title']);
        if ($imgId) {
            set_post_thumbnail($id, $imgId);
        }
    }

    if (! empty($d['datasheet']) && function_exists('update_field')) {
        update_field('product_datasheet_url', $d['datasheet']['url'], $id);
        update_field('product_datasheet_name', $d['datasheet']['name'], $id);
    }

    return [
        'pid'       => $d['pid'],
        'id'        => $id,
        'action'    => $existing ? 'updated' : 'created',
        'image'     => $imgId ? 'yes' : ($d['image_local'] ? 'failed' : 'none'),
        'datasheet' => ! empty($d['datasheet']) ? 'yes' : 'none',
    ];
}

/**
 * Import every product mirror file (or only the given ids). $log is an optional
 * callable(string) for progress output. Returns a summary array.
 *
 * KSES is disabled for the run so the spec-table HTML (inline styles, <table>,
 * <span style>) survives wp_insert_post — this is trusted, first-party content.
 */
function sanyuan_import_products(array $onlyIds = [], ?callable $log = null): array
{
    $log = $log ?: function ($line) {};

    $dir   = get_theme_file_path('public/site/product_Details');
    $files = glob($dir . '/*.html') ?: [];

    // Restrict to numeric-id files unless specific ids requested (skip stray).
    $ids = [];
    foreach ($files as $file) {
        $pid = preg_replace('/\.html$/i', '', basename($file));
        if ($onlyIds && ! in_array($pid, $onlyIds, true)) {
            continue;
        }
        $ids[] = $pid;
    }
    sort($ids, SORT_NATURAL);

    kses_remove_filters();

    $summary = ['total' => count($ids), 'created' => 0, 'updated' => 0, 'errors' => 0];
    foreach ($ids as $i => $pid) {
        $data = sanyuan_extract_product($pid);
        if (! $data) {
            $summary['errors']++;
            $log(sprintf('[%d/%d] %s  ERROR: file unreadable', $i + 1, count($ids), $pid));
            continue;
        }
        $r = sanyuan_import_product($data);
        if (isset($r['error'])) {
            $summary['errors']++;
            $log(sprintf('[%d/%d] %s  ERROR: %s', $i + 1, count($ids), $pid, $r['error']));
            continue;
        }
        $summary[$r['action']]++;
        $title = strlen($data['title']) > 48 ? substr($data['title'], 0, 47) . '…' : $data['title'];
        $log(sprintf(
            '[%d/%d] %s  #%d %s (img:%s datasheet:%s) — %s',
            $i + 1, count($ids), $pid, $r['id'], $r['action'], $r['image'], $r['datasheet'], $title
        ));
    }

    kses_init_filters();

    return $summary;
}
