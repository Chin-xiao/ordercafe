<?php

namespace App\Http\Controllers\MiniApp;

use App\Http\Controllers\Controller;
use App\Models\OrderSession;
use Illuminate\Http\Request;

class SessionController extends Controller
{
    public function current()
    {
        $session = OrderSession::query()
            ->where('status', 'open')
            ->with('creator')
            ->latest('started_at')
            ->first();

        return response()->json(['data' => $session]);
    }
}
