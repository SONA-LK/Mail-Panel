<?php

namespace App\Http\Controllers;

use App\Models\BrandVersion;
use App\Services\Theme;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Brand stylesheet and logo. Public on purpose (the sign-in page is branded too) and free of secrets.
 * The URLs carry the brand version, so browsers may keep them for a year.
 */
class ThemeController extends Controller
{
    private const CACHE = 'public, max-age=31536000, immutable';

    public function css(Request $request, Theme $theme)
    {
        $css = $theme->css();
        $etag = '"'.md5($css).'"';

        if ($request->headers->get('If-None-Match') === $etag) {
            return response('', 304, ['ETag' => $etag, 'Cache-Control' => self::CACHE]);
        }

        return response($css, 200, [
            'Content-Type' => 'text/css; charset=UTF-8',
            'Cache-Control' => self::CACHE,
            'ETag' => $etag,
        ]);
    }

    public function logo()
    {
        $path = BrandVersion::latest('id')->value('logo_path');
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, 'logo.png', [
            'Content-Type' => 'image/png',
            'Cache-Control' => self::CACHE,
        ]);
    }
}
