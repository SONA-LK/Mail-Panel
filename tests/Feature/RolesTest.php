<?php

namespace Tests\Feature;

use App\Models\Forwarder;
use App\Models\Mailbox;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PanelFixtures;
use Tests\TestCase;

class RolesTest extends TestCase
{
    use PanelFixtures, RefreshDatabase;

    private $a;
    private $b;
    private Mailbox $boxA;
    private Mailbox $boxB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPanel();
        $this->fakeCpanel();
        $this->a = $this->domain('a.com');
        $this->b = $this->domain('b.com');
        $this->boxA = Mailbox::create(['address' => 'x@a.com', 'local_part' => 'x', 'domain_id' => $this->a->id, 'quota_mb' => 100]);
        $this->boxB = Mailbox::create(['address' => 'y@b.com', 'local_part' => 'y', 'domain_id' => $this->b->id, 'quota_mb' => 100]);
    }

    public function test_domain_manager_opening_another_domain_by_url_is_denied_and_logged(): void
    {
        $mgr = $this->manager($this->a);

        $this->actingAs($mgr)->get("/mailboxes/{$this->boxA->id}")->assertOk();
        $this->actingAs($mgr)->get("/mailboxes/{$this->boxB->id}")->assertForbidden();
        $this->actingAs($mgr)->get("/mailboxes/{$this->boxB->id}/settings")->assertForbidden();
        $this->actingAs($mgr)->get("/mailboxes/{$this->boxB->id}/delete")->assertForbidden();
        $this->assertDatabaseHas('audit_log', ['action' => 'access.denied', 'user_id' => $mgr->id, 'result' => 'denied']);
    }

    public function test_domain_manager_cannot_change_another_domain_with_forged_ids(): void
    {
        $mgr = $this->manager($this->a);

        $this->actingAs($mgr)->post('/mailboxes', ['domain_id' => $this->b->id, 'local_part' => 'new'])->assertForbidden();
        $this->actingAs($mgr)->post("/mailboxes/{$this->boxB->id}/quota", ['quota_mb' => 5])->assertForbidden();
        $this->actingAs($mgr)->post("/mailboxes/{$this->boxB->id}/suspend")->assertForbidden();
        $this->actingAs($mgr)->delete("/mailboxes/{$this->boxB->id}", ['confirm' => 'y@b.com'])->assertForbidden();
        $this->actingAs($mgr)->post('/forwarders', ['domain_id' => $this->b->id, 'local_part' => 'info', 'destinations' => 'z@b.com'])->assertForbidden();
        $this->actingAs($mgr)->put("/catch-all/{$this->b->id}", ['policy' => 'discard'])->assertForbidden();
        $this->assertSame(0, $this->cpanelCalls('add_pop') + $this->cpanelCalls('delete_pop') + $this->cpanelCalls('suspend_login'));
    }

    public function test_lists_only_show_own_domains(): void
    {
        $mgr = $this->manager($this->a);
        Forwarder::create(['source' => 'f@b.com', 'destination' => 'q@b.com', 'type' => 'address', 'domain_id' => $this->b->id]);

        $this->actingAs($mgr)->get('/mailboxes')->assertSee('x@a.com')->assertDontSee('y@b.com');
        $this->actingAs($mgr)->get('/forwarders')->assertDontSee('f@b.com');
        $this->actingAs($mgr)->get('/mailboxes/export')->assertOk();
        $this->assertStringNotContainsString('y@b.com', $this->actingAs($mgr)->get('/mailboxes/export')->streamedContent());
    }

    public function test_audit_log_is_scoped_to_own_domains_for_non_admins(): void
    {
        \App\Services\Audit::record($this->admin(), 'mailbox.create', 'x@a.com', $this->a);
        \App\Services\Audit::record($this->admin(), 'mailbox.create', 'y@b.com', $this->b);
        $mgr = $this->manager($this->a);

        $this->actingAs($mgr)->get('/audit')->assertSee('x@a.com')->assertDontSee('y@b.com');
        $this->actingAs($this->admin())->get('/audit')->assertSee('x@a.com')->assertSee('y@b.com');
    }

    public function test_read_only_user_is_blocked_on_screen_and_on_the_server(): void
    {
        $ro = $this->readonly($this->a);

        $this->actingAs($ro)->get("/mailboxes/{$this->boxA->id}")->assertOk()->assertDontSee('Reset password')->assertDontSee('Delete…');
        $this->actingAs($ro)->get('/mailboxes')->assertDontSee('New mailbox');

        $this->actingAs($ro)->post('/mailboxes', ['domain_id' => $this->a->id, 'local_part' => 'new'])->assertForbidden();
        $this->actingAs($ro)->post("/mailboxes/{$this->boxA->id}/quota", ['quota_mb' => 5])->assertForbidden();
        $this->actingAs($ro)->post("/mailboxes/{$this->boxA->id}/password")->assertForbidden();
        $this->actingAs($ro)->delete("/mailboxes/{$this->boxA->id}", ['confirm' => 'x@a.com'])->assertForbidden();
        $this->actingAs($ro)->post('/forwarders', ['domain_id' => $this->a->id, 'local_part' => 'i', 'destinations' => 'q@a.com'])->assertForbidden();
        $this->actingAs($ro)->get('/bulk')->assertForbidden();
        $this->assertSame(0, $this->cpanelCalls('add_pop') + $this->cpanelCalls('passwd_pop') + $this->cpanelCalls('delete_pop'));
    }

    public function test_only_super_admin_reaches_admin_pages(): void
    {
        foreach (['/admin/users', '/admin/domains', '/admin/settings'] as $url) {
            $this->actingAs($this->manager($this->a))->get($url)->assertForbidden();
            $this->actingAs($this->readonly($this->a))->get($url)->assertForbidden();
            $this->actingAs($this->admin())->get($url)->assertOk();
        }
        $this->actingAs($this->manager($this->a))->post('/admin/settings/sync')->assertForbidden();
    }

    public function test_super_admin_sees_every_domain(): void
    {
        $this->actingAs($this->admin())->get('/mailboxes')->assertSee('x@a.com')->assertSee('y@b.com');
    }

    public function test_cannot_remove_the_last_active_super_admin(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put("/admin/users/{$admin->id}", ['name' => 'A', 'email' => $admin->email, 'role' => 'read_only', 'active' => 1])
            ->assertSessionHasErrors('role');
        $this->assertSame('super_admin', $admin->fresh()->role);

        $this->actingAs($admin)->delete("/admin/users/{$admin->id}")->assertSessionHasErrors('user');
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_adding_a_user_adds_them_to_the_approved_list(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/users', [
            'name' => 'New Person', 'email' => 'New@Example.com', 'role' => 'domain_manager', 'active' => 1, 'domains' => [$this->a->id],
        ])->assertRedirect('/admin/users');

        $u = User::where('email', 'new@example.com')->first();
        $this->assertNotNull($u);
        $this->assertTrue($u->canManage($this->a));
        $this->assertFalse($u->canManage($this->b));
        $this->assertTrue($u->canView($this->a) && ! $u->canView($this->b));
    }
}
