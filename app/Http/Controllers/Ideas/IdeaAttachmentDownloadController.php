<?php

namespace App\Http\Controllers\Ideas;

use App\Http\Controllers\Controller;
use App\Models\Idea;
use App\Models\IdeaAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IdeaAttachmentDownloadController extends Controller
{
    /**
     * Stream an idea attachment to the current team member.
     *
     * The idea is resolved manually with the exact same team + visibility
     * scoping used by the idea show page, rather than relying on implicit
     * route-model binding on the idea's slug (which is only unique per team,
     * not globally) — this is what keeps a private idea's attachments
     * unreachable to anyone who couldn't already see the idea itself. The
     * attachment is bound implicitly (its id is globally unique), and the
     * policy check below is what stops a valid attachment id for a different
     * idea/team from being substituted into an otherwise-valid URL.
     *
     * Since the underlying disk is never web-exposed directly, this
     * controller is the only way to ever reach the file.
     */
    public function __invoke(Request $request, string $current_team, string $idea, IdeaAttachment $attachment): StreamedResponse
    {
        $user = Auth::user();
        $team = $user->currentTeam;

        $ideaModel = Idea::query()
            ->where('team_id', $team->id)
            ->where('slug', $idea)
            ->visibleTo($user->teamRole($team), $user->id)
            ->firstOrFail();

        abort_unless(Gate::allows('view', [$attachment, $ideaModel]), 404);

        abort_unless(Storage::disk($attachment->disk)->exists($attachment->path), 404);

        return Storage::disk($attachment->disk)->download($attachment->path, $attachment->original_filename);
    }
}
