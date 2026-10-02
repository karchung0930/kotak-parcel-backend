<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * Send the user to the home page of their role.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        return to_route($request->user()->role->homeRoute());
    }
}
