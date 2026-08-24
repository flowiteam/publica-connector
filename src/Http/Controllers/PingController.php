<?php

namespace Flowiteam\PublicaConnector\Http\Controllers;

use Flowiteam\PublicaConnector\Publica;
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
            'connector' => '1.3.0',
            'capabilities' => array_merge((array) config('publica.capabilities', []), [
                // Not a setting: either this site has been given somewhere to
                // report changes to, or it has not. PUBLICA reads this to know
                // whether an article edited here will ever be heard about, and
                // can say so on the channel screen rather than implying a
                // two-way link that is only half connected.
                'callback' => Publica::reports(),
            ]),
        ]);
    }
}
