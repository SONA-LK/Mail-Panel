<?php

namespace Tests\Feature;

use App\Models\BrandVersion;
use App\Models\User;
use App\Support\Color;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PanelFixtures;
use Tests\TestCase;

class ThemeTest extends TestCase
{
    use PanelFixtures, RefreshDatabase;

    private function brand(array $attrs = []): BrandVersion
    {
        $v = BrandVersion::create($attrs + ['primary' => '#2455d6', 'header' => '#141b2d', 'font' => 'system']);
        app(\App\Services\Theme::class)->forget();

        return $v;
    }

    private function save(array $over = [])
    {
        return $this->put('/admin/branding', $over + ['name' => 'Acme Mail', 'primary' => '#aa2200', 'header' => '#112233', 'font' => 'serif']);
    }

    /** A real PNG with a PHP payload appended after the image data. */
    private function pngBytes(int $w = 40, int $h = 20, string $trailer = ''): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 200, 30, 30));
        ob_start();
        imagepng($im);

        return ob_get_clean().$trailer;
    }

    public function test_color_helper_makes_text_readable(): void
    {
        $this->assertSame('#ffffff', Color::ink('#141b2d'));
        $this->assertSame('#111827', Color::ink('#ffee00'));
        $this->assertGreaterThanOrEqual(4.5, Color::contrast(Color::readable('#ffee00', '#ffffff'), '#ffffff'));
        $this->assertGreaterThanOrEqual(4.5, Color::contrast(Color::readable('#101060', '#171c28'), '#171c28'));
        $this->assertFalse(Color::valid('red;}'));
        $this->assertTrue(Color::valid('#A1b2C3'));
    }

    public function test_stylesheet_is_public_cookie_free_and_cacheable(): void
    {
        $r = $this->get('/theme.css?v=0');

        $r->assertOk()->assertHeader('Content-Type', 'text/css; charset=UTF-8');
        $this->assertStringContainsString('immutable', $r->headers->get('Cache-Control'));
        $this->assertStringContainsString('--brand:#2455d6', $r->getContent());
        $this->assertEmpty($r->headers->getCookies());
    }

    public function test_stylesheet_follows_the_saved_brand_and_keeps_links_readable(): void
    {
        $this->brand(['primary' => '#ffee00', 'header' => '#fafafa', 'font' => 'mono']);

        $css = $this->get('/theme.css')->getContent();

        $this->assertStringContainsString('--brand:#ffee00', $css);
        $this->assertStringContainsString('--head:#fafafa', $css);
        $this->assertStringContainsString('--head-ink:#111827', $css);
        $this->assertStringContainsString('monospace', $css);
        preg_match('/--link:(#[0-9a-f]{6})/', $css, $m);
        $this->assertGreaterThanOrEqual(4.5, Color::contrast($m[1], '#ffffff'));
    }

    public function test_only_super_admins_reach_branding(): void
    {
        $manager = $this->manager($this->domain());
        $version = $this->brand();

        $this->actingAs($manager)->get('/admin/branding')->assertForbidden();
        $this->actingAs($manager)->put('/admin/branding', ['primary' => '#000000', 'header' => '#000000', 'font' => 'system'])->assertForbidden();
        $this->actingAs($manager)->get('/admin/branding/preview.css?primary=%23000000&header=%23000000&font=system')->assertForbidden();
        $this->actingAs($this->readonly())->post("/admin/branding/{$version->id}/restore")->assertForbidden();
        $this->assertSame(1, BrandVersion::count());
    }

    public function test_super_admin_saves_branding_as_a_new_audited_version(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->save()->assertRedirect('/admin/branding');

        $v = BrandVersion::first();
        $this->assertSame(['Acme Mail', '#aa2200', '#112233', 'serif', $admin->id], [$v->name, $v->primary, $v->header, $v->font, $v->created_by]);
        $this->assertDatabaseHas('audit_log', ['action' => 'brand.update', 'user_id' => $admin->id]);
        $this->actingAs($admin)->get('/admin/branding')->assertOk()->assertSee('Acme Mail');
        $this->get('/')->assertSee('Acme Mail');
    }

    public function test_css_and_markup_injection_through_the_form_is_refused(): void
    {
        $admin = $this->admin();

        foreach ([
            ['primary' => '#fff;}body{display:none'],
            ['primary' => 'red'],
            ['header' => 'url(//evil.test/x)'],
            ['header' => "#112233\n}@import 'x';"],
            ['font' => 'x;}body{background:url(//evil.test)'],
            ['name' => '<script>alert(1)</script>'],
            ['name' => 'x}body{color:red'],
        ] as $bad) {
            $this->actingAs($admin)->from('/admin/branding')->save($bad)->assertSessionHasErrors();
        }
        $this->assertSame(0, BrandVersion::count());
    }

    public function test_preview_only_accepts_clean_values(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/branding/preview.css?primary=%23aa2200&header=%23112233&font=serif')
            ->assertOk()->assertSee('--brand:#aa2200', false);
        $this->actingAs($admin)->get('/admin/branding/preview.css?primary=red&header=%23112233&font=serif')->assertNotFound();
        $this->actingAs($admin)->get('/admin/branding/preview.css?primary=%23aa2200%3B%7D&header=%23112233&font=serif')->assertNotFound();
        $this->actingAs($admin)->get('/admin/branding?primary=%23aa2200&header=%23112233&font=serif')->assertOk()->assertSee('Preview only');
        $this->assertSame(0, BrandVersion::count());
    }

    public function test_logo_is_rebuilt_and_served_safely(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $payload = '<?php system($_GET["c"]); ?>';
        $file = UploadedFile::fake()->createWithContent('logo.png', $this->pngBytes(1200, 300, $payload));

        $this->actingAs($admin)->save(['logo' => $file])->assertRedirect('/admin/branding');

        $path = BrandVersion::first()->logo_path;
        $stored = Storage::disk('local')->get($path);
        $this->assertStringNotContainsString('<?php', $stored);
        [$w, $h] = getimagesizefromstring($stored);
        $this->assertLessThanOrEqual(480, $w);
        $this->assertLessThanOrEqual(120, $h);

        $r = $this->get('/brand/logo');
        $r->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertEmpty($r->headers->getCookies());
    }

    public function test_hostile_logo_uploads_are_refused(): void
    {
        Storage::fake('local');
        $admin = $this->admin();

        foreach ([
            UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            UploadedFile::fake()->createWithContent('logo.png', '<?php echo 1; ?>'),
            UploadedFile::fake()->createWithContent('logo.png', 'GIF89a'.str_repeat('x', 50)),
            UploadedFile::fake()->create('big.png', 2048, 'image/png'),
        ] as $file) {
            $this->actingAs($admin)->from('/admin/branding')->save(['logo' => $file])->assertSessionHasErrors('logo');
        }

        $this->assertSame(0, BrandVersion::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->get('/brand/logo')->assertNotFound();
    }

    public function test_a_version_can_be_restored(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->save(['name' => 'First', 'primary' => '#aa2200']);
        $this->actingAs($admin)->save(['name' => 'Second', 'primary' => '#00aa22']);
        $first = BrandVersion::orderBy('id')->first();

        $this->actingAs($admin)->post("/admin/branding/{$first->id}/restore")->assertRedirect('/admin/branding');

        $live = BrandVersion::latest('id')->first();
        $this->assertSame(['First', '#aa2200'], [$live->name, $live->primary]);
        $this->assertSame(3, BrandVersion::count());
        $this->assertDatabaseHas('audit_log', ['action' => 'brand.restore']);
    }

    public function test_old_versions_are_pruned_but_a_shared_logo_file_survives(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('brand/shared.png', 'x');
        Storage::disk('local')->put('brand/only-old.png', 'x');
        $this->brand(['logo_path' => 'brand/only-old.png']);
        $this->brand(['logo_path' => 'brand/shared.png']);
        for ($i = 0; $i < BrandVersion::KEEP - 2; $i++) {
            $this->brand(['logo_path' => 'brand/shared.png']);
        }

        $this->actingAs($this->admin())->save();

        $this->assertSame(BrandVersion::KEEP, BrandVersion::count());
        Storage::disk('local')->assertMissing('brand/only-old.png');
        Storage::disk('local')->assertExists('brand/shared.png');
    }

    public function test_people_keep_their_own_mode_and_density(): void
    {
        $user = $this->readonly();

        $this->actingAs($user)->get('/appearance')->assertOk();
        $this->actingAs($user)->put('/appearance', ['mode' => 'dark', 'density' => 'compact'])->assertRedirect('/appearance');

        $this->assertSame('dark', $user->fresh()->theme_prefs['mode']);
        $this->actingAs($user)->get('/')->assertSee('data-mode="dark"', false)->assertSee('data-density="compact"', false);

        $other = $this->readonly();
        $this->actingAs($other)->get('/')->assertSee('data-mode="system"', false)->assertSee('data-density="comfortable"', false);
    }

    public function test_invalid_preferences_are_refused(): void
    {
        $user = $this->readonly();

        $this->actingAs($user)->from('/appearance')->put('/appearance', ['mode' => 'neon', 'density' => 'compact'])->assertSessionHasErrors('mode');
        $this->actingAs($user)->from('/appearance')->put('/appearance', ['mode' => 'dark', 'density' => 'x" onload="y'])->assertSessionHasErrors('density');
        $this->actingAs($user)->from('/appearance')->put('/appearance', ['mode' => 'dark', 'density' => 'compact', 'palette' => 'nope'])->assertSessionHasErrors('palette');
        $this->assertNull($user->fresh()->theme_prefs);
    }

    public function test_palettes_apply_only_while_the_company_allows_them(): void
    {
        $user = $this->readonly();

        $this->brand(['allow_palettes' => false]);
        $this->actingAs($user)->put('/appearance', ['mode' => 'light', 'density' => 'comfortable', 'palette' => 'ocean']);
        $this->assertSame('', $user->fresh()->theme_prefs['palette']);

        $this->brand(['allow_palettes' => true]);
        $this->actingAs($user)->put('/appearance', ['mode' => 'light', 'density' => 'comfortable', 'palette' => 'ocean']);
        $this->actingAs($user)->get('/')->assertSee('data-palette="ocean"', false);
        $this->assertStringContainsString('html[data-palette=ocean]', $this->get('/theme.css')->getContent());

        // The company switches palettes off again: the saved choice stops applying right away.
        $this->brand(['allow_palettes' => false]);
        $this->actingAs($user)->get('/')->assertDontSee('data-palette', false);
    }

    public function test_sign_in_page_carries_the_company_name(): void
    {
        $this->brand(['name' => 'Acme Mail']);

        $this->get('/login')->assertOk()->assertSee('Acme Mail')->assertSee('/theme.css?v=', false);
    }

    public function test_users_table_gains_prefs_without_breaking_roles(): void
    {
        $this->assertInstanceOf(User::class, $this->admin());
        $this->assertTrue($this->admin()->isSuperAdmin());
    }
}
