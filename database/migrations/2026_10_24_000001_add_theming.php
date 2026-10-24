<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Each person's own appearance choices (mode, density, optional palette).
        Schema::table('users', function (Blueprint $table) {
            $table->json('theme_prefs')->nullable()->after('active');
        });

        // Company branding is never edited in place: every save is a new row, the newest is live, and any
        // older one can be restored. The logo is a re-encoded PNG kept outside the web root.
        Schema::create('brand_versions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->nullable();
            $table->string('primary', 7);
            $table->string('header', 7);
            $table->string('font', 20)->default('system');
            $table->boolean('allow_palettes')->default(false);
            $table->string('logo_path')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_versions');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('theme_prefs'));
    }
};
