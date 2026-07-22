<?php

namespace App\Http\Middleware;

use App\Enums\ViewAsSessionEndReason;
use App\Models\ViewAsSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrackViewAsSession
{
    /**
     * Track activity for the active View As session, ending it if it has timed out.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($session = ViewAsSession::current()) {
            if ($session->isExpired()) {
                $session->end(ViewAsSessionEndReason::Timeout);

                return redirect()->route('home')->with('status', __('Your View As session ended due to inactivity.'));
            }

            $session->recordActivity($request);
        }

        return $next($request);
    }
}
