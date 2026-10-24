<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SecurityOverview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PanelFixtures;
use Tests\TestCase;

class SecurityOverviewTest extends TestCase
{
    use PanelFixtures, RefreshDatabase;

    private function item(string $label): array
    {
        return collect(app(SecurityOverview::class)->items())->firstWhere('label', $label);
    }

    public function test_only_super_admins_see_the_security_card(): void
    {
        $this->actingAs($this->admin())->get('/')->assertOk()->assertSee('Audit log integrity')->assertSee('Super admins without a second step');
        $this->actingAs($this->manager($this->domain()))->get('/')->assertOk()->assertDontSee('Audit log integrity');
    }

    public function test_super_admins_without_a_second_step_are_flagged(): void
    {
        $this->admin();

        $this->assertSame(['1', 'bad'], [$this->item('Super admins without a second step')['value'], $this->item('Super admins without a second step')['level']]);

        User::query()->update(['totp_secret' => encrypt('X'), 'totp_confirmed_at' => now()]);
        $this->assertSame(['0', 'ok'], [$this->item('Super admins without a second step')['value'], $this->item('Super admins without a second step')['level']]);
        $this->assertSame('1 of 1', $this->item('People with a second step')['value']);
    }

    public function test_denied_sign_ins_and_lockouts_are_counted(): void
    {
        foreach (range(1, 6) as $i) {
            \App\Services\Audit::record(null, 'auth.failed', 'x@example.com', null, [], 'denied', 'x@example.com');
        }
        \App\Services\Audit::record(null, 'auth.second_factor_locked', 'x@example.com', null, [], 'denied');

        $this->assertSame(['6', 'warn'], [$this->item('Denied sign-ins, last 24 hours')['value'], $this->item('Denied sign-ins, last 24 hours')['level']]);
        $this->assertSame('1', $this->item('Second-step lock-outs, last 24 hours')['value']);
    }

    public function test_the_integrity_line_follows_the_daily_check(): void
    {
        $this->assertSame('warn', $this->item('Audit log integrity')['level']);

        $this->artisan('panel:audit-verify')->assertSuccessful();
        $this->assertSame('ok', $this->item('Audit log integrity')['level']);
        $this->assertSame('yes', $this->item('Audit log locked in the database')['value']);
    }

    public function test_token_age_warns_then_complains(): void
    {
        $this->assertSame('warn', $this->item('cPanel API token age')['level']);

        foreach ([[30, 'ok'], [120, 'warn'], [200, 'bad']] as [$days, $level]) {
            config(['panel.security.token_created' => now()->subDays($days)->toDateString()]);
            $this->assertSame([$level, "{$days} days"], [$this->item('cPanel API token age')['level'], $this->item('cPanel API token age')['value']]);
        }

        config(['panel.security.token_created' => 'not a date']);
        $this->assertSame('unknown', $this->item('cPanel API token age')['value']);
    }

    public function test_the_security_txt_is_published_only_when_a_contact_is_set(): void
    {
        $this->get('/.well-known/security.txt')->assertNotFound();

        config(['panel.security.contact' => 'javascript:alert(1)']);
        $this->get('/.well-known/security.txt')->assertNotFound();

        config(['panel.security.contact' => 'mailto:security@example.com']);
        $r = $this->get('/.well-known/security.txt')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $this->assertStringContainsString('Contact: mailto:security@example.com', $r->getContent());
        $this->assertStringContainsString('Canonical: ', $r->getContent());
        $this->assertMatchesRegularExpression('/Expires: \d{4}-\d\d-\d\dT00:00:00\.000Z/', $r->getContent());
        $this->assertEmpty($r->headers->getCookies());
        $this->assertStringNotContainsString('no-store', $r->headers->get('Cache-Control'));
    }
}
