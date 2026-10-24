<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialUser;
use Mockery;
use Tests\Support\PanelFixtures;
use Tests\TestCase;

use Illuminate\Foundation\Testing\RefreshDatabase;

class AuthTest extends TestCase
{
    use PanelFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPanel();
    }

    private function googleReturns(string $email, bool $verified = true, string $id = 'g-1'): void
    {
        $social = (new SocialUser)->map(['id' => $id, 'name' => 'Test', 'email' => $email]);
        $social->user = ['email_verified' => $verified];

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andReturn($social);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/mailboxes')->assertRedirect('/login');
    }

    public function test_unapproved_google_account_is_refused_and_logged(): void
    {
        $this->googleReturns('stranger@gmail.com');

        $this->get('/auth/google/callback')->assertRedirect('/login');

        $this->assertGuest();
        $this->assertDatabaseHas('audit_log', ['action' => 'auth.failed', 'user_email' => 'stranger@gmail.com', 'result' => 'denied']);
        $this->assertSame(0, User::count());
    }

    public function test_unverified_google_email_is_refused(): void
    {
        User::factory()->create(['email' => 'anna@example.com', 'role' => 'super_admin']);
        $this->googleReturns('anna@example.com', verified: false);

        $this->get('/auth/google/callback')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_inactive_user_is_refused(): void
    {
        User::factory()->create(['email' => 'anna@example.com', 'active' => false]);
        $this->googleReturns('anna@example.com');

        $this->get('/auth/google/callback')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_approved_user_signs_in_and_google_id_is_bound(): void
    {
        $user = User::factory()->superAdmin()->create(['email' => 'anna@example.com']);
        $this->googleReturns('Anna@Example.com');

        $this->get('/auth/google/callback')->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
        $this->assertSame('g-1', $user->fresh()->google_id);
        $this->assertDatabaseHas('audit_log', ['action' => 'auth.login', 'user_id' => $user->id]);
    }

    public function test_a_different_google_account_cannot_take_over_a_bound_email(): void
    {
        User::factory()->create(['email' => 'anna@example.com', 'google_id' => 'original']);
        $this->googleReturns('anna@example.com', id: 'attacker');

        $this->get('/auth/google/callback')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_workspace_domain_restriction(): void
    {
        config(['services.google.allowed_domain' => 'corp.com']);
        User::factory()->create(['email' => 'anna@gmail.com']);
        $this->googleReturns('anna@gmail.com');

        $this->get('/auth/google/callback')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_deactivated_user_loses_access_on_next_request(): void
    {
        $user = $this->admin();
        $this->actingAs($user)->get('/')->assertOk();

        $user->update(['active' => false]);

        $this->get('/')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_audit_log_is_append_only(): void
    {
        $entry = AuditLog::create(['action' => 'x', 'created_at' => now()]);

        $this->expectException(\LogicException::class);
        $entry->update(['action' => 'changed']);
    }

    public function test_audit_log_rows_cannot_be_deleted(): void
    {
        $entry = AuditLog::create(['action' => 'x', 'created_at' => now()]);

        $this->expectException(\LogicException::class);
        $entry->delete();
    }

    public function test_security_headers_are_present(): void
    {
        $response = $this->get('/login');

        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString("default-src 'none'", $response->headers->get('Content-Security-Policy'));
    }
}
