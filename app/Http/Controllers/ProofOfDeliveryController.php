<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProofOfDeliveryController extends Controller
{
    /**
     * Stream the latest proof of delivery photo from private storage.
     */
    public function __invoke(Order $order): StreamedResponse
    {
        Gate::authorize('viewProof', $order);

        $path = $order->deliveryAttempts()->whereNotNull('photo_path')->latest('id')->value('photo_path');

        abort_unless(is_string($path) && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, headers: [
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
