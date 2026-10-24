<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\PanelFixtures;
use Tests\TestCase;

class ApprovalsTest extends TestCase
{
    use PanelFixtures, RefreshDatabase;

    public function test_with_one_super_admin_removal_goes_straight_through(): void
    {
        $admin = $this->admin();
        $domain = $this->domain('solo.test');

        $this->actingAs($admin)->delete("/admin/domains/{$domain->id}")->assertRedirect('/admin/domains');

        $this->assertDatabaseMissing('domains', ['id' => $domain->id]);
        $this->assertSame(0, ApprovalRequest::count());
    }

    public function test_with_two_super_admins_removing_a_domain_needs_the_other_one(): void
    {
        $a = $this->admin();
        $b = $this->admin();
        $domain = $this->domain('shared.test');

        $this->actingAs($a)->delete("/admin/domains/{$domain->id}")->assertRedirect('/admin/domains')
            ->assertSessionHas('status');

        $this->assertDatabaseHas('domains', ['id' => $domain->id]);
        $req = ApprovalRequest::first();
        $this->assertSame(['domain.remove', 'shared.test', 'pending', $a->id], [$req->action, $req->target, $req->status, $req->requested_by]);
        $this->assertDatabaseHas('audit_log', ['action' => 'approval.requested', 'user_id' => $a->id]);

        // Asking again does not pile up requests.
        $this->actingAs($a)->delete("/admin/domains/{$domain->id}");
        $this->assertSame(1, ApprovalRequest::count());

        // The person who asked cannot approve.
        $this->actingAs($a)->from('/admin/approvals')->post("/admin/approvals/{$req->id}/approve")->assertSessionHasErrors('approval');
        $this->assertDatabaseHas('domains', ['id' => $domain->id]);

        // The other super admin can.
        $this->actingAs($b)->post("/admin/approvals/{$req->id}/approve")->assertRedirect()->assertSessionHas('status');

        $this->assertDatabaseMissing('domains', ['id' => $domain->id]);
        $req->refresh();
        $this->assertSame(['approved', $b->id], [$req->status, $req->decided_by]);
        $this->assertDatabaseHas('audit_log', ['action' => 'domain.remove', 'target' => 'shared.test']);
        $this->assertDatabaseHas('audit_log', ['action' => 'approval.approved', 'user_id' => $b->id]);

        // And it cannot be approved a second time.
        $this->actingAs($b)->from('/admin/approvals')->post("/admin/approvals/{$req->id}/approve")->assertSessionHasErrors('approval');
    }

    public function test_a_request_can_be_rejected_and_expires(): void
    {
        $a = $this->admin();
        $b = $this->admin();
        $keep = $this->domain('keep.test');
        $late = $this->domain('late.test');

        $this->actingAs($a)->delete("/admin/domains/{$keep->id}");
        $this->actingAs($a)->delete("/admin/domains/{$late->id}");
        [$first, $second] = ApprovalRequest::orderBy('id')->get()->all();

        $this->actingAs($b)->post("/admin/approvals/{$first->id}/reject")->assertRedirect();
        $this->assertSame('rejected', $first->fresh()->status);
        $this->assertDatabaseHas('domains', ['id' => $keep->id]);
        $this->actingAs($b)->from('/admin/approvals')->post("/admin/approvals/{$first->id}/approve")->assertSessionHasErrors('approval');

        config(['panel.security.session_absolute_minutes' => 0]);   // this test is about the request expiring, not the session
        $this->travel(config('panel.security.four_eyes_hours') + 1)->hours();
        $this->actingAs($b)->from('/admin/approvals')->post("/admin/approvals/{$second->id}/approve")->assertSessionHasErrors('approval');
        $this->assertDatabaseHas('domains', ['id' => $late->id]);
    }

    public function test_removing_a_super_admin_needs_approval_but_removing_anyone_else_does_not(): void
    {
        $a = $this->admin();
        $b = $this->admin();
        $reader = User::factory()->create();

        $this->actingAs($a)->delete("/admin/users/{$reader->id}")->assertRedirect();
        $this->assertDatabaseMissing('users', ['id' => $reader->id]);

        $this->actingAs($a)->delete("/admin/users/{$b->id}")->assertRedirect();
        $this->assertDatabaseHas('users', ['id' => $b->id]);
        $req = ApprovalRequest::first();
        $this->assertSame('user.delete', $req->action);

        $c = $this->admin();
        $this->actingAs($c)->post("/admin/approvals/{$req->id}/approve")->assertRedirect();
        $this->assertDatabaseMissing('users', ['id' => $b->id]);
    }

