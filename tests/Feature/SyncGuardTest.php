<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\PanelFixtures;
use Tests\TestCase;

class SyncGuardTest extends TestCase
{
    use PanelFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPanel();
        $this->fakeCpanel();
        config(['queue.default' => 'database']);
    }

    public function test_sync_all_with_no_domains_says_so_instead_of_pretending_to_work(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/settings/sync')
            ->assertRedirect()->assertSessionHasErrors('sync')->assertSessionMissing('status');
        $this->assertSame(0, DB::table('jobs')->count());

        $this->actingAs($admin)->get('/admin/settings')->assertOk()->assertSee('no domains yet')->assertSee('Import your domains from cPanel');
    }

    public function test_sync_all_still_queues_one_job_per_domain(): void
    {
        $this->domain('a.example.com');
        $this->domain('b.example.com');

        $this->actingAs($this->admin())->post('/admin/settings/sync')->assertSessionHas('status');
        $this->assertSame(2, DB::table('jobs')->count());
    }
}
