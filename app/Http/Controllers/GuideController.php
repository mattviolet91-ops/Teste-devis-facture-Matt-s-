<?php

namespace App\Http\Controllers;

use App\Support\Guide;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Guide d'utilisation : chaque fonctionnalité expliquée pas à pas. */
class GuideController extends Controller
{
    public function __invoke(Request $request): View
    {
        return view('guide', ['sections' => Guide::sections($request->user())]);
    }
}
