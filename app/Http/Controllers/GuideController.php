<?php

namespace App\Http\Controllers;

use App\Support\GettingStarted;
use App\Support\Guide;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Guide d'utilisation : chaque fonctionnalité expliquée pas à pas. */
class GuideController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();

        return view('guide', [
            'sections' => Guide::sections($user),
            'checklist' => $user->isAdmin() ? GettingStarted::items($user) : [],
        ]);
    }
}
