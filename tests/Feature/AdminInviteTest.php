<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AdminInvite;
use App\Models\Feature;
use App\Models\Package;
use App\Models\Role;
use Illuminate\Support\Facades\Mail;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialUser;
use Tests\TestCase;

class AdminInviteTest extends TestCase
{
    private const JSON = ['Accept' => 'application/json'];

    // Full migration set is MySQL-only; build just the tables this feature touches.
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            '0001_01_01_000003_create_admins_table',
            '2026_09_23_000001_add_created_by_admin_id_to_admins_table',
            '2026_11_15_000400_add_google_id_to_admins_table',
            '2026_09_14_000003_create_roles_table',
            '2026_09_14_000004_create_features_table',
            '2026_09_14_000005_create_packages_table',
            '2026_09_14_000006_create_package_role_table',
            '2026_09_14_000007_create_package_feature_table',
            '2026_09_14_000008_create_admin_package_table',
            '2026_09_16_000001_create_package_limits_table',
            '2026_09_16_000003_create_permissions_table',
            '2026_09_16_000004_create_package_permission_table',
            '2026_11_15_000300_create_admin_invites_table',
        ] as $file) {
            (require database_path("migrations/$file.php"))->up();
        }

        Role::create(['name' => 'admin', 'is_global' => true, 'scope' => 'none']);
        Role::create(['name' => 'hotel_admin', 'is_global' => false, 'scope' => 'hotel']);
        Feature::create(['key' => 'users', 'label' => 'People', 'category' => 'admin', 'sort_order' => 0]);
        Feature::create(['key' => 'bookings', 'label' => 'Bookings', 'category' => 'admin', 'sort_order' => 1]);

        config(['app.frontend_url' => 'https://spa.test']);
    }

    private function orgAdmin(): Admin
    {
        $package = Package::create(['name' => 'Org admin', 'is_system' => true]);
        $package->roles()->sync([Role::where('name', 'admin')->value('id')]);
        $package->features()->sync(Feature::where('key', 'users')->pluck('id'));

        $admin = Admin::factory()->create();
        $admin->packages()->sync([$package->id => ['location_ids' => null, 'hotel_ids' => null]]);

        return $admin;
    }

    private function seat(): Package
    {
        $seat = Package::create(['name' => 'Front desk', 'is_system' => false]);
        $seat->roles()->sync([Role::where('name', 'hotel_admin')->value('id')]);
        $seat->features()->sync(Feature::where('key', 'bookings')->pluck('id'));

        return $seat;
    }

    private function invitePayload(Package $seat, array $over = []): array
    {
        return array_merge([
            'email' => 'Dara@Example.com',
            'name' => 'Dara',
            'packages' => [['packageId' => $seat->id, 'hotelIds' => [7]]],
        ], $over);
    }

    private function fakeGoogle(string $email, string $id = 'g-1', bool $verified = true): void
    {
        $user = (new SocialUser)->map(['id' => $id, 'name' => 'Dara G', 'email' => $email]);
        $user->user = ['email_verified' => $verified];

        $provider = \Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andReturn($user);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    public function test_admin_creates_an_invite_and_gets_a_link(): void
    {
        Mail::fake();
        $seat = $this->seat();

        $response = $this->actingAs($this->orgAdmin(), 'admin')
            ->postJson('/admin/invites', $this->invitePayload($seat), self::JSON)
            ->assertCreated()
            ->assertJsonPath('invite.email', 'dara@example.com')
            ->assertJsonPath('invite.status', 'pending');

        $this->assertStringStartsWith('https://spa.test/admin/accept-invite?token=', $response->json('inviteUrl'));
        $token = substr($response->json('inviteUrl'), strlen('https://spa.test/admin/accept-invite?token='));

        // only the hash is stored
        $this->assertDatabaseMissing('admin_invites', ['token_hash' => $token]);
        $this->assertNotNull(AdminInvite::findByToken($token));
    }

    public function test_invite_needs_a_scope_for_a_scoped_seat(): void
    {
        $seat = $this->seat();

        $this->actingAs($this->orgAdmin(), 'admin')
            ->postJson('/admin/invites', $this->invitePayload($seat, ['packages' => [['packageId' => $seat->id]]]), self::JSON)
            ->assertStatus(422);
    }

    public function test_cannot_invite_someone_who_already_has_an_account(): void
    {
        $admin = $this->orgAdmin();
        Admin::factory()->create(['email' => 'dara@example.com']);

        $this->actingAs($admin, 'admin')
            ->postJson('/admin/invites', $this->invitePayload($this->seat()), self::JSON)
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_new_invite_replaces_the_pending_one_and_revoke_works(): void
    {
        $admin = $this->orgAdmin();
        $seat = $this->seat();
        $this->actingAs($admin, 'admin');

        $first = $this->postJson('/admin/invites', $this->invitePayload($seat), self::JSON)->json('invite.id');
        $second = $this->postJson('/admin/invites', $this->invitePayload($seat), self::JSON)->json('invite.id');

        $this->assertSame('revoked', AdminInvite::find($first)->status());
        $this->assertSame('pending', AdminInvite::find($second)->status());

        $this->deleteJson("/admin/invites/{$second}", [], self::JSON)->assertOk()->assertJsonPath('invite.status', 'revoked');
    }

    public function test_preview_shows_a_valid_invite_and_hides_a_bad_one(): void
    {
        $this->actingAs($this->orgAdmin(), 'admin');
        $url = $this->postJson('/admin/invites', $this->invitePayload($this->seat()), self::JSON)->json('inviteUrl');
        $token = substr($url, strpos($url, 'token=') + 6);

        $this->getJson("/admin/invites/{$token}/preview", self::JSON)->assertOk()->assertJsonPath('valid', true)->assertJsonPath('email', 'dara@example.com');
        $this->getJson('/admin/invites/not-a-real-token/preview', self::JSON)->assertNotFound()->assertJsonPath('valid', false);
    }

    public function test_google_callback_creates_the_admin_with_the_invited_seat(): void
    {
        $this->actingAs($this->orgAdmin(), 'admin');
        $seat = $this->seat();
        $url = $this->postJson('/admin/invites', $this->invitePayload($seat), self::JSON)->json('inviteUrl');
        $token = substr($url, strpos($url, 'token=') + 6);

        $this->fakeGoogle('dara@example.com');

        $response = $this->withSession(['admin_google_invite' => $token])->get('/admin/auth/google/callback?code=abc');

        $response->assertRedirectContains('https://spa.test/admin/auth/google/complete?ticket=');

        $admin = Admin::where('email', 'dara@example.com')->first();
        $this->assertNotNull($admin);
        $this->assertSame('g-1', $admin->google_id);
        $this->assertSame('active', $admin->status);
        $this->assertSame([$seat->id], $admin->packages()->pluck('packages.id')->all());
        $this->assertSame('accepted', AdminInvite::findByToken($token)->status());
    }

    public function test_google_email_must_match_the_invite(): void
    {
        $this->actingAs($this->orgAdmin(), 'admin');
        $url = $this->postJson('/admin/invites', $this->invitePayload($this->seat()), self::JSON)->json('inviteUrl');
        $token = substr($url, strpos($url, 'token=') + 6);

        $this->fakeGoogle('someone-else@example.com');

        $this->withSession(['admin_google_invite' => $token])->get('/admin/auth/google/callback?code=abc')
            ->assertRedirectContains('error=invite_email_mismatch');

        $this->assertDatabaseMissing('admins', ['email' => 'someone-else@example.com']);
        $this->assertSame('pending', AdminInvite::findByToken($token)->status());
    }

    public function test_google_sign_in_without_an_invite_needs_an_existing_admin(): void
    {
        $this->fakeGoogle('stranger@example.com');

        $this->get('/admin/auth/google/callback?code=abc')->assertRedirectContains('error=no_invite');
    }

    public function test_ticket_exchange_logs_the_admin_in(): void
    {
        $admin = $this->orgAdmin();
        \Illuminate\Support\Facades\Cache::put('admin_google_login_ticket:'.str_repeat('a', 64), $admin->id, now()->addMinute());

        $this->postJson('/admin/auth/google/exchange', ['ticket' => str_repeat('a', 64)], self::JSON)
            ->assertOk()
            ->assertJsonPath('admin.id', $admin->id);

        $this->postJson('/admin/auth/google/exchange', ['ticket' => str_repeat('a', 64)], self::JSON)->assertStatus(419);
    }
}
