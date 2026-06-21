<?php

/**
 * Contact page form — ACF settings + mirror HTML overrides.
 */

namespace App;

/** ACF field name => local field key (for seeding / reference meta). */
function sanyuan_contact_form_field_keys(): array
{
    return [
        'contact_form_email'        => 'field_cf_email',
        'contact_form_submit'       => 'field_cf_submit',
        'contact_form_region_ph'    => 'field_cf_region_ph',
        'contact_form_privacy_pre'  => 'field_cf_privacy_pre',
        'contact_form_lbl_name'     => 'field_cf_lbl_name',
        'contact_form_lbl_company'  => 'field_cf_lbl_company',
        'contact_form_lbl_region'   => 'field_cf_lbl_region',
        'contact_form_lbl_country'  => 'field_cf_lbl_country',
        'contact_form_lbl_email'    => 'field_cf_lbl_email',
        'contact_form_lbl_phone'    => 'field_cf_lbl_phone',
        'contact_form_lbl_message'  => 'field_cf_lbl_message',
        'contact_form_regions'      => 'field_cf_regions',
        'contact_form_req_name'     => 'field_cf_req_name',
        'contact_form_req_company'  => 'field_cf_req_company',
        'contact_form_req_region'   => 'field_cf_req_region',
        'contact_form_req_country'  => 'field_cf_req_country',
        'contact_form_req_email'    => 'field_cf_req_email',
        'contact_form_req_phone'    => 'field_cf_req_phone',
        'contact_form_req_message'  => 'field_cf_req_message',
        'contact_form_req_privacy'  => 'field_cf_req_privacy',
        'contact_form_msg_success'  => 'field_cf_msg_success',
        'contact_form_msg_required' => 'field_cf_msg_required',
        'contact_form_msg_privacy'  => 'field_cf_msg_privacy',
        'contact_form_msg_email'    => 'field_cf_msg_email',
        'contact_form_msg_error'    => 'field_cf_msg_error',
        'contact_form_msg_network'  => 'field_cf_msg_network',
    ];
}

/** Locale-specific sample defaults (used before/without ACF). */
function sanyuan_contact_form_locale_defaults(string $lang = ''): array
{
    if ($lang === '' && function_exists(__NAMESPACE__ . '\\current_lang')) {
        $lang = current_lang();
    }

    if ($lang === 'zh') {
        return [
            'email'       => 'info@sanyuancable.com.cn',
            'submit'      => '提交',
            'region_ph'   => '（请选）',
            'privacy_pre' => '我已接受',
            'intro_title' => '联系我们',
            'intro_desc'  => '无论您需要产品介绍、技术支持、定制线缆报价，还是其他任何帮助，我们随时恭候您的垂询。请不要犹豫，立即联系我们。',
            'privacy_html' => "\n    <p style=\"font-size:14px;line-height:24px\"><span style=\"font-size:20px;\"><span style=\"color:#d40b1c;\"><a href=\"policy.html\" target=\"_blank\"><u>三元的隐私政策</u></a></span></span></p>\n\n",
            'labels'      => [
                'name'    => '姓名',
                'company' => '公司',
                'region'  => '地区',
                'country' => '国家',
                'email'   => '电子邮件',
                'phone'   => '电话',
                'message' => '消息',
            ],
            'regions' => [
                ['label' => '欧洲'],
                ['label' => '中东与非洲'],
                ['label' => '美国，北美与南美'],
                ['label' => '亚洲与澳大利亚/大洋洲'],
            ],
            'messages' => [
                'success'  => '感谢您的留言，我们会尽快与您联系。',
                'required' => '请填写此字段。',
                'privacy'  => '请接受隐私政策。',
                'email'    => '请输入有效的邮箱地址。',
                'error'    => '提交失败，请稍后重试。',
                'network'  => '网络错误，请稍后重试。',
            ],
        ];
    }

    return [
        'email'       => 'info@sanyuancable.com.cn',
        'submit'      => 'Send',
        'region_ph'   => '(Please select)',
        'privacy_pre' => "I've accept the",
        'intro_title' => 'Contact us',
        'intro_desc'  => 'We’re always available for you, whether you need product details, technical support, a customized cable quote, or more. Please don’t hesitate to contact us.',
        'privacy_html' => "\n    <p style=\"font-size:14px;line-height:24px\"><span style=\"font-size:20px;\"><span style=\"color:#d40b1c;\"><a href=\"policy.html\" target=\"_blank\"><u>privacy.policy</u></a>&nbsp;</span>of Sanyuan.</span></p>\n\n",
        'labels'      => [
            'name'    => 'Name',
            'company' => 'Company',
            'region'  => 'Region',
            'country' => 'Country',
            'email'   => 'Email',
            'phone'   => 'Phone',
            'message' => 'Message',
        ],
        'regions' => [
            ['label' => 'Europe'],
            ['label' => 'Middle East & Africa'],
            ['label' => 'America, North & South'],
            ['label' => 'Asia & Australia/Oceania'],
        ],
        'messages' => [
            'success'  => 'Thank you! Your message has been sent.',
            'required' => 'This field is required.',
            'privacy'  => 'Please accept the privacy policy.',
            'email'    => 'Please enter a valid email address.',
            'error'    => 'Could not send your message. Please try again.',
            'network'  => 'Network error. Please try again.',
        ],
    ];
}

