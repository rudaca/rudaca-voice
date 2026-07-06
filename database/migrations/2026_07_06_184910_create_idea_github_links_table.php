<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 / Planned table.
 *
 * Schema only — no UI or GitHub sync logic is wired up yet.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('idea_github_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('idea_id')->constrained('ideas')->cascadeOnDelete();
            $table->string('github_owner');
            $table->string('github_repo');
            $table->integer('github_issue_number');
            $table->string('github_issue_url');
            $table->string('github_issue_state'); // open, closed
            $table->string('github_issue_status'); // backlog, ready, in_progress, done
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

                $table->unique(
                    ['github_owner', 'github_repo', 'github_issue_number'],
                    'github_issue_unique'
                );        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('idea_github_links');
    }
};
