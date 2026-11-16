<?php

namespace App\Http\Controllers;

use App\Support\AuthToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\Process\Process;

class AuthController extends ApiController
{
    /** Trạng thái đăng nhập theo token Bearer gửi kèm (không có token: chưa đăng nhập). */
    public function status(Request $request): JsonResponse
    {
        $user = AuthToken::user(AuthToken::fromRequest($request));

        return response()->json([
            'authenticated' => (bool) $user,
            'user' => $user ? $this->publicUser($user) : null,
            'setupRequired' => ! DB::table('users')
                ->where('active', 1)
                ->where('role', 'admin')
                ->whereNotNull('password_hash')
                ->exists(),
        ]);
    }

    /**
     * Kiểm tra user_code (hoặc email) + mật khẩu, trả về token Bearer:
     * { token, tokenType: "Bearer", expiresAt, expiresIn, user }.
     */
    public function login(Request $request): JsonResponse
    {
        $identifier = $request->input('identifier', $request->input('email'));
        $password = $request->input('password');
        if (! is_string($identifier) || ! is_string($password)) {
            return $this->error('Vui lòng nhập mã nhân viên hoặc email và mật khẩu hợp lệ.', 400);
        }
        $identifier = trim($identifier);
        $email = filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false
            ? $this->normalizedEmail($identifier)
            : null;
        $userCode = $email === null ? $identifier : null;
        if ($identifier === '' || strlen($identifier) > 254 || strlen($password) > 1024
            || ($userCode !== null && mb_strlen($userCode) > 50)) {
            return $this->error('Vui lòng nhập mã nhân viên hoặc email và mật khẩu hợp lệ.', 400);
        }

        $normalizedIdentifier = mb_strtolower($email ?? $userCode);
        $key = 'workdesk-login:'.hash('sha256', $request->ip().'|'.$normalizedIdentifier);
        if (RateLimiter::tooManyAttempts($key, 8)) {
            return $this->error('Đăng nhập sai quá nhiều lần. Vui lòng thử lại sau 15 phút.', 429)
                ->header('Retry-After', (string) RateLimiter::availableIn($key));
        }

        $user = DB::table('users')
            ->where($email === null ? 'user_code' : 'email', $email ?? $userCode)
            ->where('active', 1)
            ->whereNotNull('password_hash')
            ->first();
        $verified = $this->verifyPassword(
            $password,
            $user?->password_hash ?? str_repeat('0', 32).':'.str_repeat('0', 128),
            $user?->id ? (string)$user->id : null,
        );

        if (! $user || ! $verified) {
            RateLimiter::hit($key, 900);

            return $this->error('Mã nhân viên/email hoặc mật khẩu không chính xác.', 401);
        }

        RateLimiter::clear($key);
        AuthToken::purgeExpired();
        $token = AuthToken::issue($user->id);
        $user = DB::table('users')->where('id', $user->id)->first();

        return response()->json([...$token, 'user' => $this->publicUser($user)]);
    }

    /** Thu hồi token đang dùng. */
    public function logout(Request $request): JsonResponse
    {
        AuthToken::revoke(AuthToken::fromRequest($request));

        return response()->json(['ok' => true]);
    }

    private function verifyPassword(string $password, string $encoded, ?string $userId): bool
    {
        if (str_starts_with($encoded, '$2y$') || str_starts_with($encoded, '$argon2')) {
            return Hash::check($password, $encoded);
        }

        if (! preg_match('/^([a-f0-9]{32}):([a-f0-9]{128})$/i', $encoded, $matches)) {
            return false;
        }

        $process = new Process(
            ['node', base_path('../scripts/verify-legacy-password.js')],
            base_path('..'),
        );
        $process->setInput(json_encode([
            'password' => $password,
            'salt' => $matches[1],
            'hash' => $matches[2],
        ], JSON_THROW_ON_ERROR));
        $process->mustRun();
        $valid = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);

        if ($valid === true && $userId !== null) {
            DB::table('users')
                ->where('id', (int)$userId)
                ->update(['password_hash' => Hash::make($password)]);
        }

        return $valid === true;
    }
}
