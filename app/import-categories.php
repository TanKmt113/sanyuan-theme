<?php

/**
 * One-off importer: turn the original mega-menu category tree (the `e_categoryB`
 * panel in product.html) into a hierarchical WooCommerce `product_cat` taxonomy
 * and assign the imported products to their categories.
 *
 *   php wp-content/themes/sanyuan-theme/tools/run-import-cats.php
 *
 * Idempotent — terms are keyed on the `_sanyuan_cat_id` term meta (the original
 * product_list/<catId> id), product assignments use append mode. NOT loaded at
 * runtime. Relies on helpers in app/products.php (sanyuan_dom,
 * sanyuan_product_by_pid) and app/categories.php (sanyuan_term_by_catid).
 */

namespace App;

/**
 * Parse the category tree from product.html. Picks the most complete deep-1
 * list (the page carries two `e_categoryB` panels). Returns:
 *   ['categories' => [catId => ['name','parent']],   // DFS order: parents first
 *    'assignments' => [['pid','catId'], ...]]
 */
function sanyuan_parse_category_tree_file(string $relPath): array
{
    $file = get_theme_file_path($relPath);
    if (! is_readable($file)) {
        return ['categories' => [], 'assignments' => []];
    }

    $dom = sanyuan_dom(file_get_contents($file));
    $xp  = new \DOMXPath($dom);

    $hasClass = fn (string $c) => "contains(concat(' ', normalize-space(@class), ' '), ' " . $c . " ')";

    // The page carries more than one deep-1 panel and they are not identical.
    // Walk them all, largest first, so the richest hierarchy wins (categories
    // keep their first-seen parent) and smaller panels only fill in gaps.
    $trees = [];
    foreach ($xp->query('//ul[' . $hasClass('deep-1') . ']') as $ul) {
        $trees[] = [$ul, $xp->query('.//li[' . $hasClass('p_c_item') . ']', $ul)->length];
    }
    if (! $trees) {
        return ['categories' => [], 'assignments' => []];
    }
    usort($trees, fn ($a, $b) => $b[1] <=> $a[1]);

    $categories = [];
    $assignments = [];

    $walk = function (\DOMElement $ul, string $parentCatId) use (&$walk, $xp, $hasClass, &$categories, &$assignments) {
        foreach ($xp->query('./li[' . $hasClass('p_c_item') . ']', $ul) as $li) {
            $a = $xp->query('./p[contains(@class, "p_c_title")]/a', $li)->item(0);
            if (! $a) {
                continue;
            }
            $href = (string) $a->getAttribute('href');
            $name = trim($a->textContent);

            if (preg_match('~product_list/([0-9]+)\.html~', $href, $m)) {
                $catId = $m[1];
                // Keep first occurrence (DFS: parent recorded before children).
                if (! isset($categories[$catId])) {
                    $categories[$catId] = ['name' => $name, 'parent' => $parentCatId];
                }
                foreach ($xp->query('./ul[contains(@class, "p_c_content")]', $li) as $childUl) {
                    $walk($childUl, $catId);
                }
            } elseif (preg_match('~product_Details/([0-9]+)\.html~', $href, $m)) {
                $assignments[] = ['pid' => $m[1], 'catId' => $parentCatId];
            }
        }
    };
    foreach ($trees as [$ul, $count]) {
        $walk($ul, '0');
    }

    return ['categories' => $categories, 'assignments' => $assignments];
}

/** Parse the EN mega-menu category tree from public/site/product.html. */
function sanyuan_parse_category_tree(): array
{
    return sanyuan_parse_category_tree_file('public/site/product.html');
}

/** EN => ZH labels from mirror trees + manual fixes for WC-only B2ca categories. */
function sanyuan_mirror_en_zh_category_name_map(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }

    $map = [
        'Armoured Lan Cables'           => '铠装局域网线缆',
        'B2ca Cables'                   => 'CPR B2ca 线缆',
        'B2ca Alarm Cables'             => 'B2ca 报警线缆',
        'B2ca Belden Equivalent Cables' => 'B2ca 百通等效线缆',
        'B2ca Coaxial Cables'           => 'B2ca 同轴线缆',
        'B2ca Fire Resistant Cables'    => 'B2ca 防火线缆',
        'B2ca Lan Cables'               => 'B2ca 局域网线缆',
        'B2ca Signal Control Cables'    => 'B2ca信号控制线缆',
        'B2ca Fiber Optic Cables'       => 'B2ca 光纤线缆',
    ];
    foreach (sanyuan_parse_category_tree()['categories'] as $catId => $enC) {
        $enName = (string) ($enC['name'] ?? '');
        $zhName = (string) (sanyuan_parse_category_tree_file('public/site/zh/product.html')['categories'][$catId]['name'] ?? '');
        if ($enName !== '' && $zhName !== '') {
            $map[$enName] = $zhName;
        }
    }

    return $map;
}

