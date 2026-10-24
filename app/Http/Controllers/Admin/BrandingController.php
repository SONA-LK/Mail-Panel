<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BrandVersion;
use App\Services\Audit;
use App\Services\LogoProcessor;
use App\Services\Theme;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;

/** Company branding. Every save is a new version, so a bad change can be undone and each one is audited. */
class BrandingController extends Controller
{
    public function index(Request $request, Theme $theme)
    {
        $preview = $this->previewFrom($request);

        return view('admin.branding', [
            'brand' => $preview ?? $theme->brand(),
            'previewing' => $preview !== null,
            'fonts' => config('panel.theme.fonts'),
            'versions' => BrandVersion::with('author')->latest('id')->limit(BrandVersion::KEEP)->get(),
            'currentId' => $theme->brand()['version'],
        ]);
    }

    /** The preview stylesheet: only validated colours and a font name go in, so no free text reaches the CSS. */
    public function previewCss(Request $request, Theme $theme)
    {
        $brand = $this->previewFrom($request);
        abort_unless($brand, 404);

        return response($theme->css($brand), 200, ['Content-Type' => 'text/css; charset=UTF-8']);
    }

    public function update(Request $request, Theme $theme, LogoProcessor $logos)
    {
        $data = $request->validate($this->rules() + ['logo' => ['nullable', 'file'], 'remove_logo' => ['nullable', 'boolean']]);
        $current = BrandVersion::latest('id')->first();

        $logoPath = $current?->logo_path;
        $logoChange = 'unchanged';
        if ($request->boolean('remove_logo')) {
            $logoPath = null;
            $logoChange = $current?->logo_path ? 'removed' : 'unchanged';
        }
        if ($request->hasFile('logo')) {
            try {
                $png = $logos->process($request->file('logo'));
            } catch (RuntimeException $e) {
                return back()->withInput($request->except('logo'))->withErrors(['logo' => $e->getMessage()]);
            }
            $logoPath = 'brand/'.Str::random(40).'.png';
            Storage::disk('local')->put($logoPath, $png);
            $logoChange = 'replaced';
        }

        $version = BrandVersion::create([
            'name' => $data['name'] ?? null,
            'primary' => strtolower($data['primary']),
            'header' => strtolower($data['header']),
            'font' => $data['font'],
            'allow_palettes' => $request->boolean('allow_palettes'),
            'logo_path' => $logoPath,
            'created_by' => $request->user()->id,
        ]);
        $this->prune();
        $theme->forget();

        Audit::record($request->user(), 'brand.update', "brand #{$version->id}", null, [
            'name' => $version->name, 'primary' => $version->primary, 'header' => $version->header,
            'font' => $version->font, 'allow_palettes' => $version->allow_palettes, 'logo' => $logoChange,
        ]);

        return redirect()->route('admin.branding')->with('status', 'Branding saved. Everyone sees it on their next page.');
    }

    public function restore(Request $request, Theme $theme, BrandVersion $version)
    {
        $new = BrandVersion::create([
            'name' => $version->name, 'primary' => $version->primary, 'header' => $version->header, 'font' => $version->font,
            'allow_palettes' => $version->allow_palettes, 'logo_path' => $version->logo_path,
            'created_by' => $request->user()->id, 'note' => "Restored from #{$version->id}",
        ]);
        $theme->forget();
        Audit::record($request->user(), 'brand.restore', "brand #{$new->id}", null, ['from' => $version->id]);

        return redirect()->route('admin.branding')->with('status', "Branding #{$version->id} is live again.");
    }

    private function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:60', "regex:/^[\\pL\\pN .,&'()_-]+$/u"],
            'primary' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'header' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'font' => ['required', Rule::in(array_keys(config('panel.theme.fonts')))],
            'allow_palettes' => ['nullable', 'boolean'],
        ];
    }

    /** A brand taken from the query string, or null when there is none or it does not validate. */
    private function previewFrom(Request $request): ?array
    {
        if (! $request->hasAny(['primary', 'header', 'font'])) {
            return null;
        }

        $v = Validator::make($request->query(), $this->rules());
        if ($v->fails()) {
            return null;
        }

        $d = $v->validated();

        return [
            'name' => $d['name'] ?? null, 'primary' => strtolower($d['primary']), 'header' => strtolower($d['header']),
            'font' => $d['font'], 'allow_palettes' => (bool) ($d['allow_palettes'] ?? false), 'logo' => false, 'version' => 0,
        ];
    }

    /** Keep the newest versions; delete a logo file only once no remaining version points at it. */
    private function prune(): void
    {
        $old = BrandVersion::latest('id')->skip(BrandVersion::KEEP)->take(1000)->get();
        BrandVersion::whereIn('id', $old->pluck('id'))->delete();

        foreach ($old->pluck('logo_path')->filter()->unique() as $path) {
            if (! BrandVersion::where('logo_path', $path)->exists()) {
                Storage::disk('local')->delete($path);
            }
        }
    }
}
