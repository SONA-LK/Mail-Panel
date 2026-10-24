<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit;
use App\Services\AuditChain;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\PanelFixtures;
use Tests\TestCase;

class AuditIntegrityTest extends TestCase
{
    use PanelFixtures, RefreshDatabase;

    private function writeRows(int $n = 4): void
    {
        $u = User::factory()->create();
        for ($i = 1; $i <= $n; $i++) {
            Audit::record($u, "test.event{$i}", "target {$i}", null, ['b' => $i, 'a' => ['y' => 1, 'x' => 'ü']]);
        }
    }

    private function dropTriggers(): void
    {
        DB::unprepared('DROP TRIGGER audit_log_no_update');
        DB::unprepared('DROP TRIGGER audit_log_no_delete');
    }

    public function test_every_row_is_chained_to_the_one_before(): void
    {
        $this->writeRows(3);
        $rows = AuditLog::orderBy('id')->get();

        $this->assertSame(AuditChain::GENESIS, $rows[0]->prev_hash);
        $this->assertSame($rows[0]->hash, $rows[1]->prev_hash);
        $this->assertSame($rows[1]->hash, $rows[2]->prev_hash);
        $this->assertSame(64, strlen($rows[2]->hash));
        $this->assertNotSame($rows[0]->hash, $rows[1]->hash);

        $r = (new AuditChain)->verify();
        $this->assertTrue($r['ok']);
        $this->assertSame(3, $r['checked']);
    }

    public function test_the_hash_is_keyed_so_it_cannot_be_recomputed_without_the_app_key(): void
    {
        $this->writeRows(1);
        $row = AuditLog::first();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

        $this->assertFalse((new AuditChain)->verify()['ok']);
    }

