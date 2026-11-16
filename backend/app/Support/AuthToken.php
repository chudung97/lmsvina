<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Token đăng nhập dạng Bearer.
 *
 * - POST /api/auth/login trả về token; frontend gửi lại ở header `Authorization: Bearer <token>`.
 * - Bảng auth_sessions chỉ lưu SHA-256 của token, không lưu token gốc.
 * - Token hết hạn sau LIFETIME_HOURS giờ, bị thu hồi khi đăng xuất hoặc đổi mật khẩu.
 */
class AuthToken
{
    public const LIFETIME_HOURS = 12;

    /** Token lấy từ header Authorization: Bearer, hoặc null nếu không có/không hợp lệ. */
    public static function fromRequest(Request $request): ?string
    {
        $token = $request->bearerToken();

        return is_string($token) && $token !== '' && strlen($token) <= 128 ? $token : null;
    }

    /** Người dùng đang hoạt động sở hữu token còn hạn, hoặc null. */
    public static function user(?string $token): ?object
    {
        if ($token === null) {
            return null;
        }

        return DB::table('auth_sessions')
            ->join('users', 'users.id', '=', 'auth_sessions.user_id')
            ->where('auth_sessions.token_hash', hash('sha256', $token))
            ->where('auth_sessions.expires_at', '>', self::nowMs())
            ->where('users.active', 1)
            ->select('users.*')
            ->first();
    }

    /** @return array{token:string,tokenType:string,expiresAt:string,expiresIn:int} */
    public static function issue(int|string $userId): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $expiresIn = self::LIFETIME_HOURS * 3600;
        $expiresAtMs = self::nowMs() + $expiresIn * 1000;
        DB::table('auth_sessions')->insert([
            'token_hash' => hash('sha256', $token),
            'user_id' => (int) $userId,
            'expires_at' => $expiresAtMs,
        ]);

        return [
            'token' => $token,
            'tokenType' => 'Bearer',
            'expiresAt' => gmdate('Y-m-d\TH:i:s\Z', intdiv($expiresAtMs, 1000)),
            'expiresIn' => $expiresIn,
        ];
    }

    public static function revoke(?string $token): void
    {
        if ($token !== null) {
            DB::table('auth_sessions')->where('token_hash', hash('sha256', $token))->delete();
        }
    }

    public static function purgeExpired(): void
    {
        DB::table('auth_sessions')->where('expires_at', '<=', self::nowMs())->delete();
    }

    private static function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }
}
