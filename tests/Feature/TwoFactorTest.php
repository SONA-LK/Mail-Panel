<?php

namespace Tests\Feature;

use App\Models\Passkey;
use App\Models\RecoveryCode;
use App\Models\User;
use App\Services\RecoveryCodes;
use App\Services\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialUser;
use Mockery;
use Tests\Support\PanelFixtures;
use Tests\Support\SoftAuthenticator;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use PanelFixtures, RefreshDatabase;

    private Totp $totp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPanel();
        $this->totp = new Totp;
    }

    private function googleReturns(string $email, string $id = 'g-1'): void
    {
        $social = (new SocialUser)->map(['id' => $id, 'name' => 'Test', 'email' => $email]);
        $social->user = ['email_verified' => true];

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andReturn($social);
        $provider->shouldReceive('with')->andReturnSelf();
        $provider->shouldReceive('redirect')->andReturn(redirect('https://accounts.google.test/auth'));
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    /** A person with an authenticator app already set up. */
    private function withTotp(?User $user = null): array
    {
        $user ??= User::factory()->create();
        $secret = $this->totp->generateSecret();
        $user->forceFill(['totp_secret' => $secret, 'totp_confirmed_at' => now()])->save();

        return [$user, $secret];
    }

    private function codeFor(string $secret, int $drift = 0): string
    {
        return $this->totp->code($secret, $this->totp->currentStep() + $drift);
    }

    /** Google accepted this person; they now sit at the second step. */
    private function atSecondStep(User $user)
    {
        $this->googleReturns($user->email);

        return $this->get('/auth/google/callback');
    }

    // --- TOTP and recovery codes -------------------------------------------------------

    public function test_totp_matches_the_rfc_6238_vector_and_rejects_replays(): void
    {
        // RFC 6238 appendix B: secret "12345678901234567890", time 59 -> 94287082, so 287082 with 6 digits.
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        $this->assertSame('287082', $this->totp->code($secret, intdiv(59, 30)));

        $step = $this->totp->currentStep(1_000_000);
        $code = $this->totp->code($secret, $step);
        $this->assertSame($step, $this->totp->verify($secret, $code, null, 1_000_000));
        $this->assertSame($step, $this->totp->verify($secret, chunk_split($code, 3, ' '), null, 1_000_000), 'spaces are ignored');
        $this->assertSame($step + 1, $this->totp->verify($secret, $this->totp->code($secret, $step + 1), null, 1_000_000), 'one window of drift is fine');
        $this->assertNull($this->totp->verify($secret, $this->totp->code($secret, $step + 2), null, 1_000_000), 'two windows is not');
        $this->assertNull($this->totp->verify($secret, $code, $step, 1_000_000), 'a used step cannot be used again');
        $this->assertNull($this->totp->verify($secret, 'abcdef', null, 1_000_000));
        $this->assertNull($this->totp->verify($secret, '12345', null, 1_000_000));
        $this->assertSame(32, strlen($this->totp->generateSecret()));
    }

    public function test_recovery_codes_work_once_and_are_stored_hashed(): void
    {
        $user = User::factory()->create();
        $codes = app(RecoveryCodes::class)->generate($user);

        $this->assertCount(8, $codes);
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{5}-[A-Z2-9]{5}$/', $codes[0]);
        $this->assertSame(0, RecoveryCode::where('code_hash', str_replace('-', '', $codes[0]))->count());

        $this->assertSame(7, app(RecoveryCodes::class)->consume($user, strtolower($codes[0])), 'case and dash do not matter');
        $this->assertNull(app(RecoveryCodes::class)->consume($user, $codes[0]), 'second use refused');
        $this->assertNull(app(RecoveryCodes::class)->consume($user, 'AAAAA-AAAAA'));
        $this->assertNull(app(RecoveryCodes::class)->consume(User::factory()->create(), $codes[1]), 'someone else cannot use it');

        app(RecoveryCodes::class)->generate($user);
        $this->assertNull(app(RecoveryCodes::class)->consume($user, $codes[1]), 'new codes cancel the old ones');
    }

    // --- Enrolling ---------------------------------------------------------------------

    public function test_authenticator_app_setup_needs_a_correct_code(): void
    {
        $user = User::factory()->create();

        $page = $this->actingAs($user)->get('/security/totp')->assertOk();
        $page->assertSee('data:image/svg+xml;base64,', false)->assertSee('Setup key');
        $secret = session('totp.setup');

        $this->actingAs($user)->from('/security/totp')->post('/security/totp', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertFalse($user->fresh()->hasTotp());

        $this->actingAs($user)->post('/security/totp', ['code' => $this->codeFor($secret)])->assertRedirect('/security');

        $user->refresh();
        $this->assertTrue($user->hasTotp());
        $this->assertSame($secret, $user->totp_secret);
        $this->assertNotSame($secret, DB::table('users')->where('id', $user->id)->value('totp_secret'), 'encrypted at rest');
        $this->assertSame(8, $user->recoveryCodes()->count());
        $this->assertCount(8, session('recovery_codes'));
        $this->assertDatabaseHas('audit_log', ['action' => 'security.totp_enabled', 'user_id' => $user->id]);
    }

    public function test_the_security_page_shows_codes_once_and_never_the_secret(): void
    {
        [$user, $secret] = $this->withTotp();

        $this->actingAs($user)->withSession(['recovery_codes' => ['AAAAA-BBBBB']])->get('/security')->assertOk()->assertSee('AAAAA-BBBBB');
        $this->flushSession();
        $this->actingAs($user)->get('/security')->assertOk()->assertDontSee('AAAAA-BBBBB')->assertDontSee($secret);
    }

    // --- Signing in --------------------------------------------------------------------

    public function test_google_alone_does_not_sign_in_someone_with_a_second_factor(): void
    {
        [$user, $secret] = $this->withTotp();

        $this->atSecondStep($user)->assertRedirect('/two-factor');

        $this->assertGuest();
        $this->get('/mailboxes')->assertRedirect('/login');
        $this->get('/two-factor')->assertOk()->assertSee('One more step');
    }

    public function test_correct_code_finishes_the_sign_in_and_cannot_be_replayed(): void
    {
        [$user, $secret] = $this->withTotp();
        $this->atSecondStep($user);

        $used = $this->codeFor($secret);   // keep the exact string: recomputing could land in the next window
        $this->post('/two-factor/code', ['code' => $used])->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('audit_log', ['action' => 'auth.login', 'user_id' => $user->id]);
        $this->assertSame('totp', AuditDetails::last('auth.login')['method']);
        $this->assertNotNull(session('login_at'));
        $this->assertNotNull(session('confirmed_at'));
        $this->assertNotNull($user->fresh()->last_login_at);

        // Same code again on a new sign-in: refused. The next window's code is fine.
        auth()->logout();
        $this->flushSession();
        $this->atSecondStep($user->fresh());
        $this->from('/two-factor')->post('/two-factor/code', ['code' => $used])->assertSessionHasErrors('code');
        $this->assertGuest();
        $this->post('/two-factor/code', ['code' => $this->codeFor($secret, 1)])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_code_keeps_the_person_out_and_is_logged(): void
    {
        [$user] = $this->withTotp();
        $this->atSecondStep($user);

        $this->from('/two-factor')->post('/two-factor/code', ['code' => '123456'])->assertRedirect('/two-factor')->assertSessionHasErrors('code');

        $this->assertGuest();
        $this->assertDatabaseHas('audit_log', ['action' => 'auth.second_factor_failed', 'user_id' => $user->id, 'result' => 'denied']);
    }

    public function test_second_step_pages_need_a_pending_google_sign_in(): void
    {
        [$user, $secret] = $this->withTotp();

        $this->get('/two-factor')->assertRedirect('/login');
        $this->post('/two-factor/code', ['code' => $this->codeFor($secret)])->assertRedirect('/login');
        $this->postJson('/two-factor/passkey/options')->assertStatus(422);
        $this->assertGuest();

        // A pending sign-in goes stale.
        $this->atSecondStep($user);
        $this->travel(6)->minutes();
        $this->get('/two-factor')->assertRedirect('/login');
        $this->post('/two-factor/code', ['code' => $this->codeFor($secret)])->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_a_disabled_account_cannot_finish_the_second_step(): void
    {
        [$user, $secret] = $this->withTotp();
        $this->atSecondStep($user);
        $user->update(['active' => false]);

        $this->post('/two-factor/code', ['code' => $this->codeFor($secret)])->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_too_many_wrong_answers_lock_the_second_step_even_for_the_right_code(): void
    {
        [$user, $secret] = $this->withTotp();
        $this->atSecondStep($user);

        foreach (range(1, 5) as $i) {
            $this->from('/two-factor')->post('/two-factor/code', ['code' => '000000'])->assertSessionHasErrors('code');
        }
        $this->from('/two-factor')->post('/two-factor/code', ['code' => $this->codeFor($secret)])->assertSessionHasErrors('code');

        $this->assertGuest();
        $this->assertStringContainsString('Too many', session('errors')->first('code'));
        $this->assertDatabaseHas('audit_log', ['action' => 'auth.second_factor_locked', 'user_id' => $user->id]);
    }

    public function test_a_recovery_code_signs_in_once_and_warns(): void
    {
        [$user] = $this->withTotp();
        $codes = app(RecoveryCodes::class)->generate($user);
        $this->atSecondStep($user);

        $this->post('/two-factor/code', ['code' => $codes[0]])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $this->assertStringContainsString('7 left', session('warning'));
        $this->assertDatabaseHas('audit_log', ['action' => 'auth.recovery_code_used']);

        auth()->logout();
        $this->flushSession();
        $this->atSecondStep($user);
        $this->from('/two-factor')->post('/two-factor/code', ['code' => $codes[0]])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_wrong_answers_from_one_address_do_not_lock_the_real_owner_out(): void
    {
        [$user, $secret] = $this->withTotp();
        $this->atSecondStep($user);

        foreach (range(1, 6) as $i) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.66'])->from('/two-factor')->post('/two-factor/code', ['code' => '000000']);
        }

        // The guesser is locked, the owner (another address) is not.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.66'])->from('/two-factor')->post('/two-factor/code', ['code' => $this->codeFor($secret)])->assertSessionHasErrors('code');
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->post('/two-factor/code', ['code' => $this->codeFor($secret)])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_guessing_from_many_addresses_still_hits_the_total_limit(): void
    {
        config(['panel.security.two_factor_total_failures' => 6]);
        [$user, $secret] = $this->withTotp();
        $this->atSecondStep($user);

        foreach (range(1, 6) as $i) {
            $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.{$i}"])->from('/two-factor')->post('/two-factor/code', ['code' => '000000']);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->from('/two-factor')->post('/two-factor/code', ['code' => $this->codeFor($secret)])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_a_second_use_of_a_code_step_loses_the_race(): void
    {
        [$user, $secret] = $this->withTotp();
        $step = $this->totp->currentStep();
        $claim = fn () => \App\Models\User::whereKey($user->id)->where(fn ($q) => $q->whereNull('totp_last_step')->orWhere('totp_last_step', '<', $step))->update(['totp_last_step' => $step]);

        $this->assertSame([1, 0], [$claim(), $claim()]);
    }

    public function test_recovery_codes_do_not_outlive_the_last_factor(): void
    {
        [$user] = $this->withTotp();
        $codes = app(RecoveryCodes::class)->generate($user);

        $this->actingAs($user)->delete('/security/totp')->assertRedirect();

        $this->assertSame(0, $user->recoveryCodes()->count());
        $this->assertNull(app(RecoveryCodes::class)->consume($user, $codes[0]));

        // And with no factor at all, the confirm page does not accept a code.
        config(['panel.security.step_up_minutes' => 10]);
        $other = User::factory()->create();
        $other->recoveryCodes()->create(['code_hash' => hash_hmac('sha256', 'AAAAABBBBB', config('app.key'))]);
        $this->actingAs($other)->withSession(['confirmed_at' => 1])->from('/confirm')->post('/confirm/code', ['code' => 'AAAAA-BBBBB'])->assertSessionHasErrors('code');
        $this->assertSame(1, session('confirmed_at'));
    }

    public function test_a_secret_that_can_no_longer_be_read_fails_closed_without_a_server_error(): void
    {
        [$user] = $this->withTotp();
        \Illuminate\Support\Facades\DB::table('users')->where('id', $user->id)->update(['totp_secret' => 'not-decryptable']);

        // Still treated as protected (no silent downgrade to Google only) and no 500 anywhere.
        $this->assertTrue($user->fresh()->hasTwoFactor());
        $this->atSecondStep($user->fresh())->assertRedirect('/two-factor');
        $this->post('/two-factor/code', ['code' => '123456'])->assertRedirect()->assertSessionHasErrors('code');
        $this->assertGuest();
        $this->artisan('panel:doctor');
    }

    // --- Roles that must have a second factor ------------------------------------------

    public function test_a_required_role_can_only_reach_the_security_page_until_it_has_a_factor(): void
    {
        config(['panel.security.two_factor_required_roles' => ['super_admin']]);
        $admin = $this->admin();

        $this->actingAs($admin)->get('/')->assertRedirect('/security');
        $this->actingAs($admin)->get('/mailboxes')->assertRedirect('/security');
        $this->actingAs($admin)->get('/admin/users')->assertRedirect('/security');
        $this->actingAs($admin)->get('/security')->assertOk();
        $this->actingAs($admin)->get('/security/totp')->assertOk();
        $this->actingAs($admin)->get('/appearance')->assertOk();

        $this->withTotp($admin);
        $this->actingAs($admin->fresh())->get('/')->assertOk();

        // Other roles are not forced.
        $this->actingAs(User::factory()->create())->get('/')->assertOk();
    }

    public function test_a_required_role_cannot_remove_its_last_factor(): void
    {
        config(['panel.security.two_factor_required_roles' => ['super_admin']]);
        [$admin] = $this->withTotp($this->admin());

        $this->actingAs($admin)->from('/security')->delete('/security/totp')->assertSessionHasErrors('totp');
        $this->assertTrue($admin->fresh()->hasTotp());

        [$other] = $this->withTotp();
        $this->actingAs($other)->delete('/security/totp')->assertSessionHasNoErrors();
        $this->assertFalse($other->fresh()->hasTotp());
    }

    // --- Confirming sensitive changes --------------------------------------------------

    private function brandBody(): array
    {
        return ['primary' => '#aa2200', 'header' => '#112233', 'font' => 'system'];
    }

    public function test_sensitive_changes_need_a_recent_confirmation_but_reading_does_not(): void
    {
        config(['panel.security.step_up_minutes' => 10]);
        $admin = $this->admin();
        $stale = ['confirmed_at' => now()->subMinutes(11)->timestamp];
        $fresh = ['confirmed_at' => now()->subMinutes(2)->timestamp];

        $this->actingAs($admin)->withSession($stale)->get('/admin/branding')->assertOk();
        $this->actingAs($admin)->withSession($stale)->from('/admin/branding')->put('/admin/branding', $this->brandBody())->assertRedirect('/confirm');
        $this->actingAs($admin)->withSession($stale)->putJson('/admin/branding', $this->brandBody())->assertStatus(403)->assertJson(['error' => 'confirm']);
        $this->assertDatabaseCount('brand_versions', 0);

        $this->actingAs($admin)->withSession($fresh)->put('/admin/branding', $this->brandBody())->assertRedirect('/admin/branding');
        $this->assertDatabaseCount('brand_versions', 1);

        // Ordinary changes are not interrupted.
        $this->actingAs($admin)->withSession($stale)->put('/appearance', ['mode' => 'dark', 'density' => 'compact'])->assertRedirect('/appearance');
    }

    public function test_password_reset_and_mailbox_delete_are_guarded(): void
    {
        config(['panel.security.step_up_minutes' => 10]);
        $domain = $this->domain();
        $manager = $this->manager($domain);
        $mailbox = \App\Models\Mailbox::create(['address' => 'a@'.$domain->name, 'local_part' => 'a', 'domain_id' => $domain->id, 'quota_mb' => 100, 'used_mb' => 1]);

        $this->actingAs($manager)->withSession(['confirmed_at' => 1])->post("/mailboxes/{$mailbox->id}/password")->assertRedirect('/confirm');
        $this->actingAs($manager)->withSession(['confirmed_at' => 1])->delete("/mailboxes/{$mailbox->id}")->assertRedirect('/confirm');
        $this->actingAs($manager)->withSession(['confirmed_at' => 1])->put("/catch-all/{$domain->id}", ['policy' => 'default'])->assertRedirect('/confirm');
    }

    public function test_the_intended_page_never_leaves_the_site(): void
    {
        config(['panel.security.step_up_minutes' => 10]);

        $this->actingAs($this->admin())->withSession(['confirmed_at' => 1])
            ->put('/admin/branding', $this->brandBody(), ['Referer' => 'https://evil.test/phish']);

        $this->assertSame('http://localhost', session('url.intended'));
    }

    public function test_confirming_with_the_authenticator_app_opens_the_window(): void
    {
        config(['panel.security.step_up_minutes' => 10]);
        [$admin, $secret] = $this->withTotp($this->admin());
        $this->actingAs($admin)->withSession(['confirmed_at' => 1, 'url.intended' => 'http://localhost/admin/branding']);

        $this->get('/confirm')->assertOk()->assertSee('Confirm it is you')->assertSee('authenticator app');
        $this->from('/confirm')->post('/confirm/code', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->post('/confirm/code', ['code' => $this->codeFor($secret)])->assertRedirect('/admin/branding');

        $this->assertGreaterThan(now()->timestamp - 5, session('confirmed_at'));
        $this->assertDatabaseHas('audit_log', ['action' => 'auth.confirm', 'user_id' => $admin->id, 'result' => 'success']);
        $this->put('/admin/branding', $this->brandBody())->assertRedirect('/admin/branding');
    }

    public function test_people_without_a_second_factor_confirm_with_google_again(): void
    {
        config(['panel.security.step_up_minutes' => 10]);
        $admin = $this->admin();
        $this->googleReturns($admin->email);

        $this->actingAs($admin)->withSession(['confirmed_at' => 1])->get('/confirm')->assertOk()->assertSee('Sign in with Google again');
        $this->actingAs($admin)->get('/confirm/google')->assertRedirect('https://accounts.google.test/auth');
        $this->assertNotNull(session('reauth'));

        $this->get('/auth/google/callback')->assertRedirect('/');
        $this->assertGreaterThan(now()->timestamp - 5, session('confirmed_at'));
        $this->assertAuthenticatedAs($admin);
    }

    public function test_google_confirmation_by_someone_else_or_unasked_is_refused(): void
    {
        config(['panel.security.step_up_minutes' => 10]);
        $admin = $this->admin();

        // A different Google account answers.
        $this->googleReturns('someone-else@example.com');
        $this->actingAs($admin)->withSession(['reauth' => now()->timestamp, 'confirmed_at' => 1])->get('/auth/google/callback')->assertRedirect('/confirm');
        $this->assertSame(1, session('confirmed_at'));

        // The right account, but nobody asked for a confirmation.
        $this->googleReturns($admin->email);
        $this->actingAs($admin)->withSession(['confirmed_at' => 1])->get('/auth/google/callback')->assertRedirect('/confirm');
        $this->assertSame(1, session('confirmed_at'));
        $this->assertDatabaseHas('audit_log', ['action' => 'auth.confirm', 'result' => 'denied']);
    }

    // --- Managing other people ---------------------------------------------------------

    public function test_a_super_admin_can_reset_someone_elses_second_factor(): void
    {
        [$target] = $this->withTotp();
        app(RecoveryCodes::class)->generate($target);
        $target->passkeys()->create(['name' => 'k', 'credential_id' => 'abc', 'record' => '{}']);
        $admin = $this->admin();

        $this->actingAs($admin)->post("/admin/users/{$target->id}/reset-two-factor")->assertRedirect();

        $target->refresh();
        $this->assertFalse($target->hasTwoFactor());
        $this->assertSame(0, $target->recoveryCodes()->count());
        $this->assertDatabaseHas('audit_log', ['action' => 'user.two_factor_reset', 'user_id' => $admin->id, 'target' => $target->email]);

        $this->actingAs($admin)->from('/admin/users')->post("/admin/users/{$admin->id}/reset-two-factor")->assertSessionHasErrors('user');
        $this->actingAs($this->manager($this->domain()))->post("/admin/users/{$target->id}/reset-two-factor")->assertForbidden();
    }

    public function test_the_command_line_can_reset_a_lost_second_factor(): void
    {
        [$user] = $this->withTotp($this->admin());

        $this->artisan('panel:2fa-reset', ['email' => strtoupper($user->email)])->assertSuccessful();
        $this->assertFalse($user->fresh()->hasTwoFactor());

        $this->artisan('panel:2fa-reset', ['email' => 'nobody@example.com'])->assertFailed();
    }

    // --- Devices -----------------------------------------------------------------------

    public function test_people_can_see_and_end_their_other_sessions_but_not_anyone_elses(): void
    {
        config(['session.driver' => 'database']);
        $me = User::factory()->create();
        $other = User::factory()->create();
        $row = fn ($id, $uid, $ua) => DB::table('sessions')->insert(['id' => $id, 'user_id' => $uid, 'ip_address' => '203.0.113.9', 'user_agent' => $ua, 'payload' => '', 'last_activity' => time() - 60]);
        $row('mine-phone', $me->id, 'Mozilla/5.0 (iPhone; CPU iPhone OS 17) Safari/605.1');
        $row('mine-pc', $me->id, 'Mozilla/5.0 (Windows NT 10.0) Chrome/120 Safari/537');
        $row('theirs', $other->id, 'Mozilla/5.0 (Windows NT 10.0) Firefox/120');
        $handle = fn ($id) => hash_hmac('sha256', $id, config('app.key'));

        $page = $this->actingAs($me)->get('/security')->assertOk();
        $page->assertSee('Safari on iOS')->assertSee('Chrome on Windows')->assertDontSee('Firefox')->assertDontSee('mine-phone');

        $this->actingAs($me)->delete('/security/sessions/'.$handle('theirs'))->assertNotFound();
        $this->assertDatabaseHas('sessions', ['id' => 'theirs']);

        $this->actingAs($me)->delete('/security/sessions/'.$handle('mine-phone'))->assertRedirect();
        $this->assertDatabaseMissing('sessions', ['id' => 'mine-phone']);

        $this->actingAs($me)->delete('/security/sessions')->assertRedirect();
        $this->assertDatabaseMissing('sessions', ['id' => 'mine-pc']);
        $this->assertDatabaseHas('sessions', ['id' => 'theirs']);
    }

    public function test_changing_sign_in_methods_needs_a_fresh_confirmation(): void
    {
        [$user] = $this->withTotp();
        $user->passkeys()->create(['name' => 'k', 'credential_id' => 'abc', 'record' => '{}']);
        $passkey = $user->passkeys()->first();
        config(['panel.security.step_up_minutes' => 10]);
        $stale = ['confirmed_at' => now()->subHour()->timestamp];

        $this->actingAs($user)->withSession($stale)->postJson('/security/passkeys', ['id' => 'x'])->assertStatus(403)->assertJson(['error' => 'confirm']);
        $this->actingAs($user)->withSession($stale)->delete('/security/totp')->assertRedirect('/confirm');
        $this->actingAs($user)->withSession($stale)->delete("/security/passkeys/{$passkey->id}")->assertRedirect('/confirm');
        $this->actingAs($user)->withSession($stale)->post('/security/recovery-codes')->assertRedirect('/confirm');

        $this->assertTrue($user->fresh()->hasTotp());
        $this->assertDatabaseHas('passkeys', ['id' => $passkey->id]);
        $this->assertSame(0, $user->recoveryCodes()->count());
    }

    // --- Passkeys, against the real WebAuthn validator ---------------------------------

    private function registerPasskey(User $user, SoftAuthenticator $key, string $name = 'Laptop')
    {
        $options = $this->actingAs($user)->postJson('/security/passkeys/options')->assertOk()->json();

        return $this->actingAs($user)->postJson('/security/passkeys?name='.urlencode($name), $key->register($options));
    }

    public function test_a_passkey_can_be_registered(): void
    {
        $user = User::factory()->create();
        $key = new SoftAuthenticator;

        $options = $this->actingAs($user)->postJson('/security/passkeys/options')->assertOk()->json();
        $this->assertSame('localhost', $options['rp']['id']);
        $this->assertSame(32, strlen(base64_decode(strtr($options['user']['id'], '-_', '+/'))));
        $this->assertNotSame((string) $user->id, $options['user']['id']);
        $this->assertSame('none', $options['attestation']);

        $this->actingAs($user)->postJson('/security/passkeys?name=Laptop', $key->register($options))->assertOk()->assertJson(['ok' => true]);

        $passkey = $user->passkeys()->first();
        $this->assertSame('Laptop', $passkey->name);
        $this->assertTrue($user->hasTwoFactor());
        $this->assertSame(8, $user->recoveryCodes()->count(), 'first factor also makes recovery codes');
        $this->assertDatabaseHas('audit_log', ['action' => 'security.passkey_added', 'user_id' => $user->id]);
        $this->actingAs($user)->get('/security')->assertSee('Laptop');
    }

    public function test_passkey_registration_rejects_a_wrong_challenge_a_wrong_origin_and_replays(): void
    {
        $user = User::factory()->create();

        $options = $this->actingAs($user)->postJson('/security/passkeys/options')->json();
        $stale = (new SoftAuthenticator)->register($options);
        $this->actingAs($user)->postJson('/security/passkeys/options')->assertOk();   // a newer challenge replaces it
        $this->actingAs($user)->postJson('/security/passkeys', $stale)->assertStatus(422);

        $options = $this->actingAs($user)->postJson('/security/passkeys/options')->json();
        $evil = (new SoftAuthenticator)->withOrigin('https://evil.test')->register($options);
        $this->actingAs($user)->postJson('/security/passkeys', $evil)->assertStatus(422);

        $this->actingAs($user)->postJson('/security/passkeys', ['id' => 'x'])->assertStatus(422);   // no challenge left
        $this->assertSame(0, Passkey::count());

        $options = $this->actingAs($user)->postJson('/security/passkeys/options')->json();
        $good = (new SoftAuthenticator)->register($options);
        $this->actingAs($user)->postJson('/security/passkeys', $good)->assertOk();
        $this->assertSame(1, Passkey::count());
    }

    public function test_a_passkey_name_cannot_carry_markup(): void
    {
        $user = User::factory()->create();
        $options = $this->actingAs($user)->postJson('/security/passkeys/options')->json();

        $this->actingAs($user)->postJson('/security/passkeys?name='.urlencode('<script>x</script>'), (new SoftAuthenticator)->register($options))->assertStatus(422);
        $this->assertSame(0, Passkey::count());
    }

    public function test_signing_in_with_a_passkey(): void
    {
        $user = User::factory()->create();
        $key = new SoftAuthenticator;
        $this->registerPasskey($user, $key)->assertOk();
        auth()->logout();
        $this->flushSession();

        $this->atSecondStep($user)->assertRedirect('/two-factor');
        $this->get('/two-factor')->assertOk()->assertSee('Use my passkey');

        $options = $this->postJson('/two-factor/passkey/options')->assertOk()->json();
        $this->assertSame(rtrim(strtr(base64_encode($key->credentialId), '+/', '-_'), '='), $options['allowCredentials'][0]['id']);

        $this->postJson('/two-factor/passkey/verify', $key->assert($options))->assertOk()->assertJson(['ok' => true, 'redirect' => url('/')]);

        $this->assertAuthenticatedAs($user);
        $this->assertSame('passkey', AuditDetails::last('auth.login')['method']);
        $this->assertNotNull($user->passkeys()->first()->last_used_at);
    }

    public function test_a_passkey_cannot_be_replayed_cloned_or_borrowed(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $key = new SoftAuthenticator;
        $this->registerPasskey($alice, $key)->assertOk();
        $bobKey = new SoftAuthenticator;
        $this->registerPasskey($bob, $bobKey)->assertOk();
        auth()->logout();
        $this->flushSession();

        // 1. An answer to an older challenge does not work for a new one.
        $this->atSecondStep($alice);
        $old = $this->postJson('/two-factor/passkey/options')->json();
        $oldAnswer = $key->assert($old);
        $new = $this->postJson('/two-factor/passkey/options')->json();
        $this->postJson('/two-factor/passkey/verify', $oldAnswer)->assertStatus(422);
        $this->assertGuest();

        // 2. The challenge is single use: even a correct answer fails once a wrong one was tried.
        $this->postJson('/two-factor/passkey/verify', $key->assert($new))->assertStatus(422);

        // 3. Bob's key cannot answer for Alice.
        $options = $this->postJson('/two-factor/passkey/options')->json();
        $this->postJson('/two-factor/passkey/verify', $bobKey->assert($options))->assertStatus(422);
        $this->assertGuest();

        // 4. A cloned key whose counter went backwards is refused.
        $options = $this->postJson('/two-factor/passkey/options')->json();
        $this->postJson('/two-factor/passkey/verify', $key->assert($options))->assertOk();
        auth()->logout();
        $this->flushSession();
        $this->atSecondStep($alice->fresh());
        $options = $this->postJson('/two-factor/passkey/options')->json();
        $key->counter = 0;
        $this->postJson('/two-factor/passkey/verify', $key->assert($options))->assertStatus(422);
        $this->assertGuest();
    }

    public function test_a_passkey_from_the_wrong_site_is_refused_at_sign_in(): void
    {
        $user = User::factory()->create();
        $key = new SoftAuthenticator;
        $this->registerPasskey($user, $key)->assertOk();
        auth()->logout();
        $this->flushSession();

        $this->atSecondStep($user);
        $options = $this->postJson('/two-factor/passkey/options')->json();

        $this->postJson('/two-factor/passkey/verify', $key->withOrigin('https://evil.test')->assert($options))->assertStatus(422);
        $this->assertGuest();
    }

    public function test_wrong_passkeys_count_toward_the_lockout(): void
    {
        $user = User::factory()->create();
        $key = new SoftAuthenticator;
        $this->registerPasskey($user, $key)->assertOk();
        auth()->logout();
        $this->flushSession();
        $this->atSecondStep($user);

        foreach (range(1, 5) as $i) {
            $options = $this->postJson('/two-factor/passkey/options')->json();
            $this->postJson('/two-factor/passkey/verify', $key->withOrigin('https://evil.test')->assert($options))->assertStatus(422);
        }

        $options = $this->postJson('/two-factor/passkey/options')->json();
        $this->postJson('/two-factor/passkey/verify', $key->assert($options))->assertStatus(429);
        $this->assertGuest();
    }

    public function test_confirming_with_a_passkey(): void
    {
        $user = User::factory()->create();
        $key = new SoftAuthenticator;
        $this->registerPasskey($user, $key)->assertOk();
        config(['panel.security.step_up_minutes' => 10]);

        $this->actingAs($user)->withSession(['confirmed_at' => 1]);
        $options = $this->postJson('/confirm/passkey/options')->assertOk()->json();
        $this->postJson('/confirm/passkey/verify', $key->assert($options))->assertOk();

        $this->assertGreaterThan(now()->timestamp - 5, session('confirmed_at'));
    }

    public function test_passkeys_can_only_be_removed_by_their_owner_and_not_the_last_when_required(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->registerPasskey($user, new SoftAuthenticator)->assertOk();
        $mine = $user->passkeys()->first();
        $this->registerPasskey($other, new SoftAuthenticator)->assertOk();
        $theirs = $other->passkeys()->first();

        $this->actingAs($user)->delete("/security/passkeys/{$theirs->id}")->assertNotFound();
        $this->assertDatabaseHas('passkeys', ['id' => $theirs->id]);

        config(['panel.security.two_factor_required_roles' => ['read_only']]);
        $this->actingAs($user)->from('/security')->delete("/security/passkeys/{$mine->id}")->assertSessionHasErrors('passkey');
        $this->assertDatabaseHas('passkeys', ['id' => $mine->id]);

        config(['panel.security.two_factor_required_roles' => []]);
        $this->actingAs($user)->delete("/security/passkeys/{$mine->id}")->assertRedirect();
        $this->assertDatabaseMissing('passkeys', ['id' => $mine->id]);
    }

    public function test_passkey_options_never_expose_other_peoples_credentials(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $this->registerPasskey($alice, new SoftAuthenticator)->assertOk();
        $bobKey = new SoftAuthenticator;
        $this->registerPasskey($bob, $bobKey)->assertOk();
        auth()->logout();
        $this->flushSession();

        $this->atSecondStep($alice);
        $options = $this->postJson('/two-factor/passkey/options')->json();

        $this->assertCount(1, $options['allowCredentials']);
        $this->assertStringNotContainsString(rtrim(strtr(base64_encode($bobKey->credentialId), '+/', '-_'), '='), json_encode($options));
    }
}

/** Reads the details of the newest audit row for an action. */
class AuditDetails
{
    public static function last(string $action): array
    {
        return \App\Models\AuditLog::where('action', $action)->latest('id')->first()->details ?? [];
    }
}