/** Default mirror labels / regions for the current language. */
function sanyuan_contact_form_defaults(): array
{
    $loc = sanyuan_contact_form_locale_defaults();

    return [
        'email'        => (string) ($loc['email'] ?? ''),
        'submit'       => (string) ($loc['submit'] ?? 'Send'),
        'region_ph'    => (string) ($loc['region_ph'] ?? ''),
        'privacy_pre'  => (string) ($loc['privacy_pre'] ?? ''),
        'intro_title'  => (string) ($loc['intro_title'] ?? ''),
        'intro_desc'   => (string) ($loc['intro_desc'] ?? ''),
        'privacy_html' => (string) ($loc['privacy_html'] ?? ''),
        'labels'       => $loc['labels'] ?? [],
        'regions'      => $loc['regions'] ?? [],
        'required'     => [
            'name'    => true,
            'company' => true,
            'region'  => true,
            'country' => true,
            'email'   => true,
            'phone'   => false,
            'message' => true,
            'privacy' => true,
        ],
        'messages' => $loc['messages'] ?? sanyuan_contact_ui_messages(),
    ];
}

/** ACF field group appended to the Contact page editor. */
function sanyuan_contact_form_field_defs(): array
{
    if (! function_exists('acf_validate_field')) {
        return [];
    }

    $req = static fn (string $key, string $label, bool $def = true): array => [
        'key' => 'field_cf_req_' . $key, 'name' => 'contact_form_req_' . $key,
        'label' => $label, 'type' => 'true_false', 'ui' => 1,
        'default_value' => $def ? 1 : 0, 'wrapper' => ['width' => '25'],
    ];

    return array_map('acf_validate_field', [
        ['key' => 'field_tab_cf', 'label' => 'Contact form', 'type' => 'tab', 'placement' => 'top'],
        ['key' => 'field_cf_note', 'name' => '_contact_form_note', 'label' => '', 'type' => 'message',
         'message' => 'Email, labels, regions, validation and messages for the contact form on this page.',
         'new_lines' => 'wpautop'],
        ['key' => 'field_cf_email', 'name' => 'contact_form_email', 'label' => 'Recipient email',
         'type' => 'email', 'instructions' => 'Leave empty to use Footer → Email, then WordPress admin email.'],
        ['key' => 'field_cf_submit', 'name' => 'contact_form_submit', 'label' => 'Submit button',
         'type' => 'text', 'placeholder' => 'Send', 'wrapper' => ['width' => '50']],
        ['key' => 'field_cf_region_ph', 'name' => 'contact_form_region_ph', 'label' => 'Region placeholder',
         'type' => 'text', 'placeholder' => '(Please select)', 'wrapper' => ['width' => '50']],
        ['key' => 'field_cf_privacy_pre', 'name' => 'contact_form_privacy_pre', 'label' => 'Privacy checkbox prefix',
         'type' => 'text', 'placeholder' => "I've accept the"],

        ['key' => 'field_cf_hdr_labels', 'name' => '_contact_form_hdr_labels', 'label' => '', 'type' => 'message',
         'message' => '<strong>Field labels</strong>', 'esc_html' => 0, 'new_lines' => ''],
        ['key' => 'field_cf_lbl_name', 'name' => 'contact_form_lbl_name', 'label' => 'Name',
         'type' => 'text', 'placeholder' => 'Name', 'wrapper' => ['width' => '33']],
        ['key' => 'field_cf_lbl_company', 'name' => 'contact_form_lbl_company', 'label' => 'Company',
         'type' => 'text', 'placeholder' => 'Company', 'wrapper' => ['width' => '33']],
        ['key' => 'field_cf_lbl_region', 'name' => 'contact_form_lbl_region', 'label' => 'Region',
         'type' => 'text', 'placeholder' => 'Region', 'wrapper' => ['width' => '34']],
        ['key' => 'field_cf_lbl_country', 'name' => 'contact_form_lbl_country', 'label' => 'Country',
         'type' => 'text', 'placeholder' => 'Country', 'wrapper' => ['width' => '33']],
        ['key' => 'field_cf_lbl_email', 'name' => 'contact_form_lbl_email', 'label' => 'Email',
         'type' => 'text', 'placeholder' => 'Email', 'wrapper' => ['width' => '33']],
        ['key' => 'field_cf_lbl_phone', 'name' => 'contact_form_lbl_phone', 'label' => 'Phone',
         'type' => 'text', 'placeholder' => 'Phone', 'wrapper' => ['width' => '34']],
        ['key' => 'field_cf_lbl_message', 'name' => 'contact_form_lbl_message', 'label' => 'Message',
         'type' => 'text', 'placeholder' => 'Message'],

        ['key' => 'field_cf_hdr_regions', 'name' => '_contact_form_hdr_regions', 'label' => '', 'type' => 'message',
         'message' => '<strong>Region options</strong>', 'esc_html' => 0, 'new_lines' => ''],
        ['key' => 'field_cf_regions', 'name' => 'contact_form_regions', 'label' => 'Regions',
         'type' => 'repeater', 'layout' => 'table', 'button_label' => 'Add region',
         'sub_fields' => [
            ['key' => 'field_cf_region_label', 'name' => 'label', 'label' => 'Label', 'type' => 'text'],
         ]],

        ['key' => 'field_cf_hdr_required', 'name' => '_contact_form_hdr_required', 'label' => '', 'type' => 'message',
         'message' => '<strong>Required fields</strong>', 'esc_html' => 0, 'new_lines' => ''],
        $req('name', 'Name'), $req('company', 'Company'), $req('region', 'Region'),
        $req('country', 'Country'), $req('email', 'Email'), $req('phone', 'Phone', false),
        $req('message', 'Message'), $req('privacy', 'Privacy checkbox'),

        ['key' => 'field_cf_hdr_messages', 'name' => '_contact_form_hdr_messages', 'label' => '', 'type' => 'message',
         'message' => '<strong>Validation messages</strong>', 'esc_html' => 0, 'new_lines' => ''],
        ['key' => 'field_cf_msg_success', 'name' => 'contact_form_msg_success', 'label' => 'Success message', 'type' => 'text'],
        ['key' => 'field_cf_msg_required', 'name' => 'contact_form_msg_required', 'label' => 'Required field', 'type' => 'text'],
        ['key' => 'field_cf_msg_privacy', 'name' => 'contact_form_msg_privacy', 'label' => 'Privacy required', 'type' => 'text'],
        ['key' => 'field_cf_msg_email', 'name' => 'contact_form_msg_email', 'label' => 'Invalid email', 'type' => 'text'],
        ['key' => 'field_cf_msg_error', 'name' => 'contact_form_msg_error', 'label' => 'Submit error', 'type' => 'text'],
        ['key' => 'field_cf_msg_network', 'name' => 'contact_form_msg_network', 'label' => 'Network error', 'type' => 'text'],
    ]);
}

