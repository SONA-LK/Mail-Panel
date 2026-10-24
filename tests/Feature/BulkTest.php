<?php

namespace Tests\Feature;

use App\Jobs\ProcessBulkRow;
use App\Models\BulkBatch;
use App\Models\BulkRow;
use App\Models\Mailbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Tests\Support\PanelFixtures;
use Tests\TestCase;

class BulkTest extends TestCase
{
    use PanelFixtures, RefreshDatabase;

    private $d;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPanel();
        $this->fakeCpanel();
        $this->d = $this->domain('example.com', ['default_quota_mb' => 512]);
    }

    private function csv(array $lines, string $header = 'type,address,quota_mb,password,forward_to'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('import.csv', $header."\n".implode("\n", $lines)."\n");
    }

    private function upload($user, array $lines)
    {
        return $this->actingAs($user)->post('/bulk', ['file' => $this->csv($lines)]);
    }

    public function test_fifty_rows_with_three_bad_gives_47_valid_and_3_listed_with_errors(): void
    {
        $lines = [];
        for ($i = 1; $i <= 47; $i++) {
            $lines[] = "mailbox,user{$i}@example.com,100,,";
        }
        $lines[] = 'mailbox,not-an-email,100,,';
        $lines[] = 'mailbox,someone@unmanaged.org,100,,';
        $lines[] = 'mailbox,user1@example.com,100,,'; // duplicate within the file

        $admin = $this->admin();
        $this->upload($admin, $lines)->assertRedirect();

        $batch = BulkBatch::first();
        $this->assertSame('validated', $batch->status);
        $this->assertSame(50, $batch->total_rows);
        $this->assertSame(47, $batch->rows()->where('status', 'valid')->count());
        $invalid = $batch->rows()->where('status', 'invalid')->get();
        $this->assertCount(3, $invalid);
        $this->assertTrue($invalid->every(fn ($r) => $r->error_message !== ''));
        $this->assertSame(0, $this->cpanelCalls('add_pop'), 'The dry-run must not touch cPanel.');
        $this->assertSame(0, Mailbox::count());

        $this->actingAs($admin)->get("/bulk/{$batch->id}")->assertOk()->assertSee('not-an-email');
    }

    public function test_confirm_queues_valid_rows_and_processing_creates_them(): void
    {
        $admin = $this->admin();
        $this->upload($admin, ['mailbox,a@example.com,100,,', 'mailbox,b@example.com,,,', 'mailbox,bad,1,,']);
        $batch = BulkBatch::first();

        $this->actingAs($admin)->post("/bulk/{$batch->id}/confirm")->assertRedirect();

        // QUEUE_CONNECTION=sync in tests, so the jobs have already run.
        $batch->refresh();
        $this->assertSame('done', $batch->status);
        $this->assertSame(2, $batch->done_rows);
        $this->assertSame(2, $this->cpanelCalls('add_pop'));
        $this->assertDatabaseHas('mailboxes_cache', ['address' => 'b@example.com', 'quota_mb' => 512]); // domain default
        $this->assertDatabaseHas('audit_log', ['action' => 'bulk.confirm']);
        $this->assertDatabaseHas('audit_log', ['action' => 'bulk.finish']);
        $this->assertDatabaseHas('audit_log', ['action' => 'mailbox.create', 'target' => 'a@example.com']);
    }

    public function test_rows_are_queued_not_run_inline_when_the_queue_is_async(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $this->upload($admin, ['mailbox,a@example.com,100,,', 'mailbox,b@example.com,100,,']);
        $batch = BulkBatch::first();

        $this->actingAs($admin)->post("/bulk/{$batch->id}/confirm");

        Queue::assertPushed(ProcessBulkRow::class, 2);
        $this->assertSame('queued', $batch->fresh()->status);
        $this->assertSame(2, $batch->rows()->where('status', 'queued')->count());
        $this->assertSame(0, $this->cpanelCalls('add_pop'), 'Closing the browser is safe: cron runs the queue.');
    }

    public function test_cannot_confirm_twice(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $this->upload($admin, ['mailbox,a@example.com,100,,']);
        $batch = BulkBatch::first();

        $this->actingAs($admin)->post("/bulk/{$batch->id}/confirm");
        $this->actingAs($admin)->post("/bulk/{$batch->id}/confirm")->assertSessionHasErrors('batch');

        Queue::assertPushed(ProcessBulkRow::class, 1);
    }

    public function test_generated_passwords_appear_in_the_result_once_then_are_wiped(): void
    {
        $admin = $this->admin();
        $this->upload($admin, ['mailbox,gen@example.com,100,,', 'mailbox,own@example.com,100,MyOwnPassw0rd!,']);
        $batch = BulkBatch::first();
        $this->actingAs($admin)->post("/bulk/{$batch->id}/confirm");

        $secretRow = $batch->rows()->get()->first(fn ($r) => $r->result_secret);
        $this->assertNotNull($secretRow);
        $generated = $secretRow->result_secret;
        $this->assertSame('gen@example.com', $secretRow->payload['address']);

        // Passwords are dropped from the payload once processed.
        $this->assertTrue($batch->rows()->get()->every(fn ($r) => ($r->payload['password'] ?? '') === ''));
        $this->assertStringNotContainsString('MyOwnPassw0rd', json_encode(\DB::table('bulk_rows')->get()));
        $this->assertStringNotContainsString($generated, json_encode(\DB::table('bulk_rows')->get()), 'Stored encrypted, never in plain text.');

        $first = $this->actingAs($admin)->get("/bulk/{$batch->id}/result");
        $first->assertOk();
        $this->assertStringContainsString($generated, $first->getContent());
        $this->assertStringNotContainsString('MyOwnPassw0rd', $first->getContent());

        $second = $this->actingAs($admin)->get("/bulk/{$batch->id}/result");
        $this->assertStringNotContainsString($generated, $second->getContent());
        $this->assertSame(0, $batch->rows()->whereNotNull('result_secret')->count());
    }

    public function test_failed_row_is_marked_failed_with_the_cpanel_error_after_retries(): void
    {
        $admin = $this->admin();
        $this->upload($admin, ['mailbox,a@example.com,100,,', 'mailbox,b@example.com,100,,']);
        $batch = BulkBatch::first();

        $this->fakeCpanel([], 'add_pop');
        $rows = $batch->rows()->pluck('id');
        $batch->rows()->update(['status' => 'queued']);
        $batch->update(['status' => 'queued']);

        foreach ($rows as $id) {
            $job = new ProcessBulkRow($id);
            try {
                $job->handle(app(\App\Services\MailboxService::class), app(\App\Services\ForwarderService::class), app(\App\Services\BulkImportService::class));
            } catch (\Throwable $e) {
                $job->failed($e); // what the queue does after the last of 3 tries
            }
        }

        $batch->refresh();
        $this->assertSame('partial', $batch->status);
        $this->assertSame(2, $batch->failed_rows);
        $this->assertStringContainsString('already exists', $batch->rows()->first()->error_message);
        $this->assertSame(3, (new ProcessBulkRow(1))->tries);
        $this->assertSame([60, 180], (new ProcessBulkRow(1))->backoff());
    }

    public function test_forwarder_rows_with_loops_and_policy_are_flagged_and_external_needs_confirmation(): void
    {
        $this->domain('closed.com', ['forwarding_policy' => 'internal']);
        $admin = $this->admin();
        $this->upload($admin, [
            'forwarder,info@example.com,,,a@example.com;b@example.com',
            'forwarder,a@example.com,,,info@example.com',        // loop with the row above
            'forwarder,sales@example.com,,,x@gmail.com',          // valid but external
            'forwarder,info@closed.com,,,x@gmail.com',            // external blocked by policy
            'forwarder,empty@example.com,,,',                      // no destination
        ]);
        $batch = BulkBatch::first();
        $byAddr = $batch->rows()->get()->keyBy(fn ($r) => $r->payload['address']);

        $this->assertSame('valid', $byAddr['info@example.com']->status);
        $this->assertSame('invalid', $byAddr['a@example.com']->status);
        $this->assertStringContainsString('loop', strtolower($byAddr['a@example.com']->error_message));
        $this->assertSame('valid', $byAddr['sales@example.com']->status);
        $this->assertStringContainsString('External', $byAddr['sales@example.com']->warnings);
        $this->assertSame('invalid', $byAddr['info@closed.com']->status);
        $this->assertSame('invalid', $byAddr['empty@example.com']->status);

        Queue::fake();
        $this->actingAs($admin)->post("/bulk/{$batch->id}/confirm")->assertSessionHasErrors('confirm_external');
        Queue::assertNothingPushed();
        $this->actingAs($admin)->post("/bulk/{$batch->id}/confirm", ['confirm_external' => 1])->assertSessionHasNoErrors();
        Queue::assertPushed(ProcessBulkRow::class, 2);
    }

    public function test_forwarder_row_creates_one_forwarder_per_destination_and_is_idempotent_on_retry(): void
    {
        $admin = $this->admin();
        $this->upload($admin, ['forwarder,info@example.com,,,a@example.com;b@example.com']);
        $batch = BulkBatch::first();
        $this->actingAs($admin)->post("/bulk/{$batch->id}/confirm");

        $this->assertSame(2, $this->cpanelCalls('add_forwarder'));

        // A retry of the same row must not re-add what already exists.
        $row = $batch->rows()->first();
        $row->update(['status' => 'queued']);
        (new ProcessBulkRow($row->id))->handle(app(\App\Services\MailboxService::class), app(\App\Services\ForwarderService::class), app(\App\Services\BulkImportService::class));
        $this->assertSame(2, $this->cpanelCalls('add_forwarder'));
    }

    public function test_manager_cannot_import_for_another_domain(): void
    {
        $other = $this->domain('other.com');
        $mgr = $this->manager($this->d);
        $this->upload($mgr, ['mailbox,a@example.com,100,,', 'mailbox,b@other.com,100,,']);

        $rows = BulkBatch::first()->rows()->get()->keyBy(fn ($r) => $r->payload['address']);
        $this->assertSame('valid', $rows['a@example.com']->status);
        $this->assertSame('invalid', $rows['b@other.com']->status);
        $this->assertStringContainsString('permission', $rows['b@other.com']->error_message);
    }

    public function test_permission_is_rechecked_when_the_row_runs(): void
    {
        Queue::fake();
        $mgr = $this->manager($this->d);
        $this->upload($mgr, ['mailbox,a@example.com,100,,']);
        $batch = BulkBatch::first();
        $this->actingAs($mgr)->post("/bulk/{$batch->id}/confirm");

        $mgr->domains()->detach(); // demoted before cron gets to it

        $this->expectException(\RuntimeException::class);
        (new ProcessBulkRow($batch->rows()->first()->id))->handle(app(\App\Services\MailboxService::class), app(\App\Services\ForwarderService::class), app(\App\Services\BulkImportService::class));
    }

    public function test_batches_are_private_to_their_owner(): void
    {
        $a = $this->manager($this->d);
        $b = $this->manager($this->d);
        $this->upload($a, ['mailbox,a@example.com,100,,']);
        $batch = BulkBatch::first();

        $this->actingAs($b)->get("/bulk/{$batch->id}")->assertForbidden();
        $this->actingAs($b)->post("/bulk/{$batch->id}/confirm")->assertForbidden();
        $this->actingAs($this->admin())->get("/bulk/{$batch->id}")->assertOk();
    }

    public function test_bad_files_are_rejected(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/bulk', ['file' => $this->csv(['mailbox,a@example.com,1,,'], 'wrong,header')])->assertSessionHasErrors('file');
        $this->actingAs($admin)->post('/bulk', ['file' => $this->csv([])])->assertSessionHasErrors('file');

        config(['cpanel.csv_max_rows' => 2]);
        $this->actingAs($admin)->post('/bulk', ['file' => $this->csv(['mailbox,a@example.com,1,,', 'mailbox,b@example.com,1,,', 'mailbox,c@example.com,1,,'])])->assertSessionHasErrors('file');
        $this->assertSame(0, BulkBatch::count());
    }

    public function test_cancel_discards_rows_and_their_passwords(): void
    {
        $admin = $this->admin();
        $this->upload($admin, ['mailbox,a@example.com,100,Sup3rSecretPw,']);
        $batch = BulkBatch::first();

        $this->actingAs($admin)->post("/bulk/{$batch->id}/cancel")->assertRedirect('/bulk');

        $this->assertSame(0, BulkRow::count());
        $this->assertSame('cancelled', $batch->fresh()->status);
    }

    public function test_invalid_rows_do_not_keep_supplied_passwords(): void
    {
        $this->upload($this->admin(), ['mailbox,bad-address,100,Sup3rSecretPw,']);

        $this->assertStringNotContainsString('Sup3rSecretPw', json_encode(BulkRow::first()->payload));
    }

    public function test_template_download(): void
    {
        $this->actingAs($this->admin())->get('/bulk/template')->assertOk()->assertSee('type,address,quota_mb,password,forward_to');
    }
}
