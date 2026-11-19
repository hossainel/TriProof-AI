<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

class PublicPageController extends Controller
{
    public function about(): View
    {
        return view('about', [
            'team' => config('team', []),
        ]);
    }

    public function privacy(): View
    {
        return view('legal', [
            'title' => trans('about.privacy_title'),
            'description' => trans('about.privacy_description'),
            'paragraphs' => trans('about.privacy_body'),
        ]);
    }

    public function terms(): View
    {
        return view('legal', [
            'title' => trans('about.terms_title'),
            'description' => trans('about.terms_description'),
            'paragraphs' => trans('about.terms_body'),
        ]);
    }
}
