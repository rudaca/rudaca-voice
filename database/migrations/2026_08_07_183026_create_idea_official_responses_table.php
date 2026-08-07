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
        Schema::create('idea_official_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('idea_id')->constrained('ideas')->cascadeOnDelete();
            $table->foreignId('responded_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            // Set once when first published; never touched by later edits. `updated_at`
            // (below) tracks the most recent edit instead.
            $table->timestamp('published_at');
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('idea_official_responses');
    }
};
