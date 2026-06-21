<?php

/**
 * Contact page form — replaces the dead 300.cn /fwebapi submit with wp_mail().
 */

namespace App;

const SANYUAN_CONTACT_NONCE = 'sanyuan_contact';
const SANYUAN_CONTACT_RATE  = 5;
const SANYUAN_CONTACT_WINDOW = 3600;

add_action('rest_api_init', function () {
    register_rest_route('sanyuan/v1', '/contact', [
        'methods'             => 'POST',
        'callback'            => __NAMESPACE__ . '\\sanyuan_contact_handle_submit',
        'permission_callback' => '__return_true',
    ]);
});

/** Resolve Contact page ID for the current language. */
function sanyuan_contact_page_id(): int
{
    return function_exists(__NAMESPACE__ . '\\managed_page_acf_id')
        ? managed_page_acf_id('contact')
        : 0;
}

/** Recipient: page ACF → footer email → site admin. */
function sanyuan_contact_recipient(int $pageId = 0): string
{
    $pageId = $pageId > 0 ? $pageId : sanyuan_contact_page_id();
    $cfg    = sanyuan_contact_form_config($pageId);
    if ($cfg['email'] !== '' && is_email($cfg['email'])) {
        return $cfg['email'];
    }

    $email = shared_option_field('footer_email');
    if (! is_string($email) || ! is_email($email)) {
        $email = get_option('admin_email');
    }

    return is_string($email) ? $email : '';
}

/** UI strings — ACF overrides with language defaults. */
function sanyuan_contact_ui_messages(): array
{
    if (current_lang() === 'zh') {
        return [
            'success'  => '感谢您的留言，我们会尽快与您联系。',
            'required' => '请填写此字段。',
            'privacy'  => '请接受隐私政策。',
            'email'    => '请输入有效的邮箱地址。',
            'error'    => '提交失败，请稍后重试。',
            'network'  => '网络错误，请稍后重试。',
        ];
    }

    return [
        'success'  => 'Thank you! Your message has been sent.',
        'required' => 'This field is required.',
        'privacy'  => 'Please accept the privacy policy.',
        'email'    => 'Please enter a valid email address.',
        'error'    => 'Could not send your message. Please try again.',
        'network'  => 'Network error. Please try again.',
    ];
}

