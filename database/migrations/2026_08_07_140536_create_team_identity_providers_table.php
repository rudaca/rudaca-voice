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
        Schema::create('team_identity_providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();

            // Kept as a plain string rather than a DB enum so a new provider only
            // needs a new App\Enums\IdentityProvider case, never a migration.
            $table->string('provider');

            $table->string('tenant_id')->nullable();
            $table->string('client_id')->nullable();
            $table->text('client_secret_encrypted')->nullable();

            $table->boolean('enabled')->default(false);
            $table->boolean('enforce_sso')->default(false);
            $table->boolean('auto_provision_users')->default(false);

            // Roles in this application are an enum (App\Enums\TeamRole) held on the
            // team_members pivot, not rows in a roles table, so the default role for
            // provisioned users is stored as that enum's value.
            $table->string('default_role')->nullable();

            // Nullable rather than `default('[]')`: MySQL rejects a literal DEFAULT on a
            // JSON column, so the empty-array default lives on the model instead.
            $table->json('allowed_domains')->nullable();

            $table->foreignId('configured_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('configured_at')->nullable();
            $table->timestamps();

            // One configuration per provider per organization, enforced by the database
            // and not only by validation.
            $table->unique(['team_id', 'provider']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('team_identity_providers');
    }
};
