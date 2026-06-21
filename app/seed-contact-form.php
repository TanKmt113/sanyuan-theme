<?php

/**
 * Seed Contact page form ACF (EN + ZH) from mirror defaults.
 *
 * Run once:
 *   php -r 'define("WP_USE_THEMES",false); require "wp-load.php";
 *           require "wp-content/themes/sanyuan-theme/app/seed-contact-form.php";'
 */

if (! function_exists('update_field')) {
    return;
}

if (! function_exists('sanyuan_seed_field_repair')) {
    define('SANYUAN_SEED_INCLUDE_ONLY', true);
    require __DIR__ . '/seed-acf.php';
}

/** Contact page IDs for every Polylang translation. */
function sanyuan_all_lang_contact_ids(): array
{
    $seed = get_page_by_path('contact');
    if (! $seed) {
        return [];
    }

    $map = [];
    if (function_exists('pll_get_post') && function_exists('pll_languages_list')) {
        foreach ((array) pll_languages_list(['fields' => 'slug']) as $lng) {
            $tid = (int) (pll_get_post($seed->ID, $lng) ?: 0);
            if ($tid > 0) {
                $map[$lng] = $tid;
            }
        }
    }
    if ($map === []) {
        $map['en'] = (int) $seed->ID;
    }

    return $map;
}

/** Flat ACF payload for one language (form tab + page intro fields). */
function sanyuan_contact_form_seed_payload(string $lang): array
{
    $loc = App\sanyuan_contact_form_locale_defaults($lang);
    $keys = App\sanyuan_contact_form_field_keys();

    $payload = [
        'contact_form_email'        => (string) ($loc['email'] ?? ''),
        'contact_form_submit'       => (string) ($loc['submit'] ?? ''),
        'contact_form_region_ph'    => (string) ($loc['region_ph'] ?? ''),
        'contact_form_privacy_pre'  => (string) ($loc['privacy_pre'] ?? ''),
        'contact_form_lbl_name'     => (string) ($loc['labels']['name'] ?? ''),
        'contact_form_lbl_company'  => (string) ($loc['labels']['company'] ?? ''),
        'contact_form_lbl_region'   => (string) ($loc['labels']['region'] ?? ''),
        'contact_form_lbl_country'  => (string) ($loc['labels']['country'] ?? ''),
        'contact_form_lbl_email'    => (string) ($loc['labels']['email'] ?? ''),
        'contact_form_lbl_phone'    => (string) ($loc['labels']['phone'] ?? ''),
        'contact_form_lbl_message'  => (string) ($loc['labels']['message'] ?? ''),
        'contact_form_regions'      => $loc['regions'] ?? [],
        'contact_form_req_name'     => 1,
        'contact_form_req_company'  => 1,
        'contact_form_req_region'   => 1,
        'contact_form_req_country'  => 1,
        'contact_form_req_email'    => 1,
        'contact_form_req_phone'    => 0,
        'contact_form_req_message'  => 1,
        'contact_form_req_privacy'  => 1,
        'contact_form_msg_success'  => (string) ($loc['messages']['success'] ?? ''),
        'contact_form_msg_required' => (string) ($loc['messages']['required'] ?? ''),
        'contact_form_msg_privacy'  => (string) ($loc['messages']['privacy'] ?? ''),
        'contact_form_msg_email'    => (string) ($loc['messages']['email'] ?? ''),
        'contact_form_msg_error'    => (string) ($loc['messages']['error'] ?? ''),
        'contact_form_msg_network'  => (string) ($loc['messages']['network'] ?? ''),
        'contact_text_4'            => "\n        " . trim((string) ($loc['intro_title'] ?? '')) . "\n",
        'contact_text_5'            => "\n        " . trim((string) ($loc['intro_desc'] ?? '')) . "\n",
        'contact_text_6'            => (string) ($loc['privacy_html'] ?? ''),
    ];

    return ['payload' => $payload, 'keys' => $keys];
}

/** Seed one Contact page translation; return written field names. */
function sanyuan_seed_contact_form_page(int $pageId, string $lang): array
{
    if ($pageId <= 0) {
        return [];
    }

    $seeded = [];
    $pack   = sanyuan_contact_form_seed_payload($lang);
    foreach ($pack['payload'] as $name => $value) {
        if ($value === null || $value === '' || $value === []) {
            continue;
        }
        $fieldKey = $pack['keys'][$name] ?? ('field_' . $name);
        if ($name === 'contact_text_4' || $name === 'contact_text_5' || $name === 'contact_text_6') {
            $fieldKey = 'field_' . $name;
        }
        if (sanyuan_seed_field_repair($name, $value, $pageId, $fieldKey)) {
            $seeded[] = "$lang:$name";
        }
    }

    return $seeded;
}

/** Seed Contact form ACF for EN + every translation. */
function sanyuan_seed_contact_form_all(): array
{
    $seeded = [];
    foreach (sanyuan_all_lang_contact_ids() as $lang => $pageId) {
        $seeded = array_merge($seeded, sanyuan_seed_contact_form_page($pageId, $lang));
    }

    return $seeded;
}

if (! defined('SANYUAN_SEED_CONTACT_INCLUDE_ONLY')) {
    $seeded = sanyuan_seed_contact_form_all();
    if (defined('WP_CLI') || PHP_SAPI === 'cli') {
        echo $seeded === []
            ? "Contact form ACF already synced — nothing to seed.\n"
            : "Seeded " . count($seeded) . " contact form field(s):\n  " . implode("\n  ", $seeded) . "\n";
    }
}
