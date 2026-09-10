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
        Schema::table('ideas', function (Blueprint $table) {
            $table->string('description_format')->default('plain')->after('description');
        });

        Schema::table('idea_official_responses', function (Blueprint $table) {
            $table->string('body_format')->default('plain')->after('body');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ideas', function (Blueprint $table) {
            $table->dropColumn('description_format');
        });

        Schema::table('idea_official_responses', function (Blueprint $table) {
            $table->dropColumn('body_format');
        });
    }
};
