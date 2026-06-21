<?php

/**
 * wp-admin: sync Chinese product_cat translations from mirror → Polylang.
 */

namespace App;

add_action('admin_menu', function () {
    add_submenu_page(
        'edit.php?post_type=product',
        __('Sync Chinese Categories', 'sage'),
        __('Sync 中文', 'sage'),
        'manage_product_terms',
        'sanyuan-sync-categories-zh',
        __NAMESPACE__ . '\\render_sync_categories_zh_admin_page'
    );
});

add_action('product_cat_pre_add_form', __NAMESPACE__ . '\\render_sync_categories_zh_notice');
add_action('product_cat_pre_edit_form', __NAMESPACE__ . '\\render_sync_categories_zh_notice');
add_action('admin_notices', __NAMESPACE__ . '\\render_sync_categories_zh_list_notice');

function render_sync_categories_zh_list_notice(): void
{
    if (! current_user_can('manage_product_terms')) {
        return;
    }
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (! $screen || $screen->id !== 'edit-product_cat') {
        return;
    }
    render_sync_categories_zh_notice();
}

add_filter('manage_edit-product_cat_columns', __NAMESPACE__ . '\\add_product_cat_zh_name_column');
add_filter('manage_product_cat_custom_column', __NAMESPACE__ . '\\render_product_cat_zh_name_column', 10, 3);

function add_product_cat_zh_name_column(array $columns): array
{
    $out = [];
    foreach ($columns as $key => $label) {
        $out[$key] = $label;
        if ($key === 'name') {
            $out['sanyuan_zh_name'] = __('中文 Name', 'sage');
        }
    }

    return $out;
}

function render_product_cat_zh_name_column(string $content, string $column, int $termId): string
{
    if ($column !== 'sanyuan_zh_name' || ! function_exists('pll_get_term')) {
        return $content;
    }
    $zhId = (int) (pll_get_term($termId, 'zh') ?: 0);
    if ($zhId <= 0) {
        return '<span style="color:#b32d2e;">—</span>';
    }
    $zh = get_term($zhId, 'product_cat');
    if (! $zh || is_wp_error($zh)) {
        return '<span style="color:#b32d2e;">—</span>';
    }
    $edit = get_edit_term_link($zhId, 'product_cat', 'product');
    $name = esc_html($zh->name);
    if (! is_string($edit) || $edit === '') {
        return $name;
    }

    return '<a href="' . esc_url($edit) . '">' . $name . '</a>';
}

function render_sync_categories_zh_notice(): void
{
    if (! current_user_can('manage_product_terms')) {
        return;
    }
    $syncUrl = admin_url('edit.php?post_type=product&page=sanyuan-sync-categories-zh');
    $zhUrl   = admin_url('edit-tags.php?taxonomy=product_cat&post_type=product&lang=zh');
    $filter  = function_exists('PLL') ? PLL()->filter_lang : null;
    $onZh    = $filter instanceof \PLL_Language && $filter->slug === 'zh';
    echo '<div class="notice notice-info inline"><p>';
    if ($onZh) {
        echo esc_html__('You are viewing Chinese (中文) categories. The Name field shows the Chinese term name. Switch to English in the admin language filter to edit English names.', 'sage');
    } else {
        echo esc_html__('The Name column shows the English term. Chinese names are in the 中文 Name column, or switch the admin language filter to 中文.', 'sage');
        echo ' <a href="' . esc_url($zhUrl) . '">' . esc_html__('View 中文 categories', 'sage') . '</a>.';
    }
    echo ' <a href="' . esc_url($syncUrl) . '">' . esc_html__('Sync 中文 categories', 'sage') . '</a>';
    echo '</p></div>';
}

