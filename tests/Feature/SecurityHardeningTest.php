<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Support\PanelFixtures;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use PanelFixtures, RefreshDatabase;

    public function test_csp_is_strict_and_has_no_inline_allowance(): void
    {
        $csp = $this->get('/login')->headers->get('Content-Security-Policy');

        foreach (["default-src 'none'", "script-src 'self'", "style-src 'self'", "object-src 'none'", "base-uri 'none'", "form-action 'self'", "frame-ancestors 'none'"] as $part) {
            $this->assertStringContainsString($part, $csp);
        }
        $this->assertStringNotContainsString('unsafe-inline', $csp);
        $this->assertStringNotContainsString('unsafe-eval', $csp);
    }

    public function test_other_hardening_headers_are_present(): void
    {
        $r = $this->get('/login');

        $r->assertHeader('X-Frame-Options', 'DENY')->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')->assertHeader('Cross-Origin-Resource-Policy', 'same-origin');
        $this->assertStringContainsString('camera=()', $r->headers->get('Permissions-Policy'));
        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('no-store', $this->get('/theme.css')->headers->get('Cache-Control'));
    }

    public function test_no_view_uses_inline_style_or_script(): void
    {
        foreach (File::allFiles(resource_path('views')) as $file) {
            $body = $file->getContents();
            $this->assertDoesNotMatchRegularExpression('/\sstyle\s*=|<style|<script(?![^>]*\ssrc=)|\son[a-z]+\s*=/i', $body, $file->getRelativePathname().' has inline style or script, which the CSP blocks');
        }
    }

    public function test_changes_are_rate_limited_per_person_but_reading_is_not(): void
    {
        config(['panel.security.writes_per_minute' => 3]);
        $user = $this->readonly();
        $body = ['mode' => 'dark', 'density' => 'compact'];

        foreach ([1, 2, 3] as $i) {
            $this->actingAs($user)->put('/appearance', $body)->assertRedirect();
        }
        $this->actingAs($user)->put('/appearance', $body)->assertStatus(429);

        foreach (range(1, 12) as $i) {
            $this->actingAs($user)->get('/appearance')->assertOk();
        }
        // Someone else is not affected by this person's limit.
        $this->actingAs($this->readonly())->put('/appearance', $body)->assertRedirect();
    }

    public function test_sessions_end_after_the_absolute_lifetime(): void
    {
        config(['panel.security.session_absolute_minutes' => 60]);
        $user = $this->readonly();

        $this->actingAs($user)->withSession(['login_at' => now()->subMinutes(61)->timestamp])->get('/')->assertRedirect('/login');
        $this->assertGuest();
        $this->assertDatabaseHas('audit_log', ['action' => 'auth.session_expired', 'user_id' => $user->id]);

        $this->actingAs($user)->withSession(['login_at' => now()->subMinutes(10)->timestamp])->get('/')->assertOk();
    }

    public function test_older_sessions_without_a_start_time_get_one_instead_of_being_cut_off(): void
    {
        $this->actingAs($this->readonly())->get('/')->assertOk()->assertSessionHas('login_at');
    }

    public function test_revoking_a_persons_sessions_leaves_everyone_else(): void
    {
        config(['session.driver' => 'database']);
        $a = $this->readonly();
        $b = $this->readonly();
        foreach ([[$a->id, 's1'], [$a->id, 's2'], [$b->id, 's3']] as [$uid, $id]) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $uid, 'payload' => '', 'last_activity' => time()]);
        }

        $a->revokeSessions();

        $this->assertSame(0, DB::table('sessions')->where('user_id', $a->id)->count());
        $this->assertSame(1, DB::table('sessions')->where('user_id', $b->id)->count());
    }

    public function test_the_sign_in_limits_count_separately(): void
    {
        $limits = \Illuminate\Support\Facades\RateLimiter::limiter('login')(\Illuminate\Http\Request::create('/auth/google', 'GET', [], [], [], ['REMOTE_ADDR' => '203.0.113.5']));

        $this->assertCount(2, $limits);
        $this->assertNotSame($limits[0]->key, $limits[1]->key, 'one shared counter would make the hourly cap impossible to reach');
    }

    public function test_the_last_super_admin_cannot_be_demoted(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->from('/admin/users')->put("/admin/users/{$admin->id}", [
            'name' => $admin->name, 'email' => $admin->email, 'role' => User::ROLE_READONLY, 'active' => 1,
        ])->assertSessionHasErrors('role');
        $this->assertTrue($admin->fresh()->isSuperAdmin());
    }
}
