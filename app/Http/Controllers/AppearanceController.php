<?php

namespace App\Http\Controllers;

use App\Services\Theme;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Each person's own look: light or dark, spacing and, when the company allows it, an accent palette. */
class AppearanceController extends Controller
{
    public function edit(Request $request, Theme $theme)
    {
        return view('appearance.edit', [
            'current' => $theme->attributes($request->user()),
            'brand' => $theme->brand(),
            'palettes' => config('panel.theme.palettes'),
        ]);
    }

    public function update(Request $request, Theme $theme)
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in(config('panel.theme.modes'))],
            'density' => ['required', Rule::in(config('panel.theme.densities'))],
            'palette' => ['nullable', Rule::in(array_keys(config('panel.theme.palettes')))],
        ]);

        // A palette only counts while the company allows it; otherwise the choice is not stored at all.
        $data['palette'] = $theme->brand()['allow_palettes'] ? ($data['palette'] ?? '') : '';
        $request->user()->update(['theme_prefs' => $data]);

        return redirect()->route('appearance.edit')->with('status', 'Appearance saved.');
    }
}
