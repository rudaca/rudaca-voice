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
        Schema::table('idea_comments', function (Blueprint $table) {
            $table->timestamp('hidden_at')->nullable()->after('is_internal');
            $table->foreignId('hidden_by_user_id')->nullable()->after('hidden_at')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('idea_comments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('hidden_by_user_id');
            $table->dropColumn('hidden_at');
        });
    }
};
