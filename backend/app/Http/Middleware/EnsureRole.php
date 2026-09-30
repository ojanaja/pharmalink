<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware role sederhana: 'role:owner' atau 'role:owner,apoteker'.
 * Otorisasi halus tetap di Policy; middleware ini untuk blokir per-route.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        abort_if($user === null, 401);
        abort_unless(in_array($user->role->value, $roles, true), 403);

        return $next($request);
    }
}
