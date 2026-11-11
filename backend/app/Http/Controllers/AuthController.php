<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\Process\Process;

class AuthController extends ApiController
{
    public function status(Request $request): JsonResponse
    {
        $token = $request->cookie('workdesk_session');
        $user = is_string($token) && strlen($token) <= 128
            ? DB::table('auth_sessions')
                ->join('users', 'users.id', '=', 'auth_sessions.user_id')
                ->where('auth_sessions.token_hash', hash('sha256', $token))
                ->where('auth_sessions.expires_at', '>', (int) floor(microtime(true) * 1000))
                ->where('users.active', 1)
                ->select('users.*')
                ->first()
            : null;

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

    public function login(Request $request): JsonResponse
    {
        if (! $this->originAllowed($request)) {
            return $this->error('Yêu cầu không cùng nguồn bị từ chối.', 403);
        }
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
        DB::table('auth_sessions')->where('expires_at', '<=', (int) floor(microtime(true) * 1000))->delete();
        $token = $this->newSession($user->id);
        $user = DB::table('users')->where('id', $user->id)->first();

        return response()->json(['user' => $this->publicUser($user)])
            ->withCookie($this->sessionCookie($token));
    }

    public function logout(Request $request): JsonResponse
    {
        if (! $this->originAllowed($request)) {
            return $this->error('Yêu cầu không cùng nguồn bị từ chối.', 403);
        }
        $token = $request->cookie('workdesk_session');
        if (is_string($token) && strlen($token) <= 128) {
            DB::table('auth_sessions')->where('token_hash', hash('sha256', $token))->delete();
        }

        return response()->json(['ok' => true])
            ->withCookie($this->sessionCookie('', 0));
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

    private function originAllowed(Request $request): bool
    {
        $origin = $request->header('Origin');
        if (! $origin) {
            return true;
        }

        $originHost = parse_url($origin, PHP_URL_HOST);
        $requestHost = $request->getHost();

        return $origin === $request->getSchemeAndHttpHost()
            || in_array($originHost, ['localhost', '127.0.0.1'], true)
               && in_array($requestHost, ['localhost', '127.0.0.1'], true);
    }
}
