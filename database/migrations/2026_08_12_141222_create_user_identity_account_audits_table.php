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
        Schema::create('user_identity_account_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();

            // Nulled rather than cascaded: the audit trail has to outlive both the link
            // it describes (once unlinked) and the user it was for (once deleted).
            $table->foreignId('user_identity_account_id')->nullable()->constrained('user_identity_accounts')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('provider');
            $table->string('action');

            // Contextual notes only (e.g. "forced") — never claims, tokens, or secrets.
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
        Schema::dropIfExists('user_identity_account_audits');
    }
};
