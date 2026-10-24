<?php

namespace Tests\Feature;

use App\Models\Autoresponder;
use App\Models\Domain;
use App\Models\Forwarder;
use App\Models\Mailbox;
use App\Models\MailFilter;
use App\Models\PendingDeletion;
use App\Models\SyncRun;
use App\Services\CpanelClient;
use App\Services\CpanelException;
use App\Services\HealthService;
use App\Services\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\PanelFixtures;
use Tests\TestCase;

class SyncAndHealthTest extends TestCase
{
    use PanelFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPanel();
    }

    private function cpanelState(array $pops, array $forwarders = [], array $catchAll = []): array
    {
        return [
            'Email/list_pops_with_disk' => $pops,
            'Email/list_forwarders' => $forwarders,
            'Email/list_domain_forwarders' => [],
            'Email/list_default_address' => $catchAll,
            'Email/list_auto_responders' => [],
            'Email/list_filters' => [],
        ];
    }

    private function pop(string $email, $used, $quota, array $extra = []): array
    {
        return ['email' => $email, 'diskused' => $used, 'diskquota' => $quota] + $extra;
    }

    public function test_sync_loads_cpanel_state_into_the_cache(): void
    {
        $d = $this->domain('example.com');
        $this->fakeCpanel($this->cpanelState(
            [$this->pop('anna@example.com', 12.5, 1024), $this->pop('open@example.com', 1, 'unlimited', ['suspended_login' => 1]), $this->pop('acct', 0, 'unlimited')],
            [['dest' => 'info@example.com', 'forward' => 'anna@example.com']],
            [['defaultaddress' => ':blackhole:']],
        ));

        $run = app(SyncService::class)->syncDomain($d, 'manual');

        $this->assertSame(4, $run->added);
        $this->assertNull($run->errors);
        $this->assertNotNull($run->finished_at);
        $this->assertSame(2, Mailbox::count(), 'The cPanel system account is not a mailbox.');
        $this->assertSame(1024, Mailbox::where('address', 'anna@example.com')->first()->quota_mb);
        $this->assertNull(Mailbox::where('address', 'open@example.com')->first()->quota_mb);
        $this->assertTrue(Mailbox::where('address', 'open@example.com')->first()->suspended_login);
        $this->assertDatabaseHas('forwarders_cache', ['source' => 'info@example.com', 'destination' => 'anna@example.com', 'type' => 'address']);
        $this->assertDatabaseHas('forwarders_cache', ['source' => '*@example.com', 'destination' => ':blackhole:', 'type' => 'catchall']);
    }

    public function test_sync_keeps_the_domains_catch_all_policy_equal_to_cpanel(): void
    {
        $d = $this->domain('example.com');
        $sync = app(SyncService::class);

        foreach ([
            [':fail: No such person', 'reject', null],
            ['":fail: No Such User Here"', 'reject', null], // real cPanel format: value wrapped in quotes
            ['":blackhole:"', 'discard', null],
            [':blackhole:', 'discard', null],
            ['acct', 'default', null],
            ['Boss@Example.com', 'forward', 'boss@example.com'],
        ] as [$value, $policy, $target]) {
            $this->fakeCpanel($this->cpanelState([], [], [['defaultaddress' => $value]]));
            $sync->syncDomain($d);

            $d->refresh();
            $this->assertSame($policy, $d->catchall_policy, "cPanel value $value");
            $this->assertSame($target, $d->catchall_target);
        }
    }

    public function test_manual_edit_in_cpanel_shows_up_after_the_next_sync(): void
    {
        $d = $this->domain('example.com');
        $sync = app(SyncService::class);

        $this->fakeCpanel($this->cpanelState([$this->pop('anna@example.com', 1, 100), $this->pop('gone@example.com', 1, 100)], [['dest' => 'f@example.com', 'forward' => 'anna@example.com']]));
        $sync->syncDomain($d);
        $this->assertSame(2, Mailbox::count());

        // In cPanel someone: raised anna's quota, deleted gone@, added new@, removed the forwarder.
        $this->fakeCpanel($this->cpanelState([$this->pop('anna@example.com', 50, 4096), $this->pop('new@example.com', 0, 100)]));
        $run = $sync->syncDomain($d);

        $this->assertSame(4096, Mailbox::where('address', 'anna@example.com')->first()->quota_mb);
        $this->assertSame(50.0, Mailbox::where('address', 'anna@example.com')->first()->used_mb);
        $this->assertDatabaseMissing('mailboxes_cache', ['address' => 'gone@example.com']);
        $this->assertDatabaseHas('mailboxes_cache', ['address' => 'new@example.com']);
        $this->assertSame(0, Forwarder::count());
        $this->assertSame([1, 1, 2], [$run->added, $run->changed, $run->removed]); // new@; anna quota; gone@ + forwarder
    }

    public function test_usage_only_changes_are_not_counted_as_changes(): void
    {
        $d = $this->domain('example.com');
        $this->fakeCpanel($this->cpanelState([$this->pop('anna@example.com', 1, 100)]));
        app(SyncService::class)->syncDomain($d);

        $this->fakeCpanel($this->cpanelState([$this->pop('anna@example.com', 99, 100)]));
        $run = app(SyncService::class)->syncDomain($d);

        $this->assertSame(0, $run->changed);
        $this->assertSame(99.0, Mailbox::first()->used_mb);
    }

    public function test_failed_cpanel_read_keeps_the_cache_and_records_the_error(): void
    {
        $d = $this->domain('example.com');
        $this->fakeCpanel($this->cpanelState([$this->pop('anna@example.com', 1, 100)]));
        app(SyncService::class)->syncDomain($d);

        $this->resetHttp();
        Http::fake(['*' => Http::response(['status' => 0, 'errors' => ['API is down']])]);
        $run = app(SyncService::class)->syncDomain($d);

        $this->assertSame(1, Mailbox::count(), 'A failed read must not wipe the cache.');
        $this->assertStringContainsString('API is down', $run->errors);
        $this->assertSame(0, $run->removed);
    }

    public function test_sync_syncs_autoresponders_and_filters_and_prunes_them(): void
    {
        $d = $this->domain('example.com');
        $state = $this->cpanelState([$this->pop('anna@example.com', 1, 100)]);
        $state['Email/list_auto_responders'] = [['email' => 'anna@example.com', 'subject' => 'Away']];
        $state['Email/list_filters'] = [['filtername' => 'Spam', 'rules' => [['part' => 'Subject', 'match' => 'contains', 'val' => 'viagra']], 'actions' => [['action' => 'save', 'dest' => '/dev/null']]]];
        $this->fakeCpanel($state);
        app(SyncService::class)->syncDomain($d);

        $this->assertSame('Away', Autoresponder::first()->subject);
        $this->assertStringContainsString('viagra', MailFilter::first()->rule_summary);

        $this->fakeCpanel($this->cpanelState([$this->pop('anna@example.com', 1, 100)]));
        app(SyncService::class)->syncDomain($d);
        $this->assertSame(0, Autoresponder::count());
        $this->assertSame(0, MailFilter::count());
    }

    public function test_sync_runs_are_recorded_and_the_command_works(): void
    {
        $this->domain('example.com');
        $this->domain('second.com');
        $this->fakeCpanel($this->cpanelState([]));

        $this->artisan('panel:sync')->assertSuccessful();
        $this->assertSame(2, SyncRun::count());

        $this->artisan('panel:sync', ['domain' => 'second.com'])->assertSuccessful();
        $this->assertSame(3, SyncRun::count());
    }

    public function test_manual_sync_from_settings_is_queued_not_run_in_the_request(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $d = $this->domain('example.com');

        $this->actingAs($this->admin())->post("/admin/settings/sync/{$d->id}")->assertRedirect();

        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\SyncDomainJob::class);
    }

    public function test_dashboard_surfaces_sync_errors_and_failed_jobs(): void
    {
        $d = $this->domain('example.com');
        SyncRun::create(['domain_id' => $d->id, 'trigger' => 'schedule', 'started_at' => now(), 'errors' => 'mailboxes: API is down']);
        \DB::table('failed_jobs')->insert(['uuid' => 'u1', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()]);

        $this->actingAs($this->admin())->get('/')->assertSee('API is down')->assertSee('1 background job(s) failed');
    }

    public function test_dashboard_lists_mailboxes_near_quota_and_deletions_that_are_due(): void
    {
        $d = $this->domain('example.com');
        Mailbox::create(['address' => 'full@example.com', 'local_part' => 'full', 'domain_id' => $d->id, 'quota_mb' => 100, 'used_mb' => 90]);
        PendingDeletion::create(['address' => 'old@example.com', 'domain_id' => $d->id, 'suspended_at' => now()->subDays(40), 'delete_after' => now()->subDay()]);

        $this->actingAs($this->admin())->get('/')->assertSee('full@example.com')->assertSee('old@example.com');
    }

    // ---------------------------------------------------------------- health

    private function dns(array $records): HealthService
    {
        return new HealthService(fn (string $host, int $type) => $records[$host][$type] ?? []);
    }

    private function healthy(string $d = 'example.com'): array
    {
        return [
            $d => [DNS_MX => [['pri' => 10, 'target' => "mail.$d"]], DNS_TXT => [['txt' => 'v=spf1 +a +mx ~all']]],
            "default._domainkey.$d" => [DNS_TXT => [['txt' => 'v=DKIM1; k=rsa; p=ABC']]],
            "_dmarc.$d" => [DNS_TXT => [['txt' => 'v=DMARC1; p=quarantine']]],
        ];
    }

    public function test_healthy_domain_is_green(): void
    {
        $r = $this->dns($this->healthy())->check($this->domain());

        $this->assertSame('ok', $r['worst']);
        $this->assertSame(['MX', 'SPF', 'DKIM', 'DMARC'], array_column($r['checks'], 'name'));
    }

    public function test_broken_dkim_shows_red_with_a_hint(): void
    {
        $records = $this->healthy();
        unset($records['default._domainkey.example.com']);

        $r = $this->dns($records)->check($this->domain());
        $dkim = collect($r['checks'])->firstWhere('name', 'DKIM');

        $this->assertSame('fail', $r['worst']);
        $this->assertSame('fail', $dkim['status']);
        $this->assertStringContainsString('Email Deliverability', $dkim['hint']);
    }

    public function test_spf_and_dmarc_edge_cases(): void
    {
        $records = $this->healthy();
        $records['example.com'][DNS_TXT] = [['txt' => 'v=spf1 +a'], ['txt' => 'v=spf1 +mx ~all']];
        $records['_dmarc.example.com'][DNS_TXT] = [['txt' => 'v=DMARC1; p=none']];
        $checks = collect($this->dns($records)->check($this->domain())['checks'])->keyBy('name');

        $this->assertSame('fail', $checks['SPF']['status']);
        $this->assertStringContainsString('More than one', $checks['SPF']['detail']);
        $this->assertSame('warn', $checks['DMARC']['status']);

        $records['example.com'][DNS_TXT] = [['txt' => 'v=spf1 +a']];
        $this->assertSame('warn', collect($this->dns($records)->check(Domain::first())['checks'])->firstWhere('name', 'SPF')['status']);

        $records['example.com'][DNS_MX] = [];
        $this->assertSame('fail', collect($this->dns($records)->check(Domain::first())['checks'])->firstWhere('name', 'MX')['status']);
    }

    public function test_health_result_is_cached_for_the_dashboard(): void
    {
        $d = $this->domain();
        $this->assertNull(app(HealthService::class)->cached($d));

        $this->dns($this->healthy())->check($d);

        $this->assertSame('ok', app(HealthService::class)->cached($d)['worst']);
        $this->actingAs($this->admin())->get('/')->assertSee('ok');
        $this->actingAs($this->admin())->get('/health')->assertOk()->assertSee('SPF');
    }

    // ---------------------------------------------------------------- client

    public function test_client_normalises_host_and_reports_cpanel_errors_without_leaking_the_token(): void
    {
        config(['cpanel.host' => 'https://cpanel.test:2083/']);
        $this->assertSame('cpanel.test', app(CpanelClient::class)->host());

        $this->resetHttp();
        Http::fake(['*' => Http::response(['status' => 0, 'errors' => ['Bad thing']])]);
        try {
            app(CpanelClient::class)->call('Email', 'list_pops');
            $this->fail('Expected an exception');
        } catch (CpanelException $e) {
            $this->assertSame('Bad thing', $e->getMessage());
        }

        $this->resetHttp();
        Http::fake(['*' => Http::response('<html>Login</html>', 200)]);
        $this->expectException(CpanelException::class);
        app(CpanelClient::class)->call('Email', 'list_pops');
    }

    public function test_client_refuses_to_run_unconfigured_and_never_echoes_the_token(): void
    {
        config(['cpanel.token' => null]);

        try {
            app(CpanelClient::class)->call('Email', 'list_pops');
            $this->fail('Expected an exception');
        } catch (CpanelException $e) {
            $this->assertStringContainsString('not configured', $e->getMessage());
        }

        config(['cpanel.token' => 'SECRET-TOKEN-123']);
        $this->resetHttp();
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error with SECRET-TOKEN-123 in it'));
        try {
            app(CpanelClient::class)->call('Email', 'list_pops');
            $this->fail('Expected an exception');
        } catch (CpanelException $e) {
            $this->assertStringNotContainsString('SECRET-TOKEN-123', $e->getMessage());
        }
    }

    public function test_connection_test_button(): void
    {
        $this->fakeCpanel(['Email/list_pops' => [['email' => 'a@example.com']]]);

        $this->actingAs($this->admin())->post('/admin/settings/test')->assertSessionHas('status', fn ($s) => str_contains($s, '1 mailbox'));
    }

    public function test_process_deletions_only_deletes_on_domains_that_opted_in(): void
    {
        $this->fakeCpanel();
        $admin = $this->admin();
        $auto = $this->domain('auto.com', ['auto_delete_suspended' => true]);
        $manual = $this->domain('manual.com', ['auto_delete_suspended' => false]);
        foreach ([[$auto, 'a@auto.com'], [$manual, 'm@manual.com']] as [$d, $addr]) {
            Mailbox::create(['address' => $addr, 'local_part' => strstr($addr, '@', true), 'domain_id' => $d->id, 'suspended_login' => true]);
            PendingDeletion::create(['address' => $addr, 'domain_id' => $d->id, 'suspended_at' => now()->subDays(40), 'delete_after' => now()->subDay(), 'requested_by' => $admin->id]);
        }

        $this->artisan('panel:process-deletions')->assertSuccessful();

        $this->assertDatabaseMissing('mailboxes_cache', ['address' => 'a@auto.com']);
        $this->assertDatabaseHas('mailboxes_cache', ['address' => 'm@manual.com']);
        $this->assertSame(1, $this->cpanelCalls('delete_pop'));
    }

    public function test_make_admin_command(): void
    {
        $this->artisan('panel:make-admin', ['email' => 'First@Example.com', 'name' => 'First'])->assertSuccessful();
        $this->artisan('panel:make-admin', ['email' => 'nope'])->assertFailed();

        $u = \App\Models\User::where('email', 'first@example.com')->first();
        $this->assertTrue($u->isSuperAdmin() && $u->active);
    }
}
