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
        abort_unless($user->is_admin, 404, 'User is not an admin.');

        $validated = $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        // 1. Initialize Cloudinary
        $cloudName = config('services.cloudinary.cloud_name') ?? env('CLOUDINARY_CLOUD_NAME');
        $apiKey    = config('services.cloudinary.api_key') ?? env('CLOUDINARY_API_KEY');
        $apiSecret = config('services.cloudinary.api_secret') ?? env('CLOUDINARY_API_SECRET');

        if ($cloudName && $apiKey && $apiSecret) {
            $cloudinary = new \Cloudinary\Cloudinary([
                'cloud' => [
                    'cloud_name' => $cloudName,
                    'api_key'    => $apiKey,
                    'api_secret' => $apiSecret,
                ],
                'url' => [
                    'secure' => true,
                ],
            ]);
        } else {
            $cloudinaryUrl = config('services.cloudinary.url') ?? env('CLOUDINARY_URL');
            $cloudinary = new \Cloudinary\Cloudinary($cloudinaryUrl);
        }

        // 2. Upload image to Cloudinary
        try {
            $uploadResult = $cloudinary->uploadApi()->upload(
                $validated['image']->getRealPath(),
                [
                    'folder'        => 'ordercafe/payment-qr-codes',
                    'public_id'     => 'user_qr_' . $user->id . '_' . time(),
                    'resource_type' => 'image',
                    'overwrite'     => true,
                ]
            );

            $secureUrl = $uploadResult['secure_url'];

        } catch (\Throwable $exception) {
            Log::error('Cloudinary upload failed: ' . $exception->getMessage(), [
                'user_id' => $user->id,
                'trace'   => $exception->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'The payment QR image could not be uploaded to cloud storage.',
                'error'   => $exception->getMessage(),
            ], 500);
        }

        // 3. Remove old image from Cloudinary if one previously existed
        if ($user->payment_qr_path && str_contains($user->payment_qr_path, 'cloudinary.com')) {
            try {
                $pathParts = parse_url($user->payment_qr_path, PHP_URL_PATH);
                if (preg_match('#upload/(?:v\d+/)?(.+)\.[a-zA-Z0-9]+$#', $pathParts, $matches)) {
                    $oldPublicId = $matches[1];
                    $cloudinary->uploadApi()->destroy($oldPublicId);
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to delete previous Cloudinary QR code: ' . $e->getMessage());
            }
        }

        // 4. Save new URL in database
        $user->update(['payment_qr_path' => $secureUrl]);

        return response()->json([
            'message' => 'Payment QR image updated successfully.',
            'data'    => [
                'user_id'              => $user->id,
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
