<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ideas', function (Blueprint $table) {
            $table->foreignId('entered_by_user_id')->nullable()->after('submitted_by_user_id')->constrained('users')->nullOnDelete();
        });

        // Backfill existing ideas: until now, submitted_by_user_id was always
        // the authenticated user who entered the idea, so it is also the
        // correct "entered by" value for every pre-existing row.
        DB::table('ideas')->update(['entered_by_user_id' => DB::raw('submitted_by_user_id')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ideas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('entered_by_user_id');
        });
    }
};
