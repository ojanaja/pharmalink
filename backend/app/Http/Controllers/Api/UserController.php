<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\ResetPasswordRequest;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(User::class, 'user');
    }

    public function index(): AnonymousResourceCollection
    {
        return UserResource::collection(
            User::query()->orderBy('name')->paginate(15)
        );
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        // refresh() memuat default DB (is_active) agar respons lengkap.
        $user = User::create($request->validated())->refresh();

        return (new UserResource($user))->response()->setStatusCode(201);
    }

    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        // Last-owner protection: owner aktif tidak boleh diturunkan role-nya
        // bila tidak ada owner aktif lain.
        $demotingOwner = $user->role === Role::Owner
            && $request->filled('role')
            && $request->string('role')->toString() !== Role::Owner->value
            && $user->is_active;

        if ($demotingOwner) {
            $this->assertOtherActiveOwnerExists($user);
        }

        $user->update($request->validated());

        return new UserResource($user->refresh());
    }

    /**
     * Reset password + cabut semua token user tsb. Reset diri sendiri boleh
     * (ganti password sendiri wajar); owner lain tetap via policy.
     */
    public function resetPassword(ResetPasswordRequest $request, User $user): JsonResponse
    {
        DB::transaction(function () use ($request, $user) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $locked->password = $request->string('password')->toString();
            $locked->save();
            $locked->tokens()->delete();
        });

        return response()->json(['message' => 'Password direset; semua sesi user tersebut dicabut.']);
    }

    /**
     * Toggle aktif/nonaktif; menonaktifkan = cabut semua token.
     * Tidak boleh menonaktifkan diri sendiri atau owner aktif terakhir.
     */
    public function toggleActive(User $user): UserResource
    {
        $this->authorize('toggleActive', $user);

        if ($user->id === auth()->id()) {
            throw ValidationException::withMessages([
                'user' => ['Tidak dapat mengubah status aktif akun sendiri.'],
            ]);
        }

        if ($user->is_active && $user->role === Role::Owner) {
            $this->assertOtherActiveOwnerExists($user);
        }

        $user->is_active = ! $user->is_active;
        $user->save();

        if (! $user->is_active) {
            $user->tokens()->delete();
        }

        return new UserResource($user->refresh());
    }

    /**
     * @throws HttpResponseException 422 bila ini owner aktif terakhir.
     */
    protected function assertOtherActiveOwnerExists(User $user): void
    {
        $other = User::query()
            ->where('role', Role::Owner)
            ->where('is_active', true)
            ->where('id', '!=', $user->id)
            ->exists();

        if (! $other) {
            throw ValidationException::withMessages([
                'user' => ['Tidak dapat menurunkan/menonaktifkan owner aktif terakhir.'],
            ]);
        }
    }
}
