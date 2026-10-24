<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('purpose')->nullable();
            $table->unsignedInteger('default_quota_mb')->default(1024);
            $table->string('naming_pattern')->default('free');          // free | first.last | department
            $table->string('catchall_policy')->default('discard');      // forward | discard | reject
            $table->string('catchall_target')->nullable();
            $table->string('forwarding_policy')->default('any');        // any | internal | list
            $table->text('forwarding_allowlist')->nullable();
            $table->boolean('welcome_enabled')->default(false);
            $table->text('welcome_template')->nullable();
            $table->unsignedInteger('suspend_days')->default(30);
            $table->boolean('require_suspend_first')->default(false);
            $table->boolean('auto_delete_suspended')->default(false);
            $table->timestamps();
        });

        Schema::create('user_domain', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->primary(['user_id', 'domain_id']);
        });

        Schema::create('mailboxes_cache', function (Blueprint $table) {
            $table->id();
            $table->string('address')->unique();
            $table->string('local_part');
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quota_mb')->nullable();            // null = unlimited
            $table->decimal('used_mb', 12, 2)->default(0);
            $table->boolean('suspended_login')->default(false);
            $table->boolean('suspended_incoming')->default(false);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('forwarders_cache', function (Blueprint $table) {
            $table->id();
            $table->string('source');
            $table->string('destination');
            $table->string('type');                                     // address | domain | catchall
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->unique(['source', 'destination', 'type']);
            $table->index('destination');
        });

        Schema::create('autoresponders_cache', function (Blueprint $table) {
            $table->id();
            $table->string('address')->unique();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->string('subject')->nullable();
            $table->timestamp('start_at')->nullable();
            $table->timestamp('stop_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('filters_cache', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('scope');                                    // account | mailbox
            $table->string('address')->nullable();
            $table->string('name');
            $table->text('rule_summary')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('bulk_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('type')->default('csv');
            $table->string('original_name')->nullable();
            $table->string('status')->default('parsed');                // parsed | validated | queued | done | partial | cancelled
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('done_rows')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);
            $table->timestamps();
        });

        Schema::create('bulk_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('bulk_batches')->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->text('payload')->nullable();                        // encrypted JSON, password dropped once processed
            $table->string('status')->default('pending');               // pending | valid | invalid | queued | done | failed
            $table->text('error_message')->nullable();
            $table->text('warnings')->nullable();
            $table->text('result_secret')->nullable();                  // encrypted generated password, wiped after download
            $table->timestamps();
            $table->index(['batch_id', 'status']);
        });

        Schema::create('pending_deletions', function (Blueprint $table) {
            $table->id();
            $table->string('address')->unique();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->timestamp('suspended_at');
            $table->timestamp('delete_after');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('audit_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_email')->nullable();
            $table->string('action')->index();
            $table->string('target')->nullable();
            $table->foreignId('domain_id')->nullable()->constrained()->nullOnDelete();
            $table->json('details')->nullable();                        // never contains passwords
            $table->string('result')->default('success');               // success | failure | denied
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->nullable()->constrained()->nullOnDelete();
            $table->string('trigger')->default('schedule');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('added')->default(0);
            $table->unsignedInteger('changed')->default(0);
            $table->unsignedInteger('removed')->default(0);
            $table->text('errors')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['sync_runs', 'audit_log', 'pending_deletions', 'bulk_rows', 'bulk_batches', 'filters_cache',
                  'autoresponders_cache', 'forwarders_cache', 'mailboxes_cache', 'user_domain', 'domains'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
