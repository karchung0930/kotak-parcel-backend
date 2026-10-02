<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs out users whose account was deactivated while they were logged in.
 */
class EnsureUserIsActive
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->is_active) {
            return $next($request);
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Inertia::clearHistory();

        abort_if($request->expectsJson(), Response::HTTP_FORBIDDEN, 'Your account has been deactivated.');

        return redirect()->route('login')->withErrors([
            'email' => __('Your account has been deactivated. Please contact your administrator.'),
        ]);
    }
}
