<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 / Planned table.
 *
 * Schema only — per-board role access is not enforced in the UI yet.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('idea_board_role_access', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_id')->constrained('idea_boards')->cascadeOnDelete();
            $table->string('role'); // owner, admin, manager, employee, viewer
            $table->timestamps();

            $table->unique(['board_id', 'role']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('idea_board_role_access');
    }
};