/** Whether a contact-form ACF field was saved on this page (incl. empty repeater). */
function sanyuan_contact_form_has_meta(int $pageId, string $name): bool
{
    return metadata_exists('post', $pageId, $name)
        || metadata_exists('post', $pageId, '_' . $name);
}

/** Read repeater rows from ACF; null = never configured, [] = explicitly empty. */
function sanyuan_contact_form_read_regions(int $pageId): ?array
{
    if (! sanyuan_contact_form_has_meta($pageId, 'contact_form_regions')) {
        return null;
    }

    $raw = get_field('contact_form_regions', $pageId);
    if (! is_array($raw)) {
        $raw = get_post_meta($pageId, 'contact_form_regions', true);
    }
    if (! is_array($raw)) {
        return [];
    }

    return array_values(array_filter(array_map(
        static fn ($row): array => is_array($row) && trim((string) ($row['label'] ?? '')) !== ''
            ? ['label' => trim((string) $row['label'])]
            : [],
        $raw
    )));
}

/** Read merged form config for a Contact page (Polylang-aware page ID). */
function sanyuan_contact_form_config(int $pageId = 0): array
{
    static $cache = [];
    if (isset($cache[$pageId])) {
        return $cache[$pageId];
    }

    $cfg = sanyuan_contact_form_defaults();
    if ($pageId <= 0 || ! function_exists('get_field')) {
        return $cache[$pageId] = $cfg;
    }

    $str = static function (string $key) use ($pageId): string {
        $v = get_field($key, $pageId);
        return is_string($v) ? trim($v) : '';
    };
    $bool = static function (string $key, bool $def) use ($pageId): bool {
        $v = get_field($key, $pageId);
        return $v === null || $v === '' ? $def : (bool) $v;
    };

    if ($str('contact_form_email') !== '') {
        $cfg['email'] = $str('contact_form_email');
    }
    foreach (['submit' => 'contact_form_submit', 'region_ph' => 'contact_form_region_ph', 'privacy_pre' => 'contact_form_privacy_pre'] as $k => $acf) {
        if ($str($acf) !== '') {
            $cfg[$k] = $str($acf);
        }
    }
    foreach ($cfg['labels'] as $k => $def) {
        $v = $str('contact_form_lbl_' . $k);
        if ($v !== '') {
            $cfg['labels'][$k] = $v;
        }
    }
    $regions = sanyuan_contact_form_read_regions($pageId);
    if ($regions !== null) {
        $cfg['regions'] = $regions;
    }
    foreach ($cfg['required'] as $k => $def) {
        $cfg['required'][$k] = $bool('contact_form_req_' . $k, $def);
    }
    foreach (['success', 'required', 'privacy', 'email', 'error', 'network'] as $k) {
        $v = $str('contact_form_msg_' . $k);
        if ($v !== '') {
            $cfg['messages'][$k] = $v;
        }
    }

    $introTitle = trim(wp_strip_all_tags((string) get_field('contact_text_4', $pageId)));
    if ($introTitle !== '') {
        $cfg['intro_title'] = $introTitle;
    }
    $introDesc = trim(wp_strip_all_tags((string) get_field('contact_text_5', $pageId)));
    if ($introDesc !== '') {
        $cfg['intro_desc'] = $introDesc;
    }
    $privacyHtml = (string) get_field('contact_text_6', $pageId);
    if (trim($privacyHtml) !== '') {
        $cfg['privacy_html'] = $privacyHtml;
    }

    return $cache[$pageId] = $cfg;
}

