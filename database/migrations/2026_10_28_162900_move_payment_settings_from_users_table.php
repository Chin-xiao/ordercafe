<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $hasQrColumn = Schema::hasColumn('users', 'payment_qr_path');
        $hasLinkColumn = Schema::hasColumn('users', 'payment_link');

        if ($hasQrColumn || $hasLinkColumn) {
            $users = DB::table('users')->select('id');

            if ($hasQrColumn) {
                $users->addSelect('payment_qr_path');
            }

            if ($hasLinkColumn) {
                $users->addSelect('payment_link');
            }

            $users->where(function ($query) use ($hasQrColumn, $hasLinkColumn): void {
                if ($hasQrColumn) {
                    $query->whereNotNull('payment_qr_path');
                }

                if ($hasLinkColumn) {
                    $method = $hasQrColumn ? 'orWhereNotNull' : 'whereNotNull';
                    $query->{$method}('payment_link');
                }
            })->orderBy('id')->chunkById(100, function ($legacyUsers) use ($hasQrColumn, $hasLinkColumn): void {
                foreach ($legacyUsers as $legacyUser) {
                    $setting = DB::table('payment_settings')
                        ->where('user_id', $legacyUser->id)
                        ->first();

                    $qrImageUrl = $hasQrColumn ? $legacyUser->payment_qr_path : null;
                    if ($qrImageUrl !== null && ! preg_match('#^https?://#i', $qrImageUrl)) {
                        $qrImageUrl = rtrim((string) config('app.url'), '/').'/storage/'.ltrim($qrImageUrl, '/');
                    }
                    $paymentLink = $hasLinkColumn ? $legacyUser->payment_link : null;

                    if (! $setting) {
                        DB::table('payment_settings')->insert([
                            'user_id' => $legacyUser->id,
                            'qr_image_url' => $qrImageUrl,
                            'payment_link' => $paymentLink,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);

                        continue;
                    }

                    $updates = [];
                    if ($setting->qr_image_url === null && $qrImageUrl !== null) {
                        $updates['qr_image_url'] = $qrImageUrl;
                    }
                    if ($setting->payment_link === null && $paymentLink !== null) {
                        $updates['payment_link'] = $paymentLink;
                    }

                    if ($updates !== []) {
                        DB::table('payment_settings')
                            ->where('user_id', $legacyUser->id)
                            ->update([...$updates, 'updated_at' => now()]);
                    }
                }
            });
        }

        $legacyColumns = array_values(array_filter([
            $hasQrColumn ? 'payment_qr_path' : null,
            $hasLinkColumn ? 'payment_link' : null,
        ]));

        if ($legacyColumns !== []) {
            Schema::table('users', function (Blueprint $table) use ($legacyColumns): void {
                $table->dropColumn($legacyColumns);
            });
        }
    }

    public function down(): void
    {
        $hasQrColumn = Schema::hasColumn('users', 'payment_qr_path');
        $hasLinkColumn = Schema::hasColumn('users', 'payment_link');

        Schema::table('users', function (Blueprint $table) use ($hasQrColumn, $hasLinkColumn): void {
            if (! $hasQrColumn) {
                $table->string('payment_qr_path', 1000)->nullable();
            }
            if (! $hasLinkColumn) {
                $table->string('payment_link', 1000)->nullable();
            }
        });

        if (! Schema::hasTable('payment_settings')) {
            return;
        }

        DB::table('payment_settings')->orderBy('id')->chunkById(100, function ($settings): void {
            foreach ($settings as $setting) {
                DB::table('users')
                    ->where('id', $setting->user_id)
                    ->update([
                        'payment_qr_path' => $setting->qr_image_url,
                        'payment_link' => $setting->payment_link,
                    ]);
            }
        });
    }
};
