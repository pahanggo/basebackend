<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LocaleController extends Controller
{
    /**
     * Switch the interface language and return to the page the user came from.
     *
     * The locale is validated against config('app.available_locales') rather than
     * trusted from the URL, so the route cannot be used to write arbitrary values
     * into the session. App\Http\Middleware\SetLocale applies it on the next request.
     */
    public function switch(Request $request, string $locale): RedirectResponse
    {
        abort_unless(array_key_exists($locale, config('app.available_locales', [])), 404);

        $request->session()->put('locale', $locale);

        return redirect()->back(fallback: backpack_url('dashboard'));
    }
}
