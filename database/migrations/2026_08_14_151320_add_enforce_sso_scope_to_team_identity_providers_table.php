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
            // Defaults to 'global' so an org that already has enforce_sso on
            // keeps blocking the member's password everywhere, matching the
            // behavior enforce_sso had before this scope existed.
            $table->string('enforce_sso_scope')->default('global')->after('enforce_sso');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('team_identity_providers', function (Blueprint $table) {
            $table->dropColumn('enforce_sso_scope');
        });
    }
};
