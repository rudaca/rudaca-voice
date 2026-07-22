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
        Schema::create('view_as_session_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('view_as_session_id')->constrained()->cascadeOnDelete();
            $table->string('method');
            $table->string('path');
            $table->string('route_name')->nullable();
            $table->timestamp('performed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('view_as_session_actions');
    }
};