function sanyuan_category_name_has_han(string $name): bool
{
    return (bool) preg_match('/\p{Han}/u', $name);
}

/** Resolve the Chinese product_cat label for an EN admin/mirror name. */
function sanyuan_resolve_zh_category_name(string $enName, string $catId = '', array $zhCats = []): string
{
    $fromCat = '';
    if ($catId !== '' && isset($zhCats[$catId])) {
        $fromCat = (string) $zhCats[$catId]['name'];
        if ($fromCat !== '' && sanyuan_category_name_has_han($fromCat)) {
            return $fromCat;
        }
    }
    $map = sanyuan_mirror_en_zh_category_name_map();
    if (isset($map[$enName]) && $map[$enName] !== '') {
        return $map[$enName];
    }

    return $fromCat !== '' ? $fromCat : $enName;
}

/** Update existing zh terms that still carry English-only names when a Chinese label exists. */
function sanyuan_fill_zh_category_names_without_han(?callable $log = null): array
{
    $log = $log ?: function (string $line): void {};
    $summary = ['updated' => 0, 'skipped' => 0, 'errors' => 0];

    if (! function_exists('pll_get_term') || ! function_exists('pll_get_term_language')) {
        return $summary;
    }

    global $wpdb;
    $zhCats = sanyuan_parse_category_tree_file('public/site/zh/product.html')['categories'];
    $terms  = get_terms([
        'taxonomy'   => 'product_cat',
        'hide_empty' => false,
        'lang'       => 'zh',
        'meta_query' => [[ 'key' => SANYUAN_CAT_META, 'compare' => 'EXISTS' ]],
    ]);
    if (! is_array($terms)) {
        return $summary;
    }

    foreach ($terms as $term) {
        if (! ($term instanceof \WP_Term) || sanyuan_category_name_has_han($term->name)) {
            $summary['skipped']++;
            continue;
        }
        $enId = (int) (pll_get_term($term->term_id, default_lang()) ?: 0);
        $en   = $enId > 0 ? get_term($enId, 'product_cat') : null;
        $enName = ($en && ! is_wp_error($en)) ? (string) $en->name : $term->name;
        $catId  = (string) get_term_meta($term->term_id, SANYUAN_CAT_META, true);
        $zhName = sanyuan_resolve_zh_category_name($enName, $catId, $zhCats);
        if ($zhName === $term->name || ! sanyuan_category_name_has_han($zhName)) {
            $summary['skipped']++;
            continue;
        }
        $updated = $wpdb->update($wpdb->terms, ['name' => $zhName], ['term_id' => $term->term_id]);
        if ($updated === false) {
            $summary['errors']++;
            $log("  zh #{$term->term_id} update ERROR");
            continue;
        }
        clean_term_cache($term->term_id, 'product_cat');
        $summary['updated']++;
        $log("  zh #{$term->term_id}: {$term->name} → {$zhName}");
    }

    if (function_exists('pll_cache_flush')) {
        pll_cache_flush();
    }
    if (function_exists(__NAMESPACE__ . '\\sanyuan_bump_category_tree_version')) {
        sanyuan_bump_category_tree_version();
    }

    return $summary;
}

/**
 * Rebuild Polylang en↔zh pairs for mirrored product_cat terms. Writes Chinese
 * names from public/site/zh/product.html into the zh Polylang terms (wp-admin).
 */
