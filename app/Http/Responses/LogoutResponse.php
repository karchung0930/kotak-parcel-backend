<?php

namespace App\Http\Responses;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Laravel\Fortify\Http\Responses\LogoutResponse as FortifyLogoutResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs out as Fortify does, then tells Inertia to clear the browser history,
 * so the Back button cannot bring back the previous user's pages.
 */
class LogoutResponse extends FortifyLogoutResponse
{
    /**
     * Create an HTTP response that represents the object.
     *
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        // The session was just renewed, so this flag reaches the next page.
        Inertia::clearHistory();

        return parent::toResponse($request);
    }
}
