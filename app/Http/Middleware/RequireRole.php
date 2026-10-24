<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();
        abort_unless($user && $user->is_active && $user->hasKnownRole(), 403);
        if ($roles !== []) {
            abort_unless(collect($roles)->contains(fn ($role) => $user->hasRole($role)), 403);
        }

        return $next($request);
    }
}