function repair_polylang_product_categories(?callable $log = null): array
{
    $log = $log ?: function (string $line): void {};

    if (! function_exists('pll_set_term_language') || ! function_exists('PLL')) {
        $log('Polylang missing — skipped.');

        return ['repaired' => 0, 'merged' => 0, 'deleted' => 0, 'errors' => 1, 'zh_created' => 0];
    }

    global $wpdb;
    $enCats = sanyuan_parse_category_tree()['categories'];
    $zhCats = sanyuan_parse_category_tree_file('public/site/zh/product.html')['categories'];
    $def    = default_lang();

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT t.term_id, t.name, t.slug, CAST(tm.meta_value AS CHAR) AS cat_id
           FROM {$wpdb->terms} t
           JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id AND tt.taxonomy = 'product_cat'
           JOIN {$wpdb->termmeta} tm ON tm.term_id = t.term_id AND tm.meta_key = %s
          ORDER BY tm.meta_value, t.term_id",
        SANYUAN_CAT_META
    ));

    $byCat = [];
    foreach ($rows ?: [] as $row) {
        $byCat[(string) $row->cat_id][] = $row;
    }

    $summary   = ['repaired' => 0, 'merged' => 0, 'deleted' => 0, 'errors' => 0, 'zh_created' => 0];
    $canonical = ['en' => [], 'zh' => []];
    $hasHan    = static fn (string $s): bool => (bool) preg_match('/\p{Han}/u', $s);

    uksort($byCat, static function (string $a, string $b) use ($enCats): int {
        $pa = isset($enCats[$a]['parent']) ? (string) $enCats[$a]['parent'] : '0';
        $pb = isset($enCats[$b]['parent']) ? (string) $enCats[$b]['parent'] : '0';
        if ($pa === $pb) {
            return strcmp($a, $b);
        }
        if ($pa === '0') {
            return -1;
        }
        if ($pb === '0') {
            return 1;
        }

        return strcmp($pa, $pb);
    });

    foreach ($byCat as $catId => $group) {
        $catId      = (string) $catId;
        $enC        = $enCats[$catId] ?? null;
        $enMirror   = (string) ($enC['name'] ?? '');
        $zhMirror   = (string) ($zhCats[$catId]['name'] ?? '');
        $enId       = sanyuan_pick_polylang_category_term($group, $def, 'en', $enMirror, $zhMirror, $hasHan);
        if ($enId <= 0) {
            continue;
        }

        $enTerm    = get_term($enId, 'product_cat');
        $enName    = ($enTerm && ! is_wp_error($enTerm) && $enTerm->name !== '')
            ? (string) $enTerm->name
            : ($enMirror !== '' ? $enMirror : (string) $group[0]->name);
        $zhName    = sanyuan_resolve_zh_category_name($enName, $catId, $zhCats);
        $enSlug    = (string) ($wpdb->get_var($wpdb->prepare(
            "SELECT slug FROM {$wpdb->terms} WHERE term_id = %d",
            $enId
        )) ?: sanitize_title($enName));
        $parentEn  = sanyuan_polylang_category_parent_en_id($catId, $enC, $enId, $canonical);
        $zhId      = sanyuan_pick_polylang_category_term($group, $def, 'zh', $enMirror, $zhName, $hasHan, $enId);
        $createdZh = false;

        $wpdb->update($wpdb->terms, ['name' => $enName, 'slug' => $enSlug], ['term_id' => $enId]);
        pll_set_term_language($enId, $def);
        wp_update_term($enId, 'product_cat', ['parent' => $parentEn]);
        update_term_meta($enId, SANYUAN_CAT_META, $catId);
        clean_term_cache($enId, 'product_cat');

        if ($zhId <= 0 || $zhId === $enId) {
            $res = wp_insert_term($zhName, 'product_cat', ['parent' => 0, 'slug' => $enSlug]);
            if (is_wp_error($res)) {
                $res = wp_insert_term($zhName, 'product_cat', [
                    'parent' => 0,
                    'slug'   => $enSlug . '-zh-' . sanitize_title($catId),
                ]);
            }
            if (is_wp_error($res)) {
                $summary['errors']++;
                $log("  cat {$catId} zh create ERROR: " . $res->get_error_message());
                continue;
            }
            $zhId      = (int) $res['term_id'];
            $createdZh = true;
        }

        $parentZh = sanyuan_polylang_category_parent_zh_id($catId, $enC, $enId, $canonical);
        $wpdb->update($wpdb->terms, ['name' => $zhName, 'slug' => $enSlug], ['term_id' => $zhId]);
        pll_set_term_language($zhId, 'zh');
        pll_save_term_translations([$def => $enId, 'zh' => $zhId]);
        wp_update_term($zhId, 'product_cat', ['parent' => $parentZh]);
        update_term_meta($zhId, SANYUAN_CAT_META, $catId);
        clean_term_cache($zhId, 'product_cat');

        $canonical['en'][$catId] = $enId;
        $canonical['zh'][$catId] = $zhId;
        $summary['repaired']++;
        if ($createdZh) {
            $summary['zh_created']++;
            $log("  cat {$catId}: created zh #{$zhId} «{$zhName}»");
        }

        foreach ($group as $row) {
            $tid = (int) $row->term_id;
            if ($tid === $enId || $tid === $zhId) {
                continue;
            }
            sanyuan_merge_product_cat_term(
                $tid,
                ($hasHan((string) $row->name) || pll_get_term_language($tid) === 'zh') ? $zhId : $enId
            );
            $deleted = wp_delete_term($tid, 'product_cat');
            if (is_wp_error($deleted)) {
                $summary['errors']++;
                $log("  delete #{$tid} ERROR: " . $deleted->get_error_message());
            } else {
                $summary['deleted']++;
            }
            $summary['merged']++;
        }
    }

    if (function_exists('pll_cache_flush')) {
        pll_cache_flush();
    }
    $fill = sanyuan_fill_zh_category_names_without_han($log);
    $summary['zh_renamed'] = (int) ($fill['updated'] ?? 0);
    if (function_exists(__NAMESPACE__ . '\\sanyuan_bump_category_tree_version')) {
        sanyuan_bump_category_tree_version();
    }

    return $summary;
}

