<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class UserController extends Controller
{
    public function index()
    {
        $users = User::query()
            ->select('id', 'name', 'email', 'role', 'is_admin', 'payment_qr_path', 'created_at')
            ->get();

        return response()->json([
            'data' => $users->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'is_admin' => $user->is_admin,
                'payment_qr_image_url' => $user->paymentQrImageUrl(),
                'created_at' => $user->created_at,
            ]),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6',
            'role' => 'required|in:superadmin,controller',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
            'is_admin' => true,
            'is_active' => true,
        ]);

        return response()->json([
            'message' => 'User created successfully',
            'data' => $user,
        ], 201);
    }

    public function uploadPaymentQr(Request $request, User $user)
    {
        abort_unless($request->user()->role === 'superadmin', 403, 'Only super admins can manage payment QR images.');
        abort_unless($user->is_admin, 404);

        $validated = $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $newPath = $validated['image']->storePublicly('payment-qr-codes', 'public');
        if (! $newPath) {
            return response()->json(['message' => 'The payment QR image could not be saved.'], 500);
        }

        $previousPath = $user->payment_qr_path;

        try {
            $user->update(['payment_qr_path' => $newPath]);
        } catch (\Throwable $exception) {
            Storage::disk('public')->delete($newPath);
            throw $exception;
        }

        if ($previousPath && ! Storage::disk('public')->delete($previousPath)) {
            Log::warning('A replaced payment QR image could not be removed.', [
                'user_id' => $user->id,
                'path' => $previousPath,
            ]);
        }

        return response()->json([
            'message' => 'Payment QR image updated successfully.',
            'data' => [
                'user_id' => $user->id,
                'payment_qr_image_url' => $user->paymentQrImageUrl(),
            ],
        ]);
    }

    public function destroy(User $user)
    {
        // Prevent deleting yourself
        if ($user->id === Auth::id()) {
            return response()->json(['message' => 'You cannot delete your own account.'], 403);
        }

        $user->delete();

        return response()->noContent();
    }
}
