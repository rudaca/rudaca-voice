<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The deprecated status value being removed from the review workflow.
     */
    private const OLD_STATUS = 'under_review';

    /**
     * The review queue's "Under Review" status is removed in favour of a
     * single-step Approve action (new -> approved). Ideas still sitting at
     * "under_review" have not been through a deliberate final decision under
     * the old two-step flow, so they are sent back to "new" to await a fresh
     * Approve/Decline decision.
     *
     * The idea_status_history table is an append-only audit log (see
     * IdeaStatusHistory::UPDATED_AT) and is intentionally left untouched:
     * existing entries continue to show "under_review" as an accurate record
     * of what happened at the time, and are not rewritten by this migration.
     */
    public function up(): void
    {
        DB::transaction(function () {
            DB::table('ideas')
                ->where('status', self::OLD_STATUS)
                ->update(['status' => 'new']);
        });
    }

    /**
     * Not reversible: once merged into "new", ideas that were previously
     * "under_review" can no longer be distinguished from ideas that were
     * already "new" before this migration ran.
     */
    public function down(): void
    {
        //
    }
};
