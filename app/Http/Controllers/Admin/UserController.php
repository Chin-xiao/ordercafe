<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    /**
     * List all admin/controller users with their payment settings.
     */
    public function index(): JsonResponse
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

    /**
     * Create a new administrator / controller user.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6'],
            'role' => ['required', 'in:superadmin,controller'],
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

    /**
     * SuperAdmin endpoint to set or replace an admin's payment QR image URL.
     *
     * POST /admin/users/{user}/payment-qr
     */
    public function uploadPaymentQr(Request $request, User $user): JsonResponse
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

    /**
     * SuperAdmin endpoint to update or clear an admin's direct payment link.
     *
     * PATCH /admin/users/{user}/payment-link
     */
    public function updatePaymentLink(Request $request, User $user): JsonResponse
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

    /**
     * Remove an admin user.
     */
    public function destroy(User $user): Response|JsonResponse
    {
        if ($user->id === Auth::id()) {
            return response()->json(['message' => 'You cannot delete your own account.'], 403);
        }

        $user->delete();

        return response()->noContent();
    }
}