    public function test_the_database_refuses_to_edit_or_delete_audit_rows(): void
    {
        $this->writeRows(2);
        $id = AuditLog::first()->id;

        foreach ([
            fn () => DB::table('audit_log')->where('id', $id)->update(['action' => 'edited']),
            fn () => DB::table('audit_log')->where('id', $id)->update(['hash' => str_repeat('a', 64)]),
            fn () => DB::table('audit_log')->where('id', $id)->delete(),
            fn () => DB::table('audit_log')->delete(),
        ] as $attack) {
            try {
                $attack();
                $this->fail('The database allowed the change.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }
        }
        $this->assertTrue((new AuditChain)->verify()['ok']);
    }

    public function test_removing_a_user_still_works_and_keeps_the_chain_valid(): void
    {
        $this->writeRows(2);
        $user = User::first();

        $user->delete();

        $this->assertSame(0, AuditLog::whereNotNull('user_id')->count(), 'links are cleared');
        $this->assertSame(2, AuditLog::count(), 'rows are kept');
        $this->assertTrue((new AuditChain)->verify()['ok']);
    }

    public function test_an_edited_row_is_found(): void
    {
        $this->writeRows(4);
        $this->dropTriggers();
        $second = AuditLog::orderBy('id')->skip(1)->first();
        DB::table('audit_log')->where('id', $second->id)->update(['target' => 'something else']);

        $r = (new AuditChain)->verify();

        $this->assertFalse($r['ok']);
        $this->assertSame($second->id, $r['broken_at']);
        $this->assertStringContainsString('changed', $r['reason']);
    }

    public function test_a_removed_row_is_found(): void
    {
        $this->writeRows(4);
        $this->dropTriggers();
        $rows = AuditLog::orderBy('id')->get();
        DB::table('audit_log')->where('id', $rows[1]->id)->delete();

        $r = (new AuditChain)->verify();

        $this->assertFalse($r['ok']);
        $this->assertSame($rows[2]->id, $r['broken_at']);
        $this->assertStringContainsString('removed', $r['reason']);
    }

    public function test_an_inserted_row_without_a_hash_is_found(): void
    {
        $this->writeRows(2);
        DB::table('audit_log')->insert(['action' => 'forged', 'result' => 'success', 'created_at' => now()]);

        $r = (new AuditChain)->verify();

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('without a hash', $r['reason']);
    }

    public function test_a_forged_row_with_a_guessed_hash_is_found(): void
    {
        $this->writeRows(2);
        $last = AuditLog::orderByDesc('id')->first();
        DB::table('audit_log')->insert(['action' => 'forged', 'result' => 'success', 'created_at' => now(), 'prev_hash' => $last->hash, 'hash' => hash('sha256', 'guess')]);

        $this->assertFalse((new AuditChain)->verify()['ok']);
    }

    public function test_rows_cut_off_the_end_are_noticed_through_the_checkpoint(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'head');
        config(['panel.audit.head_path' => $path]);
        $this->writeRows(3);
        $this->assertTrue((new AuditChain)->verify()['ok']);

        $this->dropTriggers();
        DB::table('audit_log')->where('id', AuditLog::max('id'))->delete();

        $r = (new AuditChain)->verify();
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('cut short', $r['reason']);
        @unlink($path);
    }

    public function test_rows_from_before_chaining_are_left_alone(): void
    {
        DB::table('audit_log')->insert(['action' => 'old.event', 'result' => 'success', 'created_at' => now()->subDay()]);
        $this->writeRows(2);

        $r = (new AuditChain)->verify();

        $this->assertTrue($r['ok']);
        $this->assertSame(1, $r['legacy']);
        $this->assertSame(2, $r['checked']);
    }

    public function test_reanchoring_after_a_break_starts_a_valid_chain_and_is_visible(): void
    {
        $this->writeRows(3);
        $this->dropTriggers();
        DB::table('audit_log')->where('id', AuditLog::orderBy('id')->first()->id)->update(['target' => 'x']);
        $this->assertFalse((new AuditChain)->verify()['ok']);

        $this->artisan('panel:audit-verify', ['--reanchor' => 'restored from backup, checked by hand'])->assertSuccessful();

        $this->assertDatabaseHas('audit_log', ['action' => AuditChain::REANCHOR]);
        $r = (new AuditChain)->verify();
        $this->assertTrue($r['ok']);
        $this->assertSame(3, $r['legacy'], 'the damaged part is no longer vouched for');

        // New rows chain on from the anchor, and damage after it is found again.
        $this->writeRows(2);
        $this->assertTrue((new AuditChain)->verify()['ok']);
        DB::table('audit_log')->where('id', AuditLog::max('id'))->update(['target' => 'tampered']);
        $this->assertFalse((new AuditChain)->verify()['ok']);
    }

    public function test_a_forged_anchor_cannot_hide_earlier_damage(): void
    {
        $this->writeRows(3);
        $this->dropTriggers();
        DB::table('audit_log')->where('id', AuditLog::orderBy('id')->first()->id)->update(['target' => 'x']);
        DB::table('audit_log')->insert(['action' => AuditChain::REANCHOR, 'result' => 'success', 'created_at' => now(), 'prev_hash' => AuditChain::GENESIS, 'hash' => hash('sha256', 'forged')]);

        $r = (new AuditChain)->verify();

        $this->assertFalse($r['ok'], 'an anchor without a valid keyed hash is ignored');
        $this->assertSame(0, $r['legacy']);
    }

    public function test_the_command_passes_on_a_good_log_and_fails_and_alerts_on_a_bad_one(): void
    {
        config(['panel.security.alerts' => true]);
        Mail::fake();
        $admin = $this->admin();
        $this->writeRows(2);

        $this->artisan('panel:audit-verify')->expectsOutputToContain('intact')->assertSuccessful();
        $this->assertTrue(AuditChain::lastResult()['ok']);

        $this->dropTriggers();
        DB::table('audit_log')->where('id', AuditLog::orderBy('id')->first()->id)->update(['target' => 'tampered']);

        $this->artisan('panel:audit-verify')->expectsOutputToContain('FAILED')->assertFailed();
        $this->assertFalse(AuditChain::lastResult()['ok']);
        $this->assertDatabaseHas('audit_log', ['action' => 'audit.chain_broken', 'result' => 'failure']);
        Mail::assertQueued(\App\Mail\SecurityAlert::class, fn ($m) => $m->hasTo($admin->email) && str_contains($m->headline, 'integrity'));
    }

    public function test_the_doctor_reports_the_chain_and_triggers(): void
    {
        $this->writeRows(1);

        $this->artisan('panel:doctor')->expectsOutputToContain('Audit log hash chain')->expectsOutputToContain('triggers present');
    }

    public function test_details_keep_the_same_hash_whatever_order_the_database_returns_keys_in(): void
    {
        $row = ['user_email' => 'a@b.c', 'action' => 'x', 'target' => null, 'result' => 'success', 'ip' => null, 'created_at' => '2026-01-01 00:00:00'];

        $this->assertSame(
            AuditChain::hash('p', $row + ['details' => ['b' => 1, 'a' => ['d' => 2, 'c' => 3]]]),
            AuditChain::hash('p', $row + ['details' => json_encode(['a' => ['c' => 3, 'd' => 2], 'b' => 1])]),
        );
    }

    public function test_secrets_still_never_reach_the_log(): void
    {
        Audit::record(null, 'x', null, null, ['password' => 'hunter2', 'nested' => ['api_token' => 't', 'ok' => 1]]);

        $d = AuditLog::first()->details;
        $this->assertSame(['nested' => ['ok' => 1]], $d);
    }
}
