<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LanguageController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'locale' => ['required', Rule::in(['en', 'bn'])],
        ]);

        $request->session()->put('locale', $data['locale']);
        $request->user()?->update(['language_pref' => $data['locale']]);

        return back();
    }

    public function change(Request $request, string $locale): RedirectResponse
    {
        abort_unless(in_array($locale, ['en', 'bn'], true), 404);

        $request->session()->put('locale', $locale);
        $request->user()?->update(['language_pref' => $locale]);

        return back(fallback: route('about'));
    }
}
