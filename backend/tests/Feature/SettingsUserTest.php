<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsUserTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $apoteker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => Role::Owner]);
        $this->apoteker = User::factory()->create(['role' => Role::Apoteker]);
    }

    private function token(User $user): string
    {
        return $user->createToken('api')->plainTextToken;
    }

    public function test_settings_bisa_dilihat_apoteker_dan_diubah_owner(): void
    {
        $this->withToken($this->token($this->apoteker))
            ->getJson('/api/settings')
            ->assertOk()
            ->assertJsonPath('data.name', 'Apotek Pharmalink')
            ->assertJsonPath('data.expiry_warning_days', 30);

        // Apoteker tidak boleh mengubah pengaturan.
        $this->withToken($this->token($this->apoteker))->putJson('/api/settings', [
            'name' => 'Apotek Baru',
            'expiry_warning_days' => 60,
            'invoice_prefix' => 'TRX',
            'po_prefix' => 'PO',
            'receipt_prefix' => 'RCV',
            'opname_prefix' => 'OPN',
        ])->assertForbidden();

        // Owner boleh, prefix ikut berubah.
        $this->withToken($this->token($this->owner))->putJson('/api/settings', [
            'name' => 'Apotek Sehat Sentosa',
            'license_number' => 'DKI-001',
            'pharmacist_name' => 'apt. Budi',
            'address' => 'Jl. Sehat No. 1',
            'phone' => '021-123456',
            'expiry_warning_days' => 45,
            'invoice_prefix' => 'INV',
            'po_prefix' => 'PO',
            'receipt_prefix' => 'RCV',
            'opname_prefix' => 'OPN',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Apotek Sehat Sentosa')
            ->assertJsonPath('data.expiry_warning_days', 45)
            ->assertJsonPath('data.invoice_prefix', 'INV');

        // Validasi: expiry_warning_days di luar 1-90 ditolak.
        $this->withToken($this->token($this->owner))->putJson('/api/settings', [
            'name' => 'Apotek',
            'expiry_warning_days' => 0,
            'invoice_prefix' => 'TRX', 'po_prefix' => 'PO', 'receipt_prefix' => 'RCV', 'opname_prefix' => 'OPN',
        ])->assertUnprocessable()->assertJsonValidationErrors(['expiry_warning_days']);
    }

    public function test_user_management_apoteker_ditolak_semua(): void
    {
        $token = $this->token($this->apoteker);

        $this->withToken($token)->getJson('/api/users')->assertForbidden();
        $this->withToken($token)->postJson('/api/users', [
            'name' => 'X', 'email' => 'x@x.test', 'password' => 'password', 'role' => 'apoteker',
        ])->assertForbidden();
        $this->withToken($token)->putJson("/api/users/{$this->owner->id}", ['name' => 'Y'])->assertForbidden();
        $this->withToken($token)->postJson("/api/users/{$this->owner->id}/reset-password", ['password' => 'passwordbaru'])->assertForbidden();
        $this->withToken($token)->postJson("/api/users/{$this->owner->id}/toggle-active")->assertForbidden();
    }

    public function test_owner_crud_user_dan_unique_email(): void
    {
        $created = $this->withToken($this->token($this->owner))->postJson('/api/users', [
            'name' => 'Apoteker Dua',
            'email' => 'apoteker2@pharmalink.test',
            'password' => 'rahasia123',
            'role' => 'apoteker',
        ])->assertCreated()->json('data');

        $this->assertSame('apoteker', $created['role']);
        $this->assertTrue($created['is_active']);
        $this->assertArrayNotHasKey('password', $created);

        // Email duplikat ditolak.
        $this->withToken($this->token($this->owner))->postJson('/api/users', [
            'name' => 'Duplikat', 'email' => 'apoteker2@pharmalink.test',
            'password' => 'rahasia123', 'role' => 'apoteker',
        ])->assertUnprocessable()->assertJsonValidationErrors(['email']);

        // Update email diri sendiri tidak bentrok unique-nya.
        $this->withToken($this->token($this->owner))->putJson("/api/users/{$this->owner->id}", [
            'email' => $this->owner->email,
            'name' => 'Owner Utama',
        ])->assertOk()->assertJsonPath('data.name', 'Owner Utama');

        // Daftar user tanpa password.
        $index = $this->withToken($this->token($this->owner))->getJson('/api/users')->assertOk()->json('data');
        $this->assertStringNotContainsString('password', json_encode($index, JSON_THROW_ON_ERROR));
    }

    public function test_reset_password_mencabut_token(): void
    {
        $target = User::factory()->create(['role' => Role::Apoteker, 'email' => 'target@pharmalink.test']);
        $oldToken = $target->createToken('api')->plainTextToken;

        $this->withToken($this->token($this->owner))->postJson("/api/users/{$target->id}/reset-password", [
            'password' => 'passwordbaru',
        ])->assertOk();

        // Token lama dicabut.
        $this->withToken($oldToken)->getJson('/api/user')->assertUnauthorized();

        // Password baru berlaku.
        $login = $this->postJson('/api/auth/login', [
            'email' => 'target@pharmalink.test', 'password' => 'passwordbaru',
        ])->assertOk();
        $this->withToken($login->json('token'))->getJson('/api/user')->assertOk();
    }

    public function test_reset_password_diri_sendiri_boleh(): void
    {
        $this->withToken($this->token($this->owner))->postJson("/api/users/{$this->owner->id}/reset-password", [
            'password' => 'passwordowner',
        ])->assertOk();

        $this->postJson('/api/auth/login', [
            'email' => $this->owner->email, 'password' => 'passwordowner',
        ])->assertOk();
    }

    public function test_toggle_active_mencabut_token_dan_memblokir_login(): void
    {
        $target = User::factory()->create(['role' => Role::Apoteker, 'email' => 'off@pharmalink.test']);
        $token = $target->createToken('api')->plainTextToken;

        $this->withToken($this->token($this->owner))
            ->postJson("/api/users/{$target->id}/toggle-active")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        // Token dicabut + login ditolak 403.
        $this->withToken($token)->getJson('/api/user')->assertUnauthorized();
        $response = $this->postJson('/api/auth/login', [
            'email' => 'off@pharmalink.test', 'password' => 'password',
        ]);
        $response->assertForbidden();
        $this->assertSame('Akun nonaktif. Hubungi owner.', $response->json('message'));

        // Aktifkan lagi: login normal.
        $this->withToken($this->token($this->owner))
            ->postJson("/api/users/{$target->id}/toggle-active")
            ->assertJsonPath('data.is_active', true);

        $this->postJson('/api/auth/login', [
            'email' => 'off@pharmalink.test', 'password' => 'password',
        ])->assertOk();
    }

    public function test_self_protection_toggle_diri_sendiri(): void
    {
        $this->withToken($this->token($this->owner))
            ->postJson("/api/users/{$this->owner->id}/toggle-active")
            ->assertUnprocessable();
    }

    public function test_last_owner_protection(): void
    {
        // Satu owner aktif: tidak bisa menurunkan role sendiri.
        $this->withToken($this->token($this->owner))->putJson("/api/users/{$this->owner->id}", [
            'role' => 'apoteker',
        ])->assertUnprocessable();

        // Owner kedua aktif: menurunkan owner pertama boleh.
        $owner2 = User::factory()->create(['role' => Role::Owner]);
        $this->withToken($this->token($owner2))->putJson("/api/users/{$this->owner->id}", [
            'role' => 'apoteker',
        ])->assertOk()->assertJsonPath('data.role', 'apoteker');

        // Owner2 (owner aktif terakhir) toggle diri sendiri tetap ditolak (self-protection).
        $this->withToken($this->token($owner2))->postJson("/api/users/{$owner2->id}/toggle-active")
            ->assertUnprocessable();

        // Kembalikan role owner pertama menjadi owner, lalu nonaktifkan owner2: boleh.
        $this->withToken($this->token($owner2))->putJson("/api/users/{$this->owner->id}", ['role' => 'owner'])->assertOk();
        $this->withToken($this->token($this->owner))->postJson("/api/users/{$owner2->id}/toggle-active")
            ->assertOk()->assertJsonPath('data.is_active', false);
    }

    public function test_tanpa_token_ditolak(): void
    {
        $this->getJson('/api/settings')->assertUnauthorized();
        $this->getJson('/api/users')->assertUnauthorized();
    }
}
