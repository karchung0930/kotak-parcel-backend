<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route guard used as "role:staff,admin". Policies still authorise each record.
 */
class EnsureUserHasRole
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $allowed = array_map(fn (string $role): Role => Role::from($role), $roles);

        abort_unless($request->user()?->hasRole(...$allowed), Response::HTTP_FORBIDDEN);

        return $next($request);
    }
}
