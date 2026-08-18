<?php

namespace Flowiteam\PublicaConnector\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The only thing standing between this site and the open internet.
 *
 * PUBLICA signs `{timestamp}.{raw body}` with the shared token. The timestamp
 * is inside the signature on purpose: signing the body alone would mean a
 * request captured once could be replayed a year later, still correctly
 * signed, and re-publish an article the customer withdrew.
 *
 * Three rules, and none of them is decoration:
 *
 * - **The raw body, not the parsed one.** `json_encode($request->all())` is a
 *   different string from what was sent — key order, unicode escaping, float
 *   formatting — and the signature would fail on payloads that are perfectly
 *   valid.
 * - **`hash_equals`, not `===`.** String comparison returns early on the first
 *   wrong byte, and the time it takes leaks how much of the signature was
 *   right.
 * - **No token, no service.** An unconfigured connector refuses everything
 *   rather than accepting anything.
 */
class VerifyPublicaSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) config('publica.token');

        if ($token === '') {
            return response()->json([
                'message' => 'This site has no PUBLICA_TOKEN configured, so nothing can be published to it yet.',
            ], 401);
        }

        $timestamp = (string) $request->header('X-Publica-Timestamp', '');
        $signature = (string) $request->header('X-Publica-Signature', '');

        if ($timestamp === '' || $signature === '') {
            return response()->json(['message' => 'The request is not signed.'], 401);
        }

        $tolerance = (int) config('publica.tolerance', 300);

        if (! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > $tolerance) {
            return response()->json([
                'message' => 'The signature has expired. The clocks on the two machines are more than '
                    .$tolerance.' seconds apart.',
            ], 401);
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $token);

        if (! hash_equals($expected, $signature)) {
            return response()->json([
                'message' => 'The signature does not match. The token on this site and the one on the PUBLICA channel are different.',
            ], 401);
        }

        return $next($request);
    }
}
