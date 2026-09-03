<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\KeepAliveController;
use App\Http\Middleware\EnsureActiveSession;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

class IdleSessionTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(array $attrs = []): User
    {
        return User::create(array_merge([
            'branch_id' => Branch::factory()->create()->id,
            'name' => 'Test User',
            'email' => 'test@wis-cms.local',
            'password' => Hash::make('Password@123'),
            'is_active' => true,
        ], $attrs));
    }

    public function test_keep_alive_touches_session_and_returns_lifetime(): void
    {
        $user = $this->makeUser();

        $session = Session::driver('array');
        $request = Request::create('/api/auth/keep-alive', 'POST');
        $request->setLaravelSession($session);
        $request->setUserResolver(fn () => $user);

        $controller = new KeepAliveController;
        $response = $controller($request);

        $this->assertEquals(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertEquals((int) config('session.lifetime', 15), $data['session_lifetime']);
        $this->assertNotNull($session->get('last_activity_at'));
    }

    public function test_keep_alive_returns_401_for_unauthenticated_request(): void
    {
        $response = $this->postJson('/api/auth/keep-alive');

        $response->assertStatus(401);
    }

    public function test_active_session_passes_ensure_active_session_middleware(): void
    {
        $middleware = new EnsureActiveSession;

        $session = Session::driver('array');
        $session->put('last_activity_at', now()->subMinutes(5)->timestamp);

        $request = Request::create('/test', 'GET');
        $request->setLaravelSession($session);

        $response = $middleware->handle($request, fn () => response()->json(['ok' => true]));

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(['ok' => true], json_decode($response->getContent(), true));
    }

    public function test_expired_session_returns_401_via_ensure_active_session_middleware(): void
    {
        config(['session.lifetime' => 15]);

        $middleware = new EnsureActiveSession;

        $session = Session::driver('array');
        $session->put('last_activity_at', now()->subMinutes(20)->timestamp);

        $request = Request::create('/test', 'GET');
        $request->setLaravelSession($session);

        $response = $middleware->handle($request, fn () => response()->json(['ok' => true]));

        $this->assertEquals(401, $response->getStatusCode());
        $this->assertStringContainsString('Session expired', $response->getContent());
    }

    public function test_session_without_last_activity_at_passes_middleware(): void
    {
        $middleware = new EnsureActiveSession;

        $session = Session::driver('array');

        $request = Request::create('/test', 'GET');
        $request->setLaravelSession($session);

        $response = $middleware->handle($request, fn () => response()->json(['ok' => true]));

        $this->assertEquals(200, $response->getStatusCode());
    }
}