/** Pick the canonical en or zh product_cat term id inside one mirror cat-id group. */
function sanyuan_pick_polylang_category_term(
    array $group,
    string $defLang,
    string $wantLang,
    string $enMirror,
    string $zhMirror,
    callable $hasHan,
    int $excludeId = 0
): int {
    $targetName = $wantLang === 'zh' ? $zhMirror : $enMirror;
    $picked     = 0;

    foreach ($group as $row) {
        $tid = (int) $row->term_id;
        if ($tid === $excludeId) {
            continue;
        }
        if ($targetName !== '' && (string) $row->name === $targetName) {
            return $tid;
        }
    }
    foreach ($group as $row) {
        $tid  = (int) $row->term_id;
        $lang = (string) (pll_get_term_language($tid) ?: '');
        $name = (string) $row->name;
        if ($tid === $excludeId) {
            continue;
        }
        if ($wantLang === 'zh' && ($lang === 'zh' || $hasHan($name))) {
            $picked = $tid;
            break;
        }
        if ($wantLang !== 'zh' && $lang === $defLang && ! $hasHan($name)) {
            $picked = $tid;
            break;
        }
    }
    if ($picked <= 0 && $wantLang !== 'zh') {
        foreach ($group as $row) {
            $tid = (int) $row->term_id;
            if ($tid !== $excludeId && ! $hasHan((string) $row->name)) {
                return $tid;
            }
        }
    }
    if ($picked <= 0 && $wantLang === 'zh') {
        foreach ($group as $row) {
            $tid = (int) $row->term_id;
            if ($tid !== $excludeId && $hasHan((string) $row->name)) {
                return $tid;
            }
        }
    }

    return $picked;
}

function sanyuan_polylang_category_parent_en_id(string $catId, ?array $enC, int $enId, array $canonical): int
{
    if ($enC && ($enC['parent'] ?? '0') !== '0') {
        return (int) ($canonical['en'][(string) $enC['parent']] ?? 0);
    }
    $term = get_term($enId, 'product_cat');

    return ($term && ! is_wp_error($term)) ? (int) $term->parent : 0;
}

function sanyuan_polylang_category_parent_zh_id(string $catId, ?array $enC, int $enId, array $canonical): int
{
    if ($enC && ($enC['parent'] ?? '0') !== '0') {
        return (int) ($canonical['zh'][(string) $enC['parent']] ?? 0);
    }
    $term = get_term($enId, 'product_cat');
    if (! $term || is_wp_error($term) || (int) $term->parent <= 0) {
        return 0;
    }
    $parentCatId = (string) get_term_meta((int) $term->parent, SANYUAN_CAT_META, true);
    if ($parentCatId !== '' && isset($canonical['zh'][$parentCatId])) {
        return (int) $canonical['zh'][$parentCatId];
    }

    return (int) (pll_get_term((int) $term->parent, 'zh') ?: 0);
}

