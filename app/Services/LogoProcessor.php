<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Accepts PNG, JPEG or WebP only and rebuilds the picture from its pixels. Whatever else a file carries
 * (metadata, appended scripts, odd chunks) is thrown away, and the result is always a plain PNG.
 */
class LogoProcessor
{
    private const MAX_WIDTH = 480;

    private const MAX_HEIGHT = 120;

    /** @return string PNG bytes */
    public function process(UploadedFile $file): string
    {
        if (! extension_loaded('gd')) {
            throw new RuntimeException('The PHP GD extension is needed to process logos safely. Ask your host to enable it.');
        }

        $max = (int) config('panel.theme.logo_max_bytes');
        if (! $file->isValid() || $file->getSize() === false || $file->getSize() > $max) {
            throw new RuntimeException('The logo must be smaller than '.round($max / 1048576, 1).' MB.');
        }

        $raw = (string) file_get_contents($file->getRealPath());
        $info = @getimagesizefromstring($raw);
        $side = (int) config('panel.theme.logo_max_pixels');

        if (! $info || ! in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) {
            throw new RuntimeException('The logo must be a PNG, JPG or WebP picture. SVG and other formats are not accepted.');
        }
        [$w, $h] = $info;
        if ($w < 1 || $h < 1 || $w > $side || $h > $side || $w * $h > 9_000_000) {
            throw new RuntimeException("The logo is too large in pixels (at most {$side} x {$side}).");
        }

        $src = @imagecreatefromstring($raw);
        if (! $src) {
            throw new RuntimeException('That picture could not be read. Export it again as PNG or JPG.');
        }

        $scale = min(1, self::MAX_WIDTH / $w, self::MAX_HEIGHT / $h);
        $tw = max(1, (int) round($w * $scale));
        $th = max(1, (int) round($h * $scale));

        $out = imagecreatetruecolor($tw, $th);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
        imagecopyresampled($out, $src, 0, 0, 0, 0, $tw, $th, $w, $h);

        ob_start();
        imagepng($out, null, 9);
        $png = (string) ob_get_clean();

        return $png;
    }
}
