<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateWorkdesk;
use Closure;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AuthenticateWorkdeskTest extends TestCase
{
    private ?string $previousAuthorizationBypass;

    protected function setUp(): void
    {
        parent::setUp();

        $previousValue = getenv('WORKDESK_DISABLE_AUTHORIZATION');
        $this->previousAuthorizationBypass = $previousValue === false ? null : $previousValue;
        putenv('WORKDESK_DISABLE_AUTHORIZATION=true');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('role');
            $table->string('org_level');
            $table->string('department_id')->nullable();
            $table->boolean('active')->default(true);
        });
        Schema::create('auth_sessions', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id');
            $table->string('token_hash');
            $table->unsignedBigInteger('expires_at');
        });

        DB::table('users')->insert([
            'id' => 1,
            'name' => 'Employee',
            'role' => 'member',
            'org_level' => 'employee',
            'department_id' => 'dep-a',
            'active' => true,
        ]);
        DB::table('auth_sessions')->insert([
            'user_id' => 1,
            'token_hash' => hash('sha256', 'session-token'),
            'expires_at' => (int) floor(microtime(true) * 1000) + 60_000,
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->previousAuthorizationBypass === null) {
            putenv('WORKDESK_DISABLE_AUTHORIZATION');
        } else {
            putenv('WORKDESK_DISABLE_AUTHORIZATION='.$this->previousAuthorizationBypass);
        }

        Schema::dropIfExists('auth_sessions');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    public function test_authorization_bypass_setting_does_not_promote_users_to_admin(): void
    {
        $response = $this->handle('Bearer session-token', fn (Request $authenticatedRequest) => response()->json([
            'role' => $authenticatedRequest->attributes->get('workdesk_user')->role,
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('member', $response->getData()->role);
    }

    public function test_bearer_token_is_required_and_must_be_valid(): void
    {
        $this->assertSame(401, $this->handle(null)->getStatusCode());
        $this->assertSame(401, $this->handle('Bearer wrong-token')->getStatusCode());
        $this->assertSame(401, $this->handle('Basic session-token')->getStatusCode());
        $this->assertSame(200, $this->handle('Bearer session-token', null, 'POST')->getStatusCode());

        // Cookie phiên cũ không còn được chấp nhận.
        $cookieOnly = Request::create('http://workdesk.test/api/overtime/meta', 'GET', [], ['workdesk_session' => 'session-token']);
        $this->assertSame(401, (new AuthenticateWorkdesk)->handle($cookieOnly, fn () => response()->json([]))->getStatusCode());
    }

    public function test_expired_token_and_inactive_user_are_rejected(): void
    {
        DB::table('auth_sessions')->update(['expires_at' => (int) floor(microtime(true) * 1000) - 1]);
        $this->assertSame(401, $this->handle('Bearer session-token')->getStatusCode());

        DB::table('auth_sessions')->update(['expires_at' => (int) floor(microtime(true) * 1000) + 60_000]);
        DB::table('users')->update(['active' => false]);
        $this->assertSame(401, $this->handle('Bearer session-token')->getStatusCode());
    }

    private function handle(?string $authorization, ?Closure $next = null, string $method = 'GET')
    {
        $request = Request::create('http://workdesk.test/api/overtime/meta', $method);
        if ($authorization !== null) {
            $request->headers->set('Authorization', $authorization);
        }

        return (new AuthenticateWorkdesk)->handle($request, $next ?? fn () => response()->json(['ok' => true]));
    }
}
