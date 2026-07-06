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
        Schema::create('ideas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('board_group_id')->nullable()->constrained('idea_board_groups')->nullOnDelete();
            $table->foreignId('board_id')->constrained('idea_boards')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('idea_categories')->nullOnDelete();
            $table->foreignId('submitted_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->string('slug');
            $table->text('description');
            $table->string('status')->default('new'); // new, under_review, planned, in_progress, released, not_doing, duplicate
            $table->string('priority')->default('medium'); // low, medium, high
            $table->string('impact')->default('medium'); // low, medium, high
            $table->string('effort')->default('medium'); // small, medium, large
            $table->boolean('is_anonymous')->default(false);
            $table->boolean('is_private')->default(false);
            $table->foreignId('duplicate_of_idea_id')->nullable()->constrained('ideas')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['team_id', 'slug']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ideas');
    }
};
