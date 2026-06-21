<?php

/**
 * Shared ACF media helpers: detect cleared fields vs never-edited, resolve URLs, hide mirror images.
 */

namespace App;

/** 1×1 transparent GIF — truthy where empty string would fall back to mirror defaults. */
function sanyuan_empty_image_url(): string
{
    return 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
}

/** True once the field was saved in wp-admin (including cleared image/file/text). */
function sanyuan_acf_field_is_set(string $field, int|string $context): bool
{
    if ($context === 'option') {
        return metadata_exists('option', 'options', $field)
            || metadata_exists('option', 'options', '_' . $field);
    }

    $pid = (int) $context;
    if ($pid <= 0) {
        return false;
    }

    return metadata_exists('post', $pid, $field)
        || metadata_exists('post', $pid, '_' . $field);
}

/** Resolve an ACF image/file value (URL string, attachment ID, or array) to a public URL. */
function sanyuan_acf_image_url(mixed $value): string
{
    if (is_string($value) && $value !== '') {
        return $value;
    }
    if (is_array($value)) {
        $url = $value['url'] ?? '';

        return is_string($url) ? $url : '';
    }
    if (is_numeric($value) && (int) $value > 0) {
        $url = wp_get_attachment_url((int) $value);

        return is_string($url) && $url !== '' ? $url : '';
    }

    return '';
}

/** Hide every mirror <img> whose src references the given assets_img basename. */
function sanyuan_hide_mirror_images_with_basename(string $html, string $basename): string
{
    if ($basename === '') {
        return $html;
    }

    $q = preg_quote($basename, '~');

    return preg_replace_callback(
        '~<img\b[^>]*\bsrc="[^"]*(?:\.\./)*assets_img/' . $q . '"[^>]*>~i',
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

/** True when at least one field in the list was saved in wp-admin. */
function sanyuan_any_acf_field_set(int|string $context, array $fields): bool
{
    foreach ($fields as $field) {
        if (sanyuan_acf_field_is_set((string) $field, $context)) {
            return true;
        }
    }

    return false;
}

/** Inject or append a <style> block before </head> (deduped by id). */
function sanyuan_inject_head_style(string $html, string $css, string $styleId): string
{
    if ($css === '' || str_contains($html, 'id="' . $styleId . '"')) {
        return $html;
    }

    return str_replace('</head>', '<style id="' . esc_attr($styleId) . '">' . $css . '</style></head>', $html);
}
