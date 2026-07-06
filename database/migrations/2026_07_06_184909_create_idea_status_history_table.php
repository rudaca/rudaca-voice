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
        Schema::create('idea_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('idea_id')->constrained('ideas')->cascadeOnDelete();
            $table->foreignId('changed_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('old_status');
            $table->string('new_status');
            $table->text('note')->nullable();
            $table->timestamp('created_at')->nullable(); // append-only log; no updated_at per DBML
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('idea_status_history');
    }
};