/** Swap CMS form runtime for native WP submit + REST handler. */
function sanyuan_inject_contact_form(string $html, int $pageId = 0): string
{
    if (strpos($html, 'e_form-27') === false) {
        return $html;
    }

    $pageId = $pageId > 0 ? $pageId : sanyuan_contact_page_id();
    $html   = sanyuan_apply_contact_form_config($html, $pageId);
    $cfg    = sanyuan_contact_form_config($pageId);

    $html = preg_replace_callback(
        '~(<form class="e_form-27[\s\S]*?</form>)~',
        static function (array $m): string {
            $block = str_replace('needjs="true"', 'needjs="false"', $m[1]);
            $block = preg_replace(
                '~(<form class="e_form-27[^"]*"[^>]*\b)needjs="false"~',
                '$1needjs="false" id="sanyuan-contact-form" data-sanyuan-contact="1"',
                $block,
                1
            ) ?? $block;
            $block = preg_replace(
                '~<a class="e_formBtn-43([^"]*)"([^>]*)>\s*<img([^>]*)>\s*<span>([^<]*)</span>\s*</a>~',
                '<button type="submit" class="e_formBtn-43$1"$2><img$3><span>$4</span></button>',
                $block,
                1
            ) ?? $block;
            $block = preg_replace('~<input name="jumpPage" type="hidden" value="[^"]*">~', '', $block, 1) ?? $block;

            return preg_replace(
                '~(<form class="e_form-27[^>]*>)~',
                '$1<input type="hidden" name="sanyuan_hp" value="" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px">',
                $block,
                1
            ) ?? $block;
        },
        $html,
        1
    ) ?? $html;

    $msgs   = $cfg['messages'];
    $sent   = isset($_GET['sent']) && (string) $_GET['sent'] === '1';
    $config = wp_json_encode([
        'restUrl'  => rest_url('sanyuan/v1/contact'),
        'nonce'    => wp_create_nonce(SANYUAN_CONTACT_NONCE),
        'redirect' => home_url(lang_base_path() . 'contact/?sent=1'),
        'messages' => $msgs,
        'required' => $cfg['required'],
        'sent'     => $sent,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($sent) {
        $banner = '<div class="sanyuan-contact-success alert alert-success" role="status" style="margin:1rem 0;padding:12px 16px;border-radius:4px;background:#d4edda;color:#155724">'
            . esc_html($msgs['success'])
            . '</div>';
        $html = preg_replace('~(<div class="ptishiCon">)~', '$1' . $banner, $html, 1) ?? $html;
    }

    $script = '<script id="sanyuan-contact-form-js">'
        . '(function(){var c=' . $config . ';var f=document.getElementById("sanyuan-contact-form");'
        . 'if(!f)return;var R=c.required||{};function q(n){return f.querySelector(\'[data-name="\'+n+\'"]\');}'
        . 'function v(n){var e=q(n);return e?(e.value||"").trim():"";}'
        . 'function setErr(n,m){var e=q(n);if(!e)return;var w=e.closest(".form-group");if(!w)return;'
        . 'var b=w.querySelector(".invalid-feedback");if(b)b.textContent=m;w.classList.add("was-validated");}'
        . 'function clearErr(){f.querySelectorAll(".form-group.was-validated").forEach(function(g){'
        . 'g.classList.remove("was-validated");var b=g.querySelector(".invalid-feedback");if(b)b.textContent="";});}'
        . 'var fields=[["e_clueName-34","name"],["e_input-36","company"],["e_select-49","region"],'
        . '["e_input-38","country"],["e_clueEmail-39","email"],["e_clueName-48","phone"],["e_textarea-35","message"]];'
        . 'f.addEventListener("submit",function(ev){ev.preventDefault();clearErr();var m=c.messages,ok=true;'
        . 'fields.forEach(function(p){if(R[p[1]]&&(!v(p[0])||(p[0]==="e_select-49"&&q(p[0])&&q(p[0]).selectedIndex<1)))'
        . '{setErr(p[0],m.required);ok=false;}});'
        . 'var em=v("e_clueEmail-39");if(R.email&&em&&!/^[^\\s@]+@[^\\s@]+\\.[^\\s@]+$/.test(em)){setErr("e_clueEmail-39",m.email);ok=false;}'
        . 'var cb=q("e_checkbox-52");if(R.privacy&&(!cb||!cb.checked)){setErr("e_checkbox-52",m.privacy);ok=false;}'
        . 'if(!ok)return;var sel=q("e_select-49");var region=sel&&sel.selectedIndex>0?sel.options[sel.selectedIndex].text:"";'
        . 'var btn=f.querySelector(".e_formBtn-43");if(btn)btn.disabled=true;'
        . 'fetch(c.restUrl,{method:"POST",headers:{"Content-Type":"application/json","X-Sanyuan-Nonce":c.nonce},'
        . 'body:JSON.stringify({name:v("e_clueName-34"),company:v("e_input-36"),region:region,country:v("e_input-38"),'
        . 'email:em,phone:v("e_clueName-48"),message:v("e_textarea-35"),privacy:!!(cb&&cb.checked),'
        . 'sanyuan_hp:(f.querySelector(\'[name="sanyuan_hp"]\')||{}).value||""})})'
        . '.then(function(r){return r.json().then(function(d){return{ok:r.ok,data:d};});})'
        . '.then(function(r){if(r.ok&&r.data&&r.data.success){window.location.href=r.data.redirect||c.redirect;return;}'
        . 'alert((r.data&&r.data.message)||m.error);if(btn)btn.disabled=false;})'
        . '.catch(function(){alert(m.network);if(btn)btn.disabled=false;});});'
        . 'if(c.sent){var s=f.closest("[id^=c_static_]");if(s)s.scrollIntoView({behavior:"smooth",block:"start"});}'
        . '})();</script>';

    return preg_replace('~</body>~i', $script . '</body>', $html, 1) ?? $html;
}

/** REST POST handler — validate, rate-limit, send mail. */
function sanyuan_contact_handle_submit(\WP_REST_Request $request): \WP_REST_Response
{
    $pageId = sanyuan_contact_page_id();
    $cfg    = sanyuan_contact_form_config($pageId);
    $msgs   = $cfg['messages'];

    if ((string) $request->get_param('sanyuan_hp') !== '') {
        return new \WP_REST_Response(['success' => false, 'message' => $msgs['error']], 400);
    }

    $nonce = $request->get_header('X-Sanyuan-Nonce')
        ?: (string) $request->get_param('nonce')
        ?: (string) $request->get_param('_wpnonce');
    if (! wp_verify_nonce($nonce, SANYUAN_CONTACT_NONCE)) {
        return new \WP_REST_Response(['success' => false, 'message' => $msgs['error']], 403);
    }

    if (! sanyuan_contact_rate_ok()) {
        return new \WP_REST_Response(['success' => false, 'message' => $msgs['error']], 429);
    }

    $data = sanyuan_contact_sanitize_payload($request);
    $err  = sanyuan_contact_validate_payload($data, $msgs, $cfg['required']);
    if ($err !== '') {
        return new \WP_REST_Response(['success' => false, 'message' => $err], 422);
    }

    $to = sanyuan_contact_recipient($pageId);
    if ($to === '') {
        return new \WP_REST_Response(['success' => false, 'message' => $msgs['error']], 500);
    }

    $subject = sprintf('[%s] Contact: %s', wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES), $data['name']);
    $body    = sanyuan_contact_email_body($data);
    $headers = [
        'Content-Type: text/plain; charset=UTF-8',
        'Reply-To: ' . $data['name'] . ' <' . $data['email'] . '>',
    ];

    if (! wp_mail($to, $subject, $body, $headers)) {
        return new \WP_REST_Response(['success' => false, 'message' => $msgs['error']], 500);
    }

    sanyuan_contact_bump_rate();

    return new \WP_REST_Response([
        'success'  => true,
        'redirect' => home_url(lang_base_path() . 'contact/?sent=1'),
    ], 200);
}

function sanyuan_contact_sanitize_payload(\WP_REST_Request $request): array
{
    return [
        'name'    => sanitize_text_field((string) $request->get_param('name')),
        'company' => sanitize_text_field((string) $request->get_param('company')),
        'region'  => sanitize_text_field((string) $request->get_param('region')),
        'country' => sanitize_text_field((string) $request->get_param('country')),
        'email'   => sanitize_email((string) $request->get_param('email')),
        'phone'   => sanitize_text_field((string) $request->get_param('phone')),
        'message' => sanitize_textarea_field((string) $request->get_param('message')),
        'privacy' => (bool) $request->get_param('privacy'),
    ];
}

function sanyuan_contact_validate_payload(array $data, array $msgs, array $required): string
{
    $keys = ['name', 'company', 'region', 'country', 'email', 'message'];
    foreach ($keys as $key) {
        if (! empty($required[$key]) && $data[$key] === '') {
            return $msgs['required'];
        }
    }
    if (! empty($required['email'])) {
        if ($data['email'] === '' || ! is_email($data['email'])) {
            return $msgs['email'];
        }
    }
    if (! empty($required['phone']) && $data['phone'] === '') {
        return $msgs['required'];
    }
    if (! empty($required['privacy']) && ! $data['privacy']) {
        return $msgs['privacy'];
    }

    return '';
}

function sanyuan_contact_email_body(array $data): string
{
    $lines = [
        'Name: ' . $data['name'],
        'Company: ' . $data['company'],
        'Region: ' . $data['region'],
        'Country: ' . $data['country'],
        'Email: ' . $data['email'],
        'Phone: ' . ($data['phone'] !== '' ? $data['phone'] : '(not provided)'),
        '',
        'Message:',
        $data['message'],
        '',
        'Sent from: ' . home_url(lang_base_path() . 'contact/'),
        'IP: ' . (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
    ];

    return implode("\n", $lines);
}

function sanyuan_contact_rate_key(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

    return 'sanyuan_contact_' . md5($ip);
}

function sanyuan_contact_rate_ok(): bool
{
    $count = (int) get_transient(sanyuan_contact_rate_key());

    return $count < SANYUAN_CONTACT_RATE;
}

function sanyuan_contact_bump_rate(): void
{
    $key   = sanyuan_contact_rate_key();
    $count = (int) get_transient($key);
    set_transient($key, $count + 1, SANYUAN_CONTACT_WINDOW);
}
