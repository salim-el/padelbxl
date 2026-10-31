<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LocaleController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $locale = $request->validate(['locale' => ['required', Rule::in(SetLocale::LOCALES)]])['locale'];
        $request->session()->put('locale', $locale);
        $request->user()?->forceFill(['locale' => $locale])->save();

        return back();
    }
}
