<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\AuthToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Xác thực API bằng token Bearer (header `Authorization: Bearer <token>`) lấy từ /api/auth/login.
 * Trình duyệt không tự gửi header này sang trang khác, nên không cần kiểm tra Origin chống CSRF.
 */
class AuthenticateWorkdesk
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = AuthToken::user(AuthToken::fromRequest($request));
        if (! $user) {
            return response()->json(['error' => 'Vui lòng đăng nhập để tiếp tục.'], 401)
                ->header('WWW-Authenticate', 'Bearer');
        }

        $request->attributes->set('workdesk_user', $user);
        $authUser = new User;
        $authUser->setRawAttributes((array) $user, true);
        Auth::setUser($authUser);

        return $next($request);
    }
}
