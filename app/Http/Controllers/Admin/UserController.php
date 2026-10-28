<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Cloudinary\Cloudinary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

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

        try {
            // 1. Initialize Cloudinary
            $cloudinary = new Cloudinary(env('CLOUDINARY_URL'));

            // 2. Upload image file to Cloudinary
            $uploadResult = $cloudinary->uploadApi()->upload(
                $validated['image']->getRealPath(),
                [
                    'folder' => 'ordercafe/payment-qr-codes',
                    'public_id' => 'user_qr_' . $user->id . '_' . time(),
                    'resource_type' => 'image',
                ]
            );

            // 3. Get the secure HTTPS CDN URL
            $secureUrl = $uploadResult['secure_url'];

        } catch (\Throwable $exception) {
            Log::error('Cloudinary upload failed: ' . $exception->getMessage(), [
                'user_id' => $user->id,
            ]);

            return response()->json([
                'message' => 'The payment QR image could not be uploaded to cloud storage.',
            ], 500);
        }

        // 4. Save the Cloudinary HTTPS URL directly into the database column
        $user->update(['payment_qr_path' => $secureUrl]);

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
