<?php

namespace App\Http\Controllers\MiniApp;

use App\Http\Controllers\Controller;
use App\Models\OrderSession;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class SessionController extends Controller
{
    public function current(Request $request)
    {
        $now = Carbon::now('UTC');
        $startParam = $request->attributes->get('telegram_web_app_start_param');
        if ($startParam !== null) {
            $sessionId = is_string($startParam)
                ? filter_var($startParam, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
                : false;
            $session = $sessionId !== false
                ? OrderSession::query()
                    ->whereKey($sessionId)
                    ->with('creator')
                    ->first()
                : null;

            if ($session
                && $session->status === 'open'
                && (! $session->expires_at || $session->expires_at->lessThanOrEqualTo($now))) {
                $session->setAttribute('status', 'expired');
            }
        } else {
            $session = OrderSession::query()
                ->where('status', 'open')
                ->where('expires_at', '>', $now)
                ->with('creator')
                ->latest('started_at')
                ->first();
        }

        if (! $session) {
            return response()->json(['data' => null]);
        }

        $data = $session->toArray();
        $data['ordering_allowed'] = $session->status === 'open'
            && $session->expires_at
            && $session->expires_at->isFuture();
        $data['can_order'] = $data['ordering_allowed'];

        return response()->json(['data' => $data]);
    }
}
