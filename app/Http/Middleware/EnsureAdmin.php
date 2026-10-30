<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Check if user exists and is either a Super Admin or a Store Owner
        if (!$user || (!$user->is_admin && $user->role !== 'store_owner') || !$user->is_active) {
            return response()->json([
                'message' => 'You are not authorized to access this resource.'
            ], 403);
        }

        return $next($request);
    }
}
