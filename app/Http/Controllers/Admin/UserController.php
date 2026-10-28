<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index()
    {
        $users = User::query()
            ->select('id', 'name', 'email', 'role', 'is_admin', 'created_at')
            ->with('paymentSetting')
            ->get();

        return response()->json([
            'data' => $users->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'is_admin' => $user->is_admin,
                'payment_qr_image_url' => $user->paymentQrImageUrl(),
                'payment_link' => $user->paymentLink(),
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
        abort_unless(
            $request->user()?->role === 'superadmin',
            403,
            'Only super admins can manage payment QR images.'
        );
        abort_unless($user->is_admin, 404, 'User is not an admin.');

        $validated = $request->validate([
            'payment_qr_url' => ['required', 'string', 'url', 'max:1000'],
        ]);

        $setting = $user->paymentSetting()->updateOrCreate(
            ['user_id' => $user->id],
            ['qr_image_url' => $validated['payment_qr_url']]
        );

        return response()->json([
            'message' => 'Payment QR image updated successfully.',
            'data' => [
                'user_id' => $user->id,
                'payment_qr_image_url' => $setting->qr_image_url,
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

    public function updatePaymentLink(Request $request, User $user)
    {
        abort_unless(
            $request->user()?->role === 'superadmin',
            403,
            'Only super admins can manage payment links.'
        );
        abort_unless($user->is_admin, 404, 'User is not an admin.');

        $validated = $request->validate([
            'payment_link' => ['present', 'nullable', 'string', 'url', 'max:1000'],
        ]);

        $setting = $user->paymentSetting()->updateOrCreate(
            ['user_id' => $user->id],
            ['payment_link' => $validated['payment_link']]
        );

        return response()->json([
            'message' => 'Payment link updated successfully.',
            'data' => [
                'user_id' => $user->id,
                'payment_link' => $setting->payment_link,
            ],
        ]);
    }
}
