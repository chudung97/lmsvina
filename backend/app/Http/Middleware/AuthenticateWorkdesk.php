<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateWorkdesk
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->cookie('workdesk_session');
        if (! is_string($token) || strlen($token) > 128) {
            return response()->json(['error' => 'Vui lòng đăng nhập để tiếp tục.'], 401);
        }

        $user = DB::table('auth_sessions')
            ->join('users', 'users.id', '=', 'auth_sessions.user_id')
            ->where('auth_sessions.token_hash', hash('sha256', $token))
            ->where('auth_sessions.expires_at', '>', (int) floor(microtime(true) * 1000))
            ->where('users.active', 1)
            ->select('users.*')
            ->first();

        if (! $user) {
            return response()->json(['error' => 'Vui lòng đăng nhập để tiếp tục.'], 401);
        }

        if (! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            $origin = $request->header('Origin');
            if ($origin && $origin !== $request->getSchemeAndHttpHost()) {
                return response()->json(['error' => 'Yêu cầu không cùng nguồn bị từ chối.'], 403);
            }
        }

        $request->attributes->set('workdesk_user', $user);
        $authUser = new User;
        $authUser->setRawAttributes((array) $user, true);
        Auth::setUser($authUser);

        return $next($request);
    }
}
