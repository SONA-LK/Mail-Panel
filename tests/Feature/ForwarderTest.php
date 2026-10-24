<?php

namespace Tests\Feature;

use App\Models\Forwarder;
use App\Services\ForwardingGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\PanelFixtures;
use Tests\TestCase;

class ForwarderTest extends TestCase
{
    use PanelFixtures, RefreshDatabase;

    private $d;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPanel();
        $this->fakeCpanel();
        $this->d = $this->domain('example.com');
        $this->domain('other.com');
    }

    private function fwd(string $src, string $dst, string $type = 'address'): Forwarder
    {
        return Forwarder::create(['source' => $src, 'destination' => $dst, 'type' => $type, 'domain_id' => $this->d->id]);
    }

    public function test_one_address_to_three_destinations(): void
    {
        $this->actingAs($this->admin())->post('/forwarders', [
            'domain_id' => $this->d->id, 'local_part' => 'info', 'destinations' => "a@example.com, b@example.com\nc@other.com",
        ])->assertRedirect();

        $this->assertSame(3, $this->cpanelCalls('add_forwarder'));
        $this->assertSame(3, Forwarder::where('source', 'info@example.com')->count());
        $this->assertDatabaseHas('audit_log', ['action' => 'forwarder.create', 'target' => 'info@example.com → a@example.com']);
    }

    public function test_loop_a_to_b_to_a_is_blocked_before_saving(): void
    {
        $this->fwd('a@example.com', 'b@example.com');

        $this->actingAs($this->admin())->post('/forwarders', [
            'domain_id' => $this->d->id, 'local_part' => 'b', 'destinations' => 'a@example.com',
        ])->assertSessionHasErrors('destinations');

        $this->assertSame(0, $this->cpanelCalls('add_forwarder'));
        $this->assertSame(1, Forwarder::count());
    }

    public function test_longer_loops_and_domain_forwarder_loops_are_found(): void
    {
        $this->fwd('a@example.com', 'b@example.com');
        $this->fwd('b@example.com', 'c@other.com');
        $guard = app(ForwardingGuard::class);

        $this->assertNotNull($guard->findLoop('c@other.com', ['a@example.com']));
        $this->assertNull($guard->findLoop('d@other.com', ['a@example.com']));

        // other.com forwards everything to example.com, so c@other.com -> c@example.com -> ... is followed.
        $this->fwd('*@other.com', '*@example.com', 'domain');
        $this->fwd('c@example.com', 'z@elsewhere.org');
        $this->assertNotNull($guard->findLoop('z@elsewhere.org', ['c@other.com']));
    }

    public function test_forwarding_to_itself_is_allowed_as_keep_a_copy(): void
    {
        $this->actingAs($this->admin())->post('/forwarders', [
            'domain_id' => $this->d->id, 'local_part' => 'anna', 'destinations' => 'anna.private@other.com', 'keep_copy' => 1,
        ])->assertRedirect();

        $this->assertDatabaseHas('forwarders_cache', ['source' => 'anna@example.com', 'destination' => 'anna@example.com']);
    }

    public function test_external_destination_needs_confirmation(): void
    {
        $admin = $this->admin();
        $payload = ['domain_id' => $this->d->id, 'local_part' => 'info', 'destinations' => 'someone@gmail.com'];

        $this->actingAs($admin)->post('/forwarders', $payload)->assertSessionHasErrors('destinations');
        $this->assertSame(0, $this->cpanelCalls('add_forwarder'));

        $this->actingAs($admin)->post('/forwarders', $payload + ['confirm_external' => 1])->assertRedirect();
        $this->assertSame(1, $this->cpanelCalls('add_forwarder'));
        $this->assertDatabaseHas('audit_log', ['action' => 'forwarder.create', 'target' => 'info@example.com → someone@gmail.com']);
    }

    public function test_internal_only_policy_rejects_external_even_when_confirmed(): void
    {
        $d = $this->domain('closed.com', ['forwarding_policy' => 'internal']);

        $this->actingAs($this->admin())->post('/forwarders', [
            'domain_id' => $d->id, 'local_part' => 'info', 'destinations' => 'x@gmail.com', 'confirm_external' => 1,
        ])->assertSessionHasErrors('destinations');
        $this->assertSame(0, $this->cpanelCalls('add_forwarder'));

        $this->actingAs($this->admin())->post('/forwarders', ['domain_id' => $d->id, 'local_part' => 'info', 'destinations' => 'x@example.com'])->assertRedirect();
    }

    public function test_approved_list_policy(): void
    {
        $d = $this->domain('listed.com', ['forwarding_policy' => 'list', 'forwarding_allowlist' => "partner.org\nboss@gmail.com"]);
        $admin = $this->admin();

        foreach (['x@partner.org', 'boss@gmail.com'] as $ok) {
            $this->actingAs($admin)->post('/forwarders', ['domain_id' => $d->id, 'local_part' => 'a'.md5($ok), 'destinations' => $ok, 'confirm_external' => 1])->assertRedirect();
        }
        $this->actingAs($admin)->post('/forwarders', ['domain_id' => $d->id, 'local_part' => 'no', 'destinations' => 'evil@gmail.com', 'confirm_external' => 1])
            ->assertSessionHasErrors('destinations');
    }

    public function test_duplicates_and_bad_destinations_are_rejected(): void
    {
        $this->fwd('info@example.com', 'a@example.com');
        $admin = $this->admin();

        $this->actingAs($admin)->post('/forwarders', ['domain_id' => $this->d->id, 'local_part' => 'info', 'destinations' => 'a@example.com'])->assertSessionHasErrors('destinations');
        $this->actingAs($admin)->post('/forwarders', ['domain_id' => $this->d->id, 'local_part' => 'info', 'destinations' => 'not-an-email'])->assertSessionHasErrors('destinations');
    }

    public function test_reverse_lookup(): void
    {
        $this->fwd('info@example.com', 'anna@example.com');
        $this->fwd('sales@example.com', 'anna@example.com');
        $this->fwd('other@example.com', 'bob@example.com');

        $this->actingAs($this->admin())->get('/forwarders?points_to=Anna@example.com')
            ->assertSee('info@example.com')->assertSee('sales@example.com');
    }

    public function test_domain_forwarder_and_its_loop_check(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/forwarders/domain', ['domain_id' => $this->d->id, 'dest_domain' => 'other.com'])->assertRedirect();
        $this->assertSame(1, $this->cpanelCalls('add_domain_forwarder'));
        $this->assertDatabaseHas('forwarders_cache', ['source' => '*@example.com', 'destination' => '*@other.com', 'type' => 'domain']);

        // other.com -> example.com would now loop.
        $other = \App\Models\Domain::where('name', 'other.com')->first();
        $this->actingAs($admin)->post('/forwarders/domain', ['domain_id' => $other->id, 'dest_domain' => 'example.com'])->assertSessionHasErrors('cpanel');
        $this->assertSame(1, $this->cpanelCalls('add_domain_forwarder'));
    }

    public function test_delete_forwarder(): void
    {
        $f = $this->fwd('info@example.com', 'a@example.com');

        $this->actingAs($this->admin())->delete("/forwarders/{$f->id}")->assertRedirect();

        $this->assertSame(1, $this->cpanelCalls('delete_forwarder'));
        $this->assertDatabaseMissing('forwarders_cache', ['id' => $f->id]);
    }

    public function test_catch_all_can_be_restored_to_the_cpanel_default(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->put("/catch-all/{$this->d->id}", ['policy' => 'reject'])->assertRedirect();

        // The cPanel account name has no "@"; the forward-address rules must not apply to it.
        $this->actingAs($admin)->put("/catch-all/{$this->d->id}", ['policy' => 'default'])->assertRedirect()->assertSessionHasNoErrors();

        Http::assertSent(fn ($req) => str_contains($req->url(), '/set_default_address')
            && ($req['fwdopt'] ?? null) === 'fwd' && ($req['fwdemail'] ?? null) === 'acct');
        $this->assertSame('acct', Forwarder::where('type', 'catchall')->first()->destination);
        $this->assertSame('default', $this->d->fresh()->catchall_policy);
        $this->assertNull($this->d->fresh()->catchall_target);
    }

    public function test_catch_all_forward_discard_reject(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put("/catch-all/{$this->d->id}", ['policy' => 'forward', 'target' => 'boss@example.com'])->assertRedirect();
        $this->assertSame('boss@example.com', Forwarder::where('type', 'catchall')->first()->destination);

        $this->actingAs($admin)->put("/catch-all/{$this->d->id}", ['policy' => 'discard'])->assertRedirect();
        $this->assertSame(':blackhole:', Forwarder::where('type', 'catchall')->first()->destination);

        $this->actingAs($admin)->put("/catch-all/{$this->d->id}", ['policy' => 'reject'])->assertRedirect();
        $this->assertSame(':fail:', Forwarder::where('type', 'catchall')->first()->destination);
        $this->assertSame(1, Forwarder::where('type', 'catchall')->count());

        $this->assertSame(3, $this->cpanelCalls('set_default_address'));
        \Illuminate\Support\Facades\Http::assertSent(fn ($r) => str_contains($r->url(), 'set_default_address') && ($r->data()['fwdopt'] ?? '') === 'blackhole');
        \Illuminate\Support\Facades\Http::assertSent(fn ($r) => str_contains($r->url(), 'set_default_address') && ($r->data()['fwdopt'] ?? '') === 'fail');

        $this->actingAs($admin)->put("/catch-all/{$this->d->id}", ['policy' => 'forward'])->assertSessionHasErrors('target');
    }
}
