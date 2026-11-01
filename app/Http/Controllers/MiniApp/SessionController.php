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

        // Determine the current store ID (via authenticated user or request context)
        $storeId = optional($request->user())->store_id;

        $startParam = $request->attributes->get('telegram_web_app_start_param');
        if ($startParam !== null) {
            $sessionId = is_string($startParam)
                ? filter_var($startParam, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
                : false;

            $session = $sessionId !== false
                ? OrderSession::query()
                    ->whereKey($sessionId)
                    // Optional security: ensure session matches the store if storeId is present
                    ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
                    ->with('creator')
                    ->first()
                : null;

            if ($session
                && $session->status === 'open'
                && (! $session->expires_at || $session->expires_at->lessThanOrEqualTo($now))) {
                $session->setAttribute('status', 'expired');
            }
        } else {
            // 👇 SCOPE THIS FALLBACK BY STORE_ID SO IT DOESN'T LEAK ACROSS STORES
            $session = OrderSession::query()
                ->where('status', 'open')
                ->where('expires_at', '>', $now)
                ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
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