function render_sync_categories_zh_admin_page(): void
{
    if (! current_user_can('manage_product_terms')) {
        wp_die(esc_html__('Sorry, you are not allowed to do this.', 'sage'));
    }

    $summary = null;
    $log     = [];

    if (
        isset($_POST['sanyuan_sync_zh_cats'])
        && check_admin_referer('sanyuan_sync_zh_cats')
    ) {
        require_once get_theme_file_path('app/import-categories.php');
        $summary = sanyuan_sync_zh_product_categories(function (string $line) use (&$log): void {
            $log[] = $line;
        });
    }

    $missing = sanyuan_count_mirrored_categories_missing_zh();
    $englishZh = sanyuan_count_zh_categories_with_english_names();
    $zhListUrl = admin_url('edit-tags.php?taxonomy=product_cat&post_type=product&lang=zh');
    ?>
    <div class="wrap">
        <h1><?php echo esc_html__('Sync Chinese Product Categories', 'sage'); ?></h1>
        <p><?php echo esc_html__('Imports Chinese names from public/site/zh/product.html into Polylang zh product_cat terms. The English Name field on each category is the EN term — open the 中文 Name link or switch the admin language filter to 中文 to edit Chinese names.', 'sage'); ?></p>
        <p><a class="button" href="<?php echo esc_url($zhListUrl); ?>"><?php echo esc_html__('Open categories (中文 filter)', 'sage'); ?></a></p>
        <?php if ($englishZh > 0) : ?>
            <div class="notice notice-warning"><p><?php echo esc_html(sprintf(
                /* translators: %d: number of categories */
                _n('%d Chinese category still uses an English name (no mirror Chinese label). Edit it manually in the 中文 Name column or 中文 filter.', '%d Chinese categories still use English names (no mirror Chinese label). Edit them manually in the 中文 Name column or 中文 filter.', $englishZh, 'sage'),
                $englishZh
            )); ?></p></div>
        <?php endif; ?>
        <?php if ($missing > 0) : ?>
            <div class="notice notice-warning"><p><?php echo esc_html(sprintf(
                /* translators: %d: number of categories */
                _n('%d mirrored category has no Chinese translation yet.', '%d mirrored categories have no Chinese translation yet.', $missing, 'sage'),
                $missing
            )); ?></p></div>
        <?php endif; ?>
        <?php if (is_array($summary)) : ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html(sprintf(
                'Done — synced: %1$d, zh created: %2$d, zh renamed: %6$d, merged: %3$d, deleted: %4$d, errors: %5$d',
                (int) ($summary['repaired'] ?? 0),
                (int) ($summary['zh_created'] ?? 0),
                (int) ($summary['merged'] ?? 0),
                (int) ($summary['deleted'] ?? 0),
                (int) ($summary['errors'] ?? 0),
                (int) ($summary['zh_renamed'] ?? 0)
            )); ?></p></div>
            <?php if ($log) : ?>
                <pre style="max-height:240px;overflow:auto;background:#fff;border:1px solid #ccd0d4;padding:12px;"><?php
                    echo esc_html(implode("\n", $log));
                ?></pre>
            <?php endif; ?>
        <?php endif; ?>
        <form method="post">
            <?php wp_nonce_field('sanyuan_sync_zh_cats'); ?>
            <p><button type="submit" name="sanyuan_sync_zh_cats" class="button button-primary" value="1">
                <?php echo esc_html__('Sync 中文 categories now', 'sage'); ?>
            </button></p>
        </form>
    </div>
    <?php
}

function sanyuan_count_mirrored_categories_missing_zh(): int
{
    if (! function_exists('pll_get_term')) {
        return 0;
    }
    $terms = get_terms([
        'taxonomy'   => 'product_cat',
        'hide_empty' => false,
        'lang'       => function_exists(__NAMESPACE__ . '\\default_lang') ? default_lang() : '',
        'meta_query' => [[ 'key' => SANYUAN_CAT_META, 'compare' => 'EXISTS' ]],
    ]);
    if (! is_array($terms)) {
        return 0;
    }
    $missing = 0;
    foreach ($terms as $term) {
        if ($term instanceof \WP_Term && ! pll_get_term($term->term_id, 'zh')) {
            $missing++;
        }
    }

    return $missing;
}

function sanyuan_count_zh_categories_with_english_names(): int
{
    if (! function_exists('pll_get_term_language')) {
        return 0;
    }
    $terms = get_terms([
        'taxonomy'   => 'product_cat',
        'hide_empty' => false,
        'lang'       => 'zh',
        'meta_query' => [[ 'key' => SANYUAN_CAT_META, 'compare' => 'EXISTS' ]],
    ]);
    if (! is_array($terms)) {
        return 0;
    }
    $count = 0;
    foreach ($terms as $term) {
        if ($term instanceof \WP_Term && ! preg_match('/\p{Han}/u', $term->name)) {
            $count++;
        }
    }

    return $count;
}