    public function test_the_last_super_admin_check_is_repeated_when_approving(): void
    {
        $a = $this->admin();
        $b = $this->admin();
        $this->actingAs($a)->delete("/admin/users/{$b->id}");
        $req = ApprovalRequest::first();

        $a->update(['active' => false]);   // meanwhile the only other super admin is switched off

        $this->actingAs($b)->from('/admin/approvals')->post("/admin/approvals/{$req->id}/approve")->assertSessionHasErrors('approval');
        $this->assertDatabaseHas('users', ['id' => $b->id]);
    }

    public function test_disabling_or_demoting_the_other_super_admin_needs_approval_too(): void
    {
        $a = $this->admin();
        $b = $this->admin();
        $domain = $this->domain('guarded.test');
        $body = ['name' => $b->name, 'email' => $b->email, 'role' => 'super_admin', 'active' => 0];

        // One admin switching the other off must not make four-eyes disappear.
        $this->actingAs($a)->put("/admin/users/{$b->id}", $body)->assertRedirect('/admin/users');
        $this->assertTrue($b->fresh()->active);
        $this->actingAs($a)->delete("/admin/domains/{$domain->id}");
        $this->assertDatabaseHas('domains', ['id' => $domain->id]);

        $demote = ApprovalRequest::where('action', 'user.demote')->first();
        $this->actingAs($b)->post("/admin/approvals/{$demote->id}/approve")->assertRedirect();
        $this->assertFalse($b->fresh()->active);

        // Changing a super admin without losing rights (a name) is not gated.
        $c = $this->admin();
        $this->actingAs($a)->put("/admin/users/{$c->id}", ['name' => 'New Name', 'email' => $c->email, 'role' => 'super_admin', 'active' => 1])->assertRedirect();
        $this->assertSame('New Name', $c->fresh()->name);
    }

    public function test_four_eyes_can_be_switched_off(): void
    {
        config(['panel.security.four_eyes' => false]);
        $a = $this->admin();
        $this->admin();
        $domain = $this->domain('free.test');

        $this->actingAs($a)->delete("/admin/domains/{$domain->id}");

        $this->assertDatabaseMissing('domains', ['id' => $domain->id]);
    }

    public function test_only_super_admins_see_or_decide_approvals(): void
    {
        $a = $this->admin();
        $this->admin();
        $domain = $this->domain('x.test');
        $this->actingAs($a)->delete("/admin/domains/{$domain->id}");
        $req = ApprovalRequest::first();
        $manager = $this->manager($domain);

        $this->actingAs($manager)->get('/admin/approvals')->assertForbidden();
        $this->actingAs($manager)->post("/admin/approvals/{$req->id}/approve")->assertForbidden();
        $this->actingAs($manager)->post("/admin/approvals/{$req->id}/reject")->assertForbidden();
        $this->assertDatabaseHas('domains', ['id' => $domain->id]);
    }

    public function test_the_page_lists_waiting_requests_and_the_menu_counts_them(): void
    {
        $a = $this->admin();
        $b = $this->admin();
        $domain = $this->domain('listed.test');
        $this->actingAs($a)->delete("/admin/domains/{$domain->id}");

        $this->actingAs($b)->get('/admin/approvals')->assertOk()->assertSee('listed.test')->assertSee('Approve and do it');
        $this->actingAs($a)->get('/admin/approvals')->assertOk()->assertSee('your request')->assertDontSee('Approve and do it');
        $this->actingAs($b)->get('/')->assertSee('Approvals <span class="badge warn">1</span>', false);
    }

    public function test_deciding_needs_a_fresh_confirmation(): void
    {
        $a = $this->admin();
        $b = $this->admin();
        $domain = $this->domain('fresh.test');
        $this->actingAs($a)->delete("/admin/domains/{$domain->id}");
        $req = ApprovalRequest::first();
        config(['panel.security.step_up_minutes' => 10]);

        $this->actingAs($b)->withSession(['confirmed_at' => 1])->post("/admin/approvals/{$req->id}/approve")->assertRedirect('/confirm');
        $this->assertDatabaseHas('domains', ['id' => $domain->id]);
    }

    public function test_asking_for_approval_alerts_the_other_super_admins(): void
    {
        config(['panel.security.alerts' => true]);
        Mail::fake();
        $a = $this->admin();
        $b = $this->admin();
        $domain = $this->domain('mail.test');

        $this->actingAs($a)->delete("/admin/domains/{$domain->id}");

        Mail::assertQueued(\App\Mail\SecurityAlert::class, fn ($m) => $m->hasTo($b->email) && str_contains($m->headline, 'approve'));
    }
}
