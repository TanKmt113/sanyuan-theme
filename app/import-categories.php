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
function sanyuan_parse_category_tree(): array
{
    $file = get_theme_file_path('public/site/product.html');
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
