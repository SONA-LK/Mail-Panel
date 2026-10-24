<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('totp_secret')->nullable();                     // encrypted; only set once the first code was confirmed
            $table->timestamp('totp_confirmed_at')->nullable();
            $table->unsignedBigInteger('totp_last_step')->nullable();    // a code can be used once: replay protection
        });

        Schema::create('passkeys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('credential_id')->unique();                   // base64url
            $table->longText('record');                                  // serialized credential record: public key, counter
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('recovery_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('code_hash', 64);                             // HMAC, never the code itself
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'code_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recovery_codes');
        Schema::dropIfExists('passkeys');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['totp_secret', 'totp_confirmed_at', 'totp_last_step']));
    }
};