/** Replace inner HTML of the first matching element inside the contact form block. */
function sanyuan_contact_form_replace_inner(string $html, string $pattern, string $inner, int $limit = 1): string
{
    if ($inner === '') {
        return $html;
    }

    $out = preg_replace($pattern, '$1' . $inner . '$3', $html, $limit);

    return is_string($out) ? $out : $html;
}

/** Apply ACF-driven labels, regions, intro and button text onto the contact form. */
function sanyuan_apply_contact_form_config(string $html, int $pageId): string
{
    $cfg = sanyuan_contact_form_config($pageId);

    if ($cfg['intro_title'] !== '') {
        $html = sanyuan_contact_form_replace_inner(
            $html,
            '~(<form class="e_form-27[\s\S]*?<p class="e_text-31 s_title">\s*)([\s\S]*?)(\s*</p>)~',
            esc_html($cfg['intro_title'])
        );
    }
    if ($cfg['intro_desc'] !== '') {
        $html = sanyuan_contact_form_replace_inner(
            $html,
            '~(<form class="e_form-27[\s\S]*?<p class="e_text-32 s_title">\s*)([\s\S]*?)(\s*</p>)~',
            esc_html($cfg['intro_desc'])
        );
    }
    if ($cfg['privacy_html'] !== '') {
        $html = sanyuan_contact_form_replace_inner(
            $html,
            '~(<div class="e_richText-53[^"]*"[^>]*>)([\s\S]*?)(</div>)~',
            wp_kses_post($cfg['privacy_html']),
            1
        );
    }

    $map = [
        'e_clueName-34'  => $cfg['labels']['name'],
        'e_input-36'     => $cfg['labels']['company'],
        'e_select-49'    => $cfg['labels']['region'],
        'e_input-38'     => $cfg['labels']['country'],
        'e_clueEmail-39' => $cfg['labels']['email'],
        'e_clueName-48'  => $cfg['labels']['phone'],
        'e_textarea-35'  => $cfg['labels']['message'],
    ];
    foreach ($map as $dataName => $label) {
        $html = preg_replace(
            '~(<[^>]+data-name="' . preg_quote($dataName, '~') . '"[^>]*>.*?<span class="s_label">)(.*?)(</span>)~s',
            '$1' . esc_html($label) . '$3',
            $html,
            1
        ) ?? $html;
    }

    $opts = '<option value="">' . esc_html($cfg['region_ph']) . '</option>';
    foreach ($cfg['regions'] as $row) {
        $label = (string) ($row['label'] ?? '');
        if ($label === '') {
            continue;
        }
        $opts .= '<option value="' . esc_attr(sanitize_title($label)) . '">' . esc_html($label) . '</option>';
    }
    $html = preg_replace(
        '~(<select[^>]*data-name="e_select-49"[^>]*>).*?(</select>)~s',
        '$1' . $opts . '$2',
        $html,
        1
    ) ?? $html;

    $html = preg_replace(
        '~(<label class="form-check-label s_other" for="e_checkbox-52-0">)(.*?)(</label>)~',
        '$1' . esc_html($cfg['privacy_pre']) . '$3',
        $html,
        1
    ) ?? $html;

    return preg_replace(
        '~(<a class="e_formBtn-43[^"]*"[^>]*>\s*<img[^>]*>\s*<span>)(.*?)(</span>)~s',
        '$1' . esc_html($cfg['submit']) . '$3',
        $html,
        1
    ) ?? $html;
}
