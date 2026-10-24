<?php

namespace Tests\Feature;

use App\Models\Forwarder;
use App\Models\Mailbox;
use App\Models\PendingDeletion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\PanelFixtures;
use Tests\TestCase;

class MailboxTest extends TestCase
{
    use PanelFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPanel();
        $this->fakeCpanel();
    }

    private function mailbox($domain, string $local = 'anna', array $attrs = []): Mailbox
    {
        return Mailbox::create($attrs + ['address' => "$local@{$domain->name}", 'local_part' => $local, 'domain_id' => $domain->id, 'quota_mb' => 1000, 'used_mb' => 10]);
    }

    public function test_create_mailbox_calls_cpanel_updates_cache_audits_and_shows_password_once(): void
    {
        $d = $this->domain();
        $admin = $this->admin();

        $response = $this->actingAs($admin)->post('/mailboxes', ['domain_id' => $d->id, 'local_part' => 'Anna', 'quota_mb' => 500]);

        $response->assertOk()->assertSee('shown once');
        $this->assertDatabaseHas('mailboxes_cache', ['address' => 'anna@example.com', 'quota_mb' => 500]);
        $this->assertDatabaseHas('audit_log', ['action' => 'mailbox.create', 'target' => 'anna@example.com', 'result' => 'success']);

        Http::assertSent(function ($req) {
            $b = $req->data();

            return str_contains($req->url(), '/execute/Email/add_pop')
                && $b['email'] === 'anna' && $b['domain'] === 'example.com' && strlen($b['password']) >= 16
                && $req->hasHeader('Authorization', 'cpanel acct:SECRET-TOKEN-123');
        });

        // The generated password exists only in the response, never in the database or the audit log.
        $password = Http::recorded()->first()[0]->data()['password'];
        $response->assertSee($password, false);
        $this->assertStringNotContainsString($password, json_encode(\DB::table('audit_log')->get()));
        $this->assertStringNotContainsString($password, json_encode(\DB::table('mailboxes_cache')->get()));
    }

    public function test_page_output_never_contains_the_api_token(): void
    {
        $d = $this->domain();
        $m = $this->mailbox($d);
        $admin = $this->admin();

        foreach (['/', '/mailboxes', "/mailboxes/{$m->id}", '/admin/settings', '/forwarders', '/health'] as $url) {
            $this->actingAs($admin)->get($url)->assertOk()->assertDontSee('SECRET-TOKEN-123');
        }
    }

    public function test_password_is_sent_in_post_body_not_url(): void
    {
        $d = $this->domain();
        $this->actingAs($this->admin())->post('/mailboxes', ['domain_id' => $d->id, 'local_part' => 'bob', 'password' => 'S3cret-pass-word']);

        Http::assertSent(fn ($req) => $req->method() === 'POST'
            && ! str_contains($req->url(), 'S3cret') && $req->data()['password'] === 'S3cret-pass-word');
    }

    public function test_duplicate_and_invalid_addresses_are_rejected(): void
    {
        $d = $this->domain();
        $this->mailbox($d);
        $admin = $this->admin();

        $this->actingAs($admin)->post('/mailboxes', ['domain_id' => $d->id, 'local_part' => 'anna'])->assertSessionHasErrors('local_part');
        $this->actingAs($admin)->post('/mailboxes', ['domain_id' => $d->id, 'local_part' => 'bad name!'])->assertSessionHasErrors('local_part');
        $this->actingAs($admin)->post('/mailboxes', ['domain_id' => $d->id, 'local_part' => 'ok', 'quota_mb' => 999999999])->assertSessionHasErrors('quota_mb');
        $this->assertSame(0, $this->cpanelCalls('add_pop'));
    }

    public function test_domain_naming_pattern_is_enforced(): void
    {
        $d = $this->domain('corp.com', ['naming_pattern' => 'first.last']);

        $this->actingAs($this->admin())->post('/mailboxes', ['domain_id' => $d->id, 'local_part' => 'anna'])->assertSessionHasErrors('local_part');
        $this->actingAs($this->admin())->post('/mailboxes', ['domain_id' => $d->id, 'local_part' => 'anna.smith'])->assertOk();
    }

    public function test_cpanel_error_is_shown_and_audited_as_failure(): void
    {
        $this->fakeCpanel([], 'add_pop');
        $d = $this->domain();

        $this->actingAs($this->admin())->post('/mailboxes', ['domain_id' => $d->id, 'local_part' => 'anna'])
            ->assertSessionHasErrors('cpanel');

        $this->assertDatabaseMissing('mailboxes_cache', ['address' => 'anna@example.com']);
        $this->assertDatabaseHas('audit_log', ['action' => 'mailbox.create', 'result' => 'failure']);
    }

    public function test_quota_password_suspend_unsuspend(): void
    {
        $d = $this->domain(attrs: ['suspend_days' => 14]);
        $m = $this->mailbox($d);
        $admin = $this->admin();

        $this->actingAs($admin)->post("/mailboxes/{$m->id}/quota", ['quota_mb' => 2048])->assertRedirect();
        $this->assertSame(2048, $m->fresh()->quota_mb);

        $this->actingAs($admin)->post("/mailboxes/{$m->id}/password", [])->assertOk()->assertSee('shown once');

        $this->actingAs($admin)->post("/mailboxes/{$m->id}/suspend")->assertRedirect();
        $this->assertTrue($m->fresh()->suspended_login && $m->fresh()->suspended_incoming);
        $this->assertSame(1, $this->cpanelCalls('suspend_login'));
        $this->assertSame(1, $this->cpanelCalls('suspend_incoming'));
        $pending = PendingDeletion::where('address', $m->address)->first();
        $this->assertEqualsWithDelta(14, now()->diffInDays($pending->delete_after), 1);

        $this->actingAs($admin)->post("/mailboxes/{$m->id}/unsuspend")->assertRedirect();
        $this->assertFalse($m->fresh()->isSuspended());
        $this->assertSame(0, PendingDeletion::count());
    }

    public function test_delete_requires_the_typed_address(): void
    {
        $d = $this->domain();
        $m = $this->mailbox($d);
        $admin = $this->admin();

        $this->actingAs($admin)->delete("/mailboxes/{$m->id}", ['confirm' => 'wrong'])->assertSessionHasErrors('confirm');
        $this->assertSame(0, $this->cpanelCalls('delete_pop'));
        $this->assertDatabaseHas('mailboxes_cache', ['id' => $m->id]);

        $this->actingAs($admin)->delete("/mailboxes/{$m->id}", ['confirm' => $m->address])->assertRedirect('/mailboxes');
        $this->assertSame(1, $this->cpanelCalls('delete_pop'));
        $this->assertDatabaseMissing('mailboxes_cache', ['id' => $m->id]);
    }

    public function test_delete_lists_forwarders_that_point_at_the_mailbox_and_clears_cache(): void
    {
        $d = $this->domain();
        $m = $this->mailbox($d);
        Forwarder::create(['source' => 'info@example.com', 'destination' => $m->address, 'type' => 'address', 'domain_id' => $d->id]);
        Forwarder::create(['source' => $m->address, 'destination' => 'x@example.com', 'type' => 'address', 'domain_id' => $d->id]);
        $admin = $this->admin();

        $this->actingAs($admin)->get("/mailboxes/{$m->id}/delete")->assertOk()->assertSee('info@example.com');

        $this->actingAs($admin)->delete("/mailboxes/{$m->id}", ['confirm' => $m->address])
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'info@example.com'));

        $this->assertSame(0, Forwarder::where('source', $m->address)->count());
    }

    public function test_suspend_first_rule_blocks_early_deletion(): void
    {
        $d = $this->domain(attrs: ['require_suspend_first' => true, 'suspend_days' => 30]);
        $m = $this->mailbox($d);
        $admin = $this->admin();

        $this->actingAs($admin)->delete("/mailboxes/{$m->id}", ['confirm' => $m->address])->assertSessionHasErrors('cpanel');
        $this->assertSame(0, $this->cpanelCalls('delete_pop'));

        $this->actingAs($admin)->post("/mailboxes/{$m->id}/suspend");
        $this->actingAs($admin)->delete("/mailboxes/{$m->id}", ['confirm' => $m->address])->assertSessionHasErrors('cpanel');

        PendingDeletion::query()->update(['delete_after' => now()->subDay()]);
        $this->actingAs($admin)->delete("/mailboxes/{$m->id}", ['confirm' => $m->address])->assertRedirect('/mailboxes');
        $this->assertSame(1, $this->cpanelCalls('delete_pop'));
    }

    public function test_list_filters(): void
    {
        $d = $this->domain();
        $this->mailbox($d, 'anna');
        $this->mailbox($d, 'full', ['quota_mb' => 100, 'used_mb' => 95]);
        $this->mailbox($d, 'paused', ['suspended_login' => true]);
        Forwarder::create(['source' => 'anna@example.com', 'destination' => 'z@example.com', 'type' => 'address', 'domain_id' => $d->id]);
        $admin = $this->admin();

        $this->actingAs($admin)->get('/mailboxes?quota=near')->assertSee('full@example.com')->assertDontSee('anna@example.com');
        $this->actingAs($admin)->get('/mailboxes?status=suspended')->assertSee('paused@example.com')->assertDontSee('anna@example.com');
        $this->actingAs($admin)->get('/mailboxes?forwarders=with')->assertSee('anna@example.com')->assertDontSee('full@example.com');
        $this->actingAs($admin)->get('/mailboxes?q=ann')->assertSee('anna@example.com')->assertDontSee('paused@example.com');
    }

    public function test_csv_export_neutralises_formula_cells(): void
    {
        $d = $this->domain();
        $this->mailbox($d, 'anna');

        $csv = $this->actingAs($this->admin())->get('/mailboxes/export')->streamedContent();

        $this->assertStringContainsString('anna@example.com', $csv);
        $this->assertSame("'=1+1", \App\Support\Csv::safe('=1+1'));
    }
}
