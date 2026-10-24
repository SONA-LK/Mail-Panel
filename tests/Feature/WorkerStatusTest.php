<?php

namespace Tests\Feature;

use App\Services\WorkerStatus;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\PanelFixtures;
use Tests\TestCase;

class WorkerStatusTest extends TestCase
{
    use PanelFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPanel();
        $this->fakeCpanel();
        $this->domain('example.com');
        Cache::flush();
    }

    private function job(int $ageSeconds = 0): void
    {
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => time(), 'created_at' => time() - $ageSeconds]);
    }

    public function test_the_schedule_records_a_heartbeat_every_minute(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => $e->description === 'panel-heartbeat');

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
    }

    public function test_status_is_running_only_while_the_heartbeat_is_fresh(): void
    {
        $w = app(WorkerStatus::class);
        $this->assertFalse($w->running());

        WorkerStatus::beat();
        $this->assertTrue($w->running());

        Cache::forever('scheduler.heartbeat', time() - WorkerStatus::FRESH_SECONDS - 5);
        $this->assertFalse($w->running(), 'A heartbeat older than a few minutes means cron has stopped.');
    }

    public function test_settings_says_when_the_worker_is_not_running_and_shows_the_waiting_jobs(): void
    {
        $this->job(600);
        $this->job(10);

        $this->actingAs($this->admin())->get('/admin/settings')
            ->assertOk()->assertSee('Background worker')->assertSee('Not running')
            ->assertSee('never run')->assertSee('panel:doctor')
            ->assertSeeInOrder(['Waiting jobs:', '2']);

        WorkerStatus::beat();
        $this->actingAs($this->admin())->get('/admin/settings')->assertOk()->assertSee('Running. Last heartbeat')->assertDontSee('Not running');
    }

    public function test_queueing_a_sync_warns_when_cron_is_not_running(): void
    {
        config(['queue.default' => 'database']);   // as in production; phpunit runs jobs inline
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/settings/sync')->assertRedirect()
            ->assertSessionHas('status')->assertSessionHas('warning');
        $this->assertSame(1, DB::table('jobs')->count(), 'The sync is still queued.');

        WorkerStatus::beat();
        $this->actingAs($admin)->post('/admin/settings/sync')->assertSessionHas('status')->assertSessionMissing('warning');
    }

    public function test_doctor_reports_the_command_line_environment(): void
    {
        $this->artisan('panel:doctor')
            ->expectsOutputToContain('PHP binary:')
            ->expectsOutputToContain('Cron heartbeat')
            ->assertFailed();   // no heartbeat yet, and the queue driver is not "database" under test

        WorkerStatus::beat();
        config(['queue.default' => 'database']);
        $this->artisan('panel:doctor')->assertSuccessful();
    }
}
