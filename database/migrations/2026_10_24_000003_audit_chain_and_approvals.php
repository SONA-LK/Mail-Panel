<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Each audit row stores a keyed hash of itself and of the row before it, so an edit, a removed row
        // or an inserted row breaks the chain and `panel:audit-verify` says where. Older rows stay unchained.
        Schema::table('audit_log', function (Blueprint $table) {
            $table->string('prev_hash', 64)->nullable();
            $table->string('hash', 64)->nullable()->index();
        });

        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->string('action');                                    // domain.remove | user.delete
            $table->string('target');                                    // what the person sees: a domain name or an email
            $table->json('payload');                                     // ids needed to carry the action out
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('requested_by_email');
            $table->string('status')->default('pending');                // pending | approved | rejected | expired
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['status', 'action']);
        });

        $this->installTriggers();
    }

    public function down(): void
    {
        $this->dropTriggers();
        Schema::dropIfExists('approval_requests');
        Schema::table('audit_log', fn (Blueprint $table) => $table->dropColumn(['prev_hash', 'hash']));
    }

    /**
     * The database itself refuses to change or delete audit rows. Only the user_id / domain_id links may be
     * cleared (that is what removing a user or domain does). Some shared hosts do not give the database user
     * the TRIGGER privilege; then this is skipped and `panel:doctor` reports it, while the hash chain still
     * detects tampering.
     */
    private function installTriggers(): void
    {
        $driver = DB::connection()->getDriverName();

        try {
            if ($driver === 'sqlite') {
                DB::unprepared("CREATE TRIGGER audit_log_no_delete BEFORE DELETE ON audit_log BEGIN SELECT RAISE(ABORT, 'audit_log is append-only'); END");
                DB::unprepared("CREATE TRIGGER audit_log_no_update BEFORE UPDATE OF user_email, action, target, details, result, ip, created_at, prev_hash, hash ON audit_log BEGIN SELECT RAISE(ABORT, 'audit_log is append-only'); END");
            } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
                DB::unprepared("CREATE TRIGGER audit_log_no_delete BEFORE DELETE ON audit_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log is append-only'");
                DB::unprepared("CREATE TRIGGER audit_log_no_update BEFORE UPDATE ON audit_log FOR EACH ROW BEGIN IF NOT (NEW.user_email <=> OLD.user_email AND NEW.action <=> OLD.action AND NEW.target <=> OLD.target AND NEW.details <=> OLD.details AND NEW.result <=> OLD.result AND NEW.ip <=> OLD.ip AND NEW.created_at <=> OLD.created_at AND NEW.prev_hash <=> OLD.prev_hash AND NEW.hash <=> OLD.hash) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log is append-only'; END IF; END");
            }
        } catch (Throwable $e) {
            Log::warning('Audit log triggers were not installed: '.$e->getMessage());
        }
    }

    private function dropTriggers(): void
    {
        try {
            DB::unprepared('DROP TRIGGER IF EXISTS audit_log_no_delete');
            DB::unprepared('DROP TRIGGER IF EXISTS audit_log_no_update');
        } catch (Throwable) {
        }
    }
};
