<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureAdminActive;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class EnsureAdminActiveTest extends TestCase
{
    // logout() saves the admin (remember-token cycle), so this test needs a real
    // `admins` table. Only that migration is run - the full set is MySQL-only.
    protected function setUp(): void
    {
        parent::setUp();

        (require database_path('migrations/0001_01_01_000003_create_admins_table.php'))->up();
    }

    private function handle(?Admin $admin): \Symfony\Component\HttpFoundation\Response
    {
        if ($admin) {
            Auth::guard('admin')->setUser($admin);
        }

        $request = Request::create('/admin/me', 'GET');
        $request->setUserResolver(fn ($guard = null) => $guard === 'admin' ? $admin : null);

        return (new EnsureAdminActive)->handle($request, fn () => response()->json(['ok' => true]));
    }

    public function test_active_admin_passes(): void
    {
        $response = $this->handle(Admin::factory()->create(['status' => 'active']));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_inactive_admin_is_signed_out_with_401(): void
    {
        $response = $this->handle(Admin::factory()->create(['status' => 'inactive']));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertStringContainsString('not active', $response->getContent());
        $this->assertNull(Auth::guard('admin')->user());
    }

    public function test_request_without_admin_passes_through(): void
    {
        // Developer-only requests carry no admin user; the auth guard decides those.
        $this->assertSame(200, $this->handle(null)->getStatusCode());
    }
}