/** Run mirror → Polylang zh product_cat sync (CLI + wp-admin). */
function sanyuan_sync_zh_product_categories(?callable $log = null): array
{
    return repair_polylang_product_categories($log);
}

/** Move product assignments from one product_cat term to another, then caller may delete $from. */
function sanyuan_merge_product_cat_term(int $from, int $to): void
{
    if ($from <= 0 || $to <= 0 || $from === $to) {
        return;
    }
    $fromTt = (int) get_term($from, 'product_cat')?->term_taxonomy_id;
    $toTt   = (int) get_term($to, 'product_cat')?->term_taxonomy_id;
    if ($fromTt <= 0 || $toTt <= 0) {
        return;
    }
    global $wpdb;
    $objectIds = $wpdb->get_col($wpdb->prepare(
        "SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d",
        $fromTt
    ));
    foreach ($objectIds ?: [] as $oid) {
        wp_set_object_terms((int) $oid, [$to], 'product_cat', true);
    }
}

/** @deprecated Use repair_polylang_product_categories() — kept for existing runner script. */
function seed_zh_product_categories(?callable $log = null): array
{
    $summary = repair_polylang_product_categories($log);

    return [
        'created' => 0,
        'updated' => (int) ($summary['repaired'] ?? 0),
        'skipped' => 0,
        'errors'  => (int) ($summary['errors'] ?? 0),
    ];
}

/**
 * Import the parsed tree into product_cat terms + product assignments.
 * $log is an optional callable(string). Returns a summary array.
 */
function sanyuan_import_categories(?callable $log = null): array
{
    $log = $log ?: function ($line) {};

    $tree = sanyuan_parse_category_tree();
    $cats = $tree['categories'];
    $assign = $tree['assignments'];

    $summary = [
        'categories' => count($cats), 'terms_created' => 0, 'terms_updated' => 0,
        'assignments' => count($assign), 'assigned' => 0, 'skipped' => 0, 'errors' => 0,
    ];

    // Terms first (DFS order => parents precede children).
    $catIdToTerm = [];
    foreach ($cats as $catId => $c) {
        $parentTermId = ($c['parent'] !== '0' && isset($catIdToTerm[$c['parent']]))
            ? $catIdToTerm[$c['parent']] : 0;

        $term = sanyuan_term_by_catid((string) $catId);
        if ($term) {
            wp_update_term($term->term_id, 'product_cat', ['name' => $c['name'], 'parent' => $parentTermId]);
            $catIdToTerm[$catId] = (int) $term->term_id;
            $summary['terms_updated']++;
            continue;
        }

        $res = wp_insert_term($c['name'], 'product_cat', ['parent' => $parentTermId]);
        if (is_wp_error($res)) {
            $dup = $res->get_error_data('term_exists');
            if ($dup) {
                // Name+parent collision with an existing (non-mirror) term: reuse,
                // and tag it with this catId so links/lookups resolve.
                $termId = (int) $dup;
                update_term_meta($termId, SANYUAN_CAT_META, (string) $catId);
                $catIdToTerm[$catId] = $termId;
                $log("  cat $catId '{$c['name']}' reused existing term #$termId");
                continue;
            }
            $summary['errors']++;
            $log("  cat $catId ERROR: " . $res->get_error_message());
            continue;
        }

        $termId = (int) $res['term_id'];
        update_term_meta($termId, SANYUAN_CAT_META, (string) $catId);
        $catIdToTerm[$catId] = $termId;
        $summary['terms_created']++;
    }

    // Product -> category assignments (append).
    foreach ($assign as $a) {
        $termId = $catIdToTerm[$a['catId']] ?? 0;
        if (! $termId) {
            $summary['skipped']++;
            continue;
        }
        $post = sanyuan_product_by_pid((string) $a['pid']);
        if (! $post) {
            $summary['skipped']++;
            continue;
        }
        $r = wp_set_object_terms($post->ID, [$termId], 'product_cat', true);
        if (is_wp_error($r)) {
            $summary['errors']++;
        } else {
            $summary['assigned']++;
        }
    }

    $log(sprintf(
        'terms: %d created, %d updated (of %d) | assignments: %d done, %d skipped, %d errors',
        $summary['terms_created'], $summary['terms_updated'], $summary['categories'],
        $summary['assigned'], $summary['skipped'], $summary['errors']
    ));

    return $summary;
}
