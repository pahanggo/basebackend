<?php

use Carbon\Carbon;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\Session;

if (! function_exists('page')) {
    function page($page)
    {
        $path = resource_path('pages/'.str_replace('.', '/', $page).'.md');
        if (! file_exists($path)) {
            return abort(404);
        }

        return Markdown::parse(file_get_contents($path));
    }
}

if (! function_exists('user')) {
    function user()
    {
        return backpack_user();
    }
}

if (! function_exists('isAssuming')) {
    function isAssuming()
    {
        return Session::has('_assuming_user_id');
    }
}

if (! function_exists('format_currency')) {
    function format_currency($value, $decimals = 2, $currency = 'RM ')
    {
        return $currency.number_format($value, $decimals, '.', ',');
    }
}

if (! function_exists('format_date')) {
    function format_date(Carbon $date)
    {
        return $date->format('j M Y');
    }
}

if (! function_exists('format_datetime')) {
    function format_datetime(Carbon $date)
    {
        return $date->format('j M Y g:i a');
    }
}

if (! function_exists('app_version')) {
    function app_version()
    {
        return json_decode(file_get_contents(base_path('composer.json')))->version;
    }
}

if (! function_exists('username_from_email')) {
    function username_from_email($email)
    {
        if (! trim($email)) {
            return '';
        }

        return explode('@', $email)[0];
    }
}
if (! function_exists('frontend_locale')) {
    /**
     * Resolve the app locale (e.g. "ms_MY") to the locale key a vendor JS package actually ships,
     * given a sprintf pattern for the file's public path (e.g. "packages/select2/dist/js/i18n/%s.js").
     * Tries the locale as-is ("ms_MY"), hyphenated ("ms-MY"), canonicalised to "ll-CC" casing
     * ("zh_CN" -> "zh-CN"), then the bare language subtag ("ms"); returns null when the package
     * ships no matching file.
     *
     * The canonical "ll-CC" candidate matters because vendor packages name these files with an
     * uppercase region ("zh-CN.js"), which will not match a locale written any other way. A
     * case-insensitive filesystem (macOS) hides the mismatch; a case-sensitive one (Linux) does
     * not. The returned value is also fed straight to the library as its `language` option, which
     * is case-sensitive everywhere.
     */
    function frontend_locale(string $pattern, ?string $locale = null): ?string
    {
        $locale = $locale ?: app()->getLocale();
        $parts = explode('_', str_replace('-', '_', $locale));
        $canonical = count($parts) > 1
            ? strtolower($parts[0]).'-'.strtoupper($parts[1])
            : strtolower($parts[0]);

        $candidates = array_unique([
            $locale,
            str_replace('_', '-', $locale),
            $canonical,
            $parts[0],
        ]);

        foreach ($candidates as $candidate) {
            $path = public_path(sprintf($pattern, $candidate));

            // Deliberately not file_exists(): on a case-insensitive filesystem that would
            // match "zh-CN.js" for a candidate like "zh_cn" and hand the caller a locale key
            // the JS library then fails to look up. Compare the basename case-exactly.
            if (in_array(basename($path), scandir(dirname($path)) ?: [], true)) {
                return $candidate;
            }
        }

        return null;
    }
}
