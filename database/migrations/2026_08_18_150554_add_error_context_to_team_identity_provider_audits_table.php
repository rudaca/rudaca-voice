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
        Schema::table('team_identity_provider_audits', function (Blueprint $table) {
            // Unlike changed_fields (names only), this holds short diagnostic
            // detail for a failure — e.g. the short error code and
            // description Microsoft itself returned — so an admin can see
            // *why* a login or connection test failed straight from this
            // table instead of needing server log access. Still never a
            // secret, token, or full request/response body.
            $table->json('error_context')->nullable()->after('changed_fields');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('team_identity_provider_audits', function (Blueprint $table) {
            $table->dropColumn('error_context');
        });
    }
};
