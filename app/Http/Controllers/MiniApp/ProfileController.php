<?php

namespace App\Http\Controllers\MiniApp;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();

        // Return user data, securely hiding sensitive fields like passwords/tokens
        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'telegram_first_name' => $user->telegram_first_name,
                'telegram_username' => $user->telegram_username,
                'phone' => $user->phone,
                'avatar_url' => $user->avatar_url ?? null,
                'joined_at' => $user->created_at->format('M Y'),
                'store_id' => $user->store_id,
            ]
        ]);
    }
}

