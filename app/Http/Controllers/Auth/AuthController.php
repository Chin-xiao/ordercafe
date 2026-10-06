<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (!Auth::attempt($credentials)) {
            return response()->json(['message' => 'Invalid email or password.'], 401);
        }

        $user = Auth::user();

        // Optional: Ensure the user has admin privileges (adjust based on your column name)
        if (property_exists($user, 'is_admin') && !$user->is_admin) {
            return response()->json(['message' => 'Access denied. Administrator privileges required.'], 403);
        }

        // Revoke old tokens if desired, then generate a new Sanctum token
        $token = $user->createToken('admin-token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'token' => $token,
            'user' => $user,
        ]);
    }
}
