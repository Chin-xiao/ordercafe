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
        $storeId = optional($request->user())->store_id ?? 1;
        $startParam = $request->attributes->get('telegram_web_app_start_param');

        $session = null;

        if ($startParam !== null) {
            $sessionId = is_string($startParam)
                ? filter_var($startParam, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
                : false;

            if ($sessionId !== false) {
                // Find the exact session requested by the Telegram link
                $session = OrderSession::query()
                    ->whereKey($sessionId)
                    ->where('store_id', $storeId)
                    ->with('creator')
                    ->first();
            }
        } else {
            // Fallback only if no startParam was provided, strictly scoped to this store
            $session = OrderSession::query()
                ->where('status', 'open')
                ->where('expires_at', '>', $now)
                ->where('store_id', $storeId)
                ->with('creator')
                ->latest('started_at')
                ->first();
        }

        if (! $session) {
            return response()->json(['data' => null]);
        }

        // Handle expiration check
        if ($session->status === 'open' && $session->expires_at && $session->expires_at->lessThanOrEqualTo($now)) {
            $session->setAttribute('status', 'expired');
        }

       $data = $session->toArray();
        $data['id'] = (int) $session->id; // Ensure ID is explicitly an integer
        $data['ordering_allowed'] = $session->status === 'open'
            && $session->expires_at
            && $session->expires_at->isFuture();
        $data['can_order'] = $data['ordering_allowed'];

        return response()->json(['data' => $data]);
    }
}
