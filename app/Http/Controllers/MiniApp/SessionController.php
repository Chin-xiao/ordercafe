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
        OrderSession::query()
            ->where('status', 'open')
            ->where(function ($query) use ($now) {
                $query->whereNull('expires_at')->orWhere('expires_at', '<=', $now);
            })
            ->update(['status' => 'expired']);

        $startParam = $request->attributes->get('telegram_web_app_start_param');
        if ($startParam !== null) {
            $session = is_string($startParam) && ctype_digit($startParam)
                ? OrderSession::query()->with('creator')->find((int) $startParam)
                : null;
        } else {
            $session = OrderSession::query()
                ->where('status', 'open')
                ->where('expires_at', '>', $now)
                ->with('creator')
                ->latest('started_at')
                ->first();
        }

        if (!$session) {
            return response()->json(['data' => null]);
        }

        $data = $session->toArray();
        $data['can_order'] = $session->status === 'open'
            && $session->expires_at
            && $session->expires_at->isFuture();

        return response()->json(['data' => $data]);
    }
}
