<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateWorkdesk;
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
        $request = Request::create(
            'http://workdesk.test/api/meal-reports',
            'POST',
            [],
            ['workdesk_session' => 'session-token'],
            [],
            ['HTTP_ORIGIN' => 'http://workdesk.test'],
        );

        $response = (new AuthenticateWorkdesk)->handle(
            $request,
            fn (Request $authenticatedRequest) => response()->json([
                'role' => $authenticatedRequest->attributes->get('workdesk_user')->role,
            ]),
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('member', $response->getData()->role);
    }

    public function test_authorization_bypass_setting_does_not_disable_origin_check(): void
    {
        $request = Request::create(
            'http://workdesk.test/api/meal-reports',
            'POST',
            [],
            ['workdesk_session' => 'session-token'],
            [],
            ['HTTP_ORIGIN' => 'https://untrusted.example'],
        );

        $response = (new AuthenticateWorkdesk)->handle($request, fn () => response()->json(['ok' => true]));

        $this->assertSame(403, $response->getStatusCode());
    }
}
