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
        Schema::create('user_identity_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();

            // Kept as a plain string (matches team_identity_providers.provider) so a new
            // provider only needs a new App\Enums\IdentityProvider case, never a migration.
            $table->string('provider');

            $table->string('provider_tenant_id');
            $table->string('provider_subject_id');

            // Snapshots of the claims at the moment the link was created — never updated
            // afterwards, which is exactly what makes a later email change on the
            // provider side a non-event for an already-linked identity.
            $table->string('email_at_link_time');
            $table->string('display_name')->nullable();

            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();

            // Global, not per-organization: this is what makes it structurally
            // impossible for the same external identity to be linked to two users,
            // or reused silently across organizations, rather than merely validated.
            $table->unique(['provider', 'provider_tenant_id', 'provider_subject_id'], 'user_identity_accounts_identity_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_identity_accounts');
    }
};
