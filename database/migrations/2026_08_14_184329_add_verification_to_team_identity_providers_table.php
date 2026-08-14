<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('team_identity_providers', function (Blueprint $table) {
            $table->timestamp('verified_at')->nullable()->after('configured_at');
            $table->foreignId('verified_by')->nullable()->after('verified_at')->constrained('users')->nullOnDelete();

            // Populated only when the most recent connection test failed; both are
            // cleared on the next success and whenever tenant/client/secret change,
            // so a stale failure can never outlive the configuration it described.
            $table->timestamp('last_test_failed_at')->nullable()->after('verified_by');
            $table->string('last_test_failure_message')->nullable()->after('last_test_failed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('team_identity_providers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('verified_by');
            $table->dropColumn(['verified_at', 'last_test_failed_at', 'last_test_failure_message']);
        });
    }
};
