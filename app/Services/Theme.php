<?php

namespace App\Services;

use App\Install\Installation;
use App\Models\BrandVersion;
use App\Models\User;
use App\Support\Color;
use Throwable;

/**
 * Turns the company brand and a person's preferences into page attributes and one generated stylesheet.
 * Only validated colours, fixed font stacks and fixed palette names ever reach the CSS, never free text.
 */
class Theme
{
    private const CARD_LIGHT = '#ffffff';

    private const CARD_DARK = '#171c28';

    private ?array $brand = null;

    /** @return array{name:?string,primary:string,header:string,font:string,allow_palettes:bool,logo:bool,version:int} */
    public function brand(): array
    {
        if ($this->brand !== null) {
            return $this->brand;
        }

        $brand = config('panel.theme.default') + ['logo' => false, 'version' => 0];

        try {
            if (Installation::isInstalled() && ($row = BrandVersion::latest('id')->first())) {
                $brand = [
                    'name' => $row->name, 'primary' => $row->primary, 'header' => $row->header, 'font' => $row->font,
                    'allow_palettes' => $row->allow_palettes, 'logo' => (bool) $row->logo_path, 'version' => $row->id,
                ];
            }
        } catch (Throwable) {
            // No database yet (installer) or the table is missing: show the default look.
        }

        return $this->brand = $brand;
    }

    public function forget(): void
    {
        $this->brand = null;
    }

    public function name(): string
    {
        return $this->brand()['name'] ?: config('app.name');
    }

    /** Changes whenever the brand or the shipped palettes change, so the browser cache never serves a stale look. */
    public function version(): string
    {
        return $this->brand()['version'].'-'.substr(md5(json_encode([config('panel.theme.palettes'), config('panel.theme.fonts')])), 0, 6);
    }

    /** @return array{mode:string,density:string,palette:string} */
    public function attributes(?User $user): array
    {
        $prefs = (array) ($user?->theme_prefs ?? []);
        $palette = (string) ($prefs['palette'] ?? '');

        return [
            'mode' => in_array($prefs['mode'] ?? null, config('panel.theme.modes'), true) ? $prefs['mode'] : 'system',
            'density' => in_array($prefs['density'] ?? null, config('panel.theme.densities'), true) ? $prefs['density'] : 'comfortable',
            'palette' => $this->brand()['allow_palettes'] && isset(config('panel.theme.palettes')[$palette]) ? $palette : '',
        ];
    }

    /** The stylesheet for a brand (the live one by default, or a validated preview). */
    public function css(?array $brand = null): string
    {
        $brand ??= $this->brand();
        $fonts = config('panel.theme.fonts');
        $header = $brand['header'];
        $headInk = Color::ink($header);

        $root = [
            '--font' => ($fonts[$brand['font']] ?? $fonts['system'])['stack'],
            '--head' => $header,
            '--head-ink' => $headInk,
            '--head-nav' => Color::mix($headInk, $header, 0.2),
            '--head-hover' => Color::mix($header, $headInk, 0.12),
            '--head-line' => Color::mix($header, $headInk, 0.35),
        ] + $this->accent($brand['primary']);

        $css = ':root'.$this->block($root)."\n"
            .'html[data-mode=dark]'.$this->block($this->link($brand['primary'], true))."\n"
            .'@media (prefers-color-scheme:dark){html[data-mode=system]'.$this->block($this->link($brand['primary'], true))."}\n";

        foreach (config('panel.theme.palettes') as $key => $palette) {
            $sel = 'html[data-palette='.$key.']';
            $css .= $sel.$this->block($this->accent($palette['primary']))."\n"
                .$sel.'[data-mode=dark]'.$this->block($this->link($palette['primary'], true))."\n"
                .'@media (prefers-color-scheme:dark){'.$sel.'[data-mode=system]'.$this->block($this->link($palette['primary'], true))."}\n";
        }

        return $css;
    }

    /** Button colour, the text on it, and a link colour that is readable on a light page. */
    private function accent(string $primary): array
    {
        return ['--brand' => $primary, '--brand-ink' => Color::ink($primary)] + $this->link($primary, false);
    }

    private function link(string $primary, bool $dark): array
    {
        return ['--link' => Color::readable($primary, $dark ? self::CARD_DARK : self::CARD_LIGHT)];
    }

    private function block(array $vars): string
    {
        return '{'.implode(';', array_map(fn ($k, $v) => "$k:$v", array_keys($vars), $vars)).'}';
    }
}
