<?php

/**
 * Seed Chinese Header/Footer/Chrome ACF Options (options_zh) from the EN store.
 *
 * Run once:
 *   php -r 'define("WP_USE_THEMES",false); require "wp-load.php";
 *           require "wp-content/themes/sanyuan-theme/app/seed-options-zh.php";'
 */

if (! function_exists('update_field')) {
    return;
}

/** Permalink of the zh translation for a page slug (fallback EN). */
function seed_zh_page_url(string $slug): string
{
    $page = get_page_by_path($slug);
    if (! $page) {
        return '';
    }
    if (function_exists('pll_get_post')) {
        $zhId = pll_get_post($page->ID, 'zh');
        if ($zhId) {
            return (string) get_permalink($zhId);
        }
    }
    return (string) get_permalink($page);
}

$store = 'options_zh';

$menu = [
    [
        'label' => '关于我们',
        'link'  => seed_zh_page_url('about'),
        'children' => [
            ['label' => '公司简介', 'link' => seed_zh_page_url('about')],
            ['label' => 'ESG', 'link' => seed_zh_page_url('esg')],
            ['label' => '新闻与活动', 'link' => seed_zh_page_url('news')],
        ],
    ],
    [
        'label' => '电缆实验室',
        'link'  => seed_zh_page_url('cable-lab-overview'),
        'children' => [
            ['label' => '实验室概览', 'link' => seed_zh_page_url('cable-lab-overview')],
            ['label' => '电缆测试与检验', 'link' => seed_zh_page_url('cable-testing-inspection')],
            ['label' => '电缆合规', 'link' => seed_zh_page_url('cable-compliance')],
        ],
    ],
    ['label' => '技术支持', 'link' => seed_zh_page_url('support'), 'children' => []],
    ['label' => '联系我们', 'link' => seed_zh_page_url('contact'), 'children' => []],
    ['label' => '产品中心', 'link' => seed_zh_page_url('product'), 'children' => []],
];

$productLabels = [
    'LAN Cables'                 => '局域网电缆',
    'Fire Resistant Cables'      => '耐火电缆',
    'Belden Equivalent Cables'   => 'Belden 同等电缆',
    'Fire Alarm Cables'          => '火灾报警电缆',
    'Industrial Ethernet Cables' => '工业以太网电缆',
    'Alarm Cables'               => '报警电缆',
    'Bus Cables'                 => '总线电缆',
    'Audio Visual Cables'        => '视听电缆',
    'Instrumentation Cables'     => '仪表电缆',
    'Power Cables'               => '电力电缆',
    'Control Cables'             => '控制电缆',
    'High Temperaure Cables'     => '耐高温电缆',
    'Robotic Cables'             => '机器人电缆',
    'Cable Assembilies'          => '电缆组件',
    'Coaxial Cables'             => '同轴电缆',
    'Cable Connectors'           => '电缆连接器',
    'Fiber Optic Cables'         => '光纤电缆',
    'Tools & Hardware'           => '工具与硬件',
    'Hybrid Cables'              => '混合电缆',
];

$enProducts = get_field('footer_products', 'option');
$products   = [];
if (is_array($enProducts)) {
    foreach ($enProducts as $row) {
        $enLabel = isset($row['label']) ? (string) $row['label'] : '';
        $products[] = [
            'label' => $productLabels[$enLabel] ?? $enLabel,
            'link'  => isset($row['link']) ? (string) $row['link'] : '',
        ];
    }
}

$contactUrl = seed_zh_page_url('contact');
$regions    = [
    ['name' => '欧洲', 'link' => $contactUrl],
    ['name' => '中东与非洲', 'link' => $contactUrl],
    ['name' => '北美与南美', 'link' => $contactUrl],
    ['name' => '亚洲与澳大利亚/大洋洲', 'link' => $contactUrl],
];

$enSocials = get_field('footer_socials', 'option');
$socials   = is_array($enSocials) ? $enSocials : [];

$fields = [
    'header_menu'               => $menu,
    'header_search_placeholder' => '在此搜索产品',
    'footer_company'            => '杭州三原电缆有限公司',
    'footer_address'            => '中国浙江省杭州市临安区天目山镇桂芳桥工业区6号 311312',
    'footer_email'              => 'info@sanyuancable.com.cn',
    'footer_copyright'          => '<p style="font-size:14px;line-height:24px">&copy; 2026 杭州振凯科技有限公司&nbsp; &nbsp;|&nbsp; &nbsp;保留所有权利。</p>',
    'footer_products'           => $products,
    'footer_regions'            => $regions,
    'footer_socials'            => $socials,
    'chrome_text_1'             => '我们随时为您服务',
    'chrome_text_2'             => '联系我们',
    'chrome_link_1'             => $contactUrl,
];

/** Run seed only when this file is the entry script (not require'd by seed-home-zh.php). */
if (! function_exists('seed_options_zh_run')) {
    function seed_options_zh_run(): void
    {
        global $fields, $store;
        foreach ($fields as $name => $value) {
            update_field($name, $value, $store);
        }
        if (defined('WP_CLI') || PHP_SAPI === 'cli') {
            echo 'options_zh seeded: ' . implode(', ', array_keys($fields)) . "\n";
        }
    }
}

if (! defined('SANYUAN_SEED_INCLUDE_ONLY')) {
    seed_options_zh_run();
}
