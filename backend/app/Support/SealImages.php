<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Lưu trữ ảnh con dấu (PNG) trong thư mục dữ liệu PDF (mặc định data/seals).
 * Ảnh mẫu của người dùng nằm ở data/seals, ảnh gắn với từng con dấu đã đặt ở
 * data/seals/signed (chụp lại lúc đặt dấu nên đổi mẫu sau này không ảnh hưởng).
 */
class SealImages
{
    public const MAX_BYTES = 3 * 1024 * 1024;

    public static function directory(string $sub = ''): string
    {
        $base = rtrim((string) env('PDF_SEAL_DATA_DIR', base_path('../data')), '\\/').DIRECTORY_SEPARATOR.'seals';
        $directory = $sub === '' ? $base : $base.DIRECTORY_SEPARATOR.$sub;
        if (! is_dir($directory) && ! mkdir($directory, 0770, true) && ! is_dir($directory)) {
            throw new \RuntimeException('Could not create the seal image directory.');
        }

        return $directory;
    }

    /** Kiểm tra nội dung là PNG hợp lệ; trả về [width, height] hoặc null. */
    public static function pngSize(string $bytes): ?array
    {
        if (strlen($bytes) < 33 || strlen($bytes) > self::MAX_BYTES || ! str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            return null;
        }
        $info = @getimagesizefromstring($bytes);
        if (! $info || ($info[2] ?? null) !== IMAGETYPE_PNG) {
            return null;
        }
        [$width, $height] = $info;
        if ($width < 16 || $height < 16 || $width > 3000 || $height > 3000) {
            return null;
        }

        return [$width, $height];
    }

    public static function storeProfileImage(string $bytes): string
    {
        $path = self::directory().DIRECTORY_SEPARATOR.Str::uuid().'.png';
        file_put_contents($path, $bytes);

        return $path;
    }

    public static function profile(int|string $userId): ?object
    {
        return DB::table('user_seal_profiles')->where('user_id', (int) $userId)->first();
    }

    /**
     * Sao chép ảnh mẫu dấu hiện tại của người dùng cho một con dấu mới.
     * Trả về ['path' => ..., 'aspect' => ...] hoặc null khi chưa có mẫu ảnh (dùng dấu chữ).
     */
    public static function copyProfileImage(int|string $userId): ?array
    {
        $profile = self::profile($userId);
        if (! $profile?->image_path || ! is_file($profile->image_path)) {
            return null;
        }
        $target = self::directory('signed').DIRECTORY_SEPARATOR.(int) $userId.'-'.Str::uuid().'.png';
        if (! copy($profile->image_path, $target)) {
            throw new \RuntimeException('Could not copy the seal image.');
        }

        return ['path' => $target, 'aspect' => (float) $profile->aspect];
    }
}
