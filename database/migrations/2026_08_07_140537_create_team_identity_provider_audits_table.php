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
        Schema::create('team_identity_provider_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();

            // Nulled rather than cascaded on disconnect: the audit trail has to outlive
            // the configuration row it describes.
            $table->foreignId('identity_provider_id')->nullable()->constrained('team_identity_providers')->nullOnDelete();

            $table->string('provider');
            $table->string('action');

            // Names of the fields that changed, never their values — the client secret
            // must not reach this table in any form.
            $table->json('changed_fields')->nullable();

            $table->foreignId('performed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable(); // append-only log; no updated_at
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('team_identity_provider_audits');
    }
};
