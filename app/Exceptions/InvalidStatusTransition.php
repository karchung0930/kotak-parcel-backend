<?php

namespace App\Exceptions;

use App\Enums\OrderStatus;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\Request;
use Inertia\Inertia;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown when an order cannot move to the requested status, e.g. paying twice.
 *
 * This is an expected business outcome, so it is not logged. JSON clients get
 * a 409 Conflict; Inertia pages are sent back with an error and a toast.
 */
class InvalidStatusTransition extends RuntimeException implements ShouldntReport
{
    /**
     * Create an exception for a transition the status workflow does not allow.
     */
    public static function between(OrderStatus $from, OrderStatus $to): self
    {
        return new self(sprintf(
            'This parcel is currently "%s" and cannot be changed to "%s".',
            $from->label(),
            $to->label(),
        ));
    }

    /**
     * Render the exception into an HTTP response.
     */
    public function render(Request $request): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], Response::HTTP_CONFLICT);
        }

        Inertia::flash('toast', ['type' => 'error', 'message' => $this->getMessage()]);

        return back()->withErrors(['status' => $this->getMessage()]);
    }
}
