<?php

namespace Flowiteam\PublicaConnector\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * The cheapest call that proves the whole chain.
 *
 * "Test connection" in PUBLICA hits this: it reaches the site, the routes are
 * registered, and the signature checks out — three separate things that fail
 * in three different ways, answered by one request that writes nothing.
 *
 * The capabilities are the site's own answer about itself, and PUBLICA trusts
 * them over any assumption of its own. A site that cannot schedule says so
 * here, and the scheduling control stops being offered for that destination.
 */
class PingController
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'version' => 'v1',
            'connector' => '1.0.0',
            'capabilities' => (array) config('publica.capabilities', []),
        ]);
    }
}
