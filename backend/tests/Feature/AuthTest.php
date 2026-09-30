<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(Role $role = Role::Owner): User
    {
        return User::factory()->create([
            'email' => $role->value.'@pharmalink.test',
            'role' => $role,
        ]);
    }

    public function test_login_sukses_mengembalikan_token_dan_role(): void
    {
        $this->makeUser(Role::Owner);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'owner@pharmalink.test',
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'role']])
            ->assertJsonPath('user.role', 'owner');
    }

    public function test_login_gagal_dengan_password_salah(): void
    {
        $this->makeUser();

        $this->postJson('/api/auth/login', [
            'email' => 'owner@pharmalink.test',
            'password' => 'salah',
        ])->assertUnprocessable();
    }

    public function test_user_endpoint_memerlukan_token(): void
    {
        $this->getJson('/api/user')->assertUnauthorized();
    }

    public function test_logout_mencabut_token(): void
    {
        $user = $this->makeUser();

        $login = $this->postJson('/api/auth/login', [
            'email' => 'owner@pharmalink.test',
            'password' => 'password',
        ])->assertOk();

        $token = $login->json('token');

        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();

        // Token yang sama tidak berlaku setelah dicabut.
        $this->withToken($token)->getJson('/api/user')->assertUnauthorized();
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $user->id,
        ]);
    }
}
