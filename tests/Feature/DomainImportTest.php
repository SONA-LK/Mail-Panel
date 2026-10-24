<?php

namespace Tests\Feature;

use App\Jobs\SyncDomainJob;
use App\Models\Domain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\PanelFixtures;
use Tests\TestCase;

class DomainImportTest extends TestCase
{
    use PanelFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPanel();
        $this->fakeCpanel(['Email/list_mail_domains' => [
            ['domain' => 'example.com'], ['domain' => 'Shop.example.com'], ['domain' => 'blog.example.com'],
        ]]);
        $this->domain('example.com');
    }

    public function test_page_lists_only_domains_the_panel_does_not_have_yet(): void
    {
        $this->actingAs($this->admin())->get('/admin/domains/import')
            ->assertOk()->assertSee('shop.example.com')->assertSee('blog.example.com')
            ->assertDontSee('value="example.com"', false);
    }

    public function test_import_adds_chosen_domains_audits_and_queues_a_sync_for_each(): void
    {
        Queue::fake();

        $this->actingAs($this->admin())->post('/admin/domains/import', ['domains' => ['shop.example.com']])
            ->assertRedirect('/admin/domains');

        $shop = Domain::where('name', 'shop.example.com')->first();
        $this->assertNotNull($shop);
        $this->assertSame(1024, $shop->default_quota_mb);
        $this->assertNull(Domain::where('name', 'blog.example.com')->first(), 'Unticked domains are not imported.');
        $this->assertDatabaseHas('audit_log', ['action' => 'domain.import', 'target' => 'shop.example.com']);
        Queue::assertPushed(SyncDomainJob::class, fn ($job) => $job->domainId === $shop->id);
    }

    public function test_names_cpanel_does_not_report_are_ignored(): void
    {
        Queue::fake();

        $this->actingAs($this->admin())->post('/admin/domains/import', ['domains' => ['evil.test', 'example.com']])
            ->assertRedirect();

        $this->assertSame(1, Domain::count());
        Queue::assertNothingPushed();
    }

    public function test_only_super_admins_can_import(): void
    {
        $manager = $this->manager(Domain::first());

        $this->actingAs($manager)->get('/admin/domains/import')->assertForbidden();
        $this->actingAs($manager)->post('/admin/domains/import', ['domains' => ['shop.example.com']])->assertForbidden();
        $this->assertSame(1, Domain::count());
    }

    public function test_console_command_dry_run_lists_without_importing(): void
    {
        $this->artisan('panel:import-domains --dry-run')->expectsOutputToContain('blog.example.com')->assertSuccessful();

        $this->assertSame(1, Domain::count());
    }
}
