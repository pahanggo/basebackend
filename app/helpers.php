<?php

use Carbon\Carbon;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\Session;

if(!function_exists('page')) {
    function page($page) {
        $path = resource_path('pages/' . str_replace('.', '/', $page) . '.md');
        if(!file_exists($path)) {
            return abort(404);
        }

        return Markdown::parse(file_get_contents($path));
    }
}

if(!function_exists('user')) {
    function user() {
        return backpack_user();
    }
}

if(!function_exists('isAssuming')) {
    function isAssuming() {
        return Session::has('_assuming_user_id');
    }
}

if(!function_exists('format_currency')) {
    function format_currency($value, $decimals = 2, $currency = 'RM ') {
        return $currency . number_format($value, $decimals, '.', ',');
    }
}

if(!function_exists('format_date')) {
    function format_date(Carbon $date) {
        return $date->format('j M Y');
    }
}

if(!function_exists('format_datetime')) {
    function format_datetime(Carbon $date) {
        return $date->format('j M Y g:i a');
    }
}

if(!function_exists('app_version')) {
    function app_version() {
        return json_decode(file_get_contents(base_path('composer.json')))->version;
    }
}