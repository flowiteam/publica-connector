<?php

namespace Flowiteam\PublicaConnector\Http\Controllers;

use Flowiteam\PublicaConnector\Contracts\ReceivesMedia;
use Flowiteam\PublicaConnector\MediaRefused;
use Flowiteam\PublicaConnector\Publica;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * The pictures, arriving before the article that points at them.
 *
 * **Why the file comes as base64 inside JSON rather than as a multipart
 * upload.** Every request to this connector is signed over the raw body, and
 * the signature is the only thing standing between this site and the open
 * internet. A multipart body is assembled by the HTTP client with a boundary
 * PUBLICA does not choose and cannot predict, so signing it would mean either
 * hand-building the body on our side or moving the filename into headers and
 * out from under the signature — a request whose *name* an attacker could
 * change while the signature still verified. One signing rule for every route
 * is worth a third more bytes on the wire.
 *
 * That third is why `media.max_bytes` exists and defaults below the 8M that
 * PHP's own `post_max_size` ships with: a file that dies inside the web server
 * never reaches this class and refuses with nothing a person can read.
 */
class MediaController
{
    public function __construct(protected ReceivesMedia $store) {}

    public function __invoke(Request $request): JsonResponse
    {
        [$bytes, $filename, $alt] = $this->validated($request);

        try {
            $answer = Publica::silently(fn () => $this->store->storeMedia($bytes, $filename, $alt));
        } catch (MediaRefused $e) {
            // The site's own rule, in the site's own words. PUBLICA repeats it
            // to whoever published the article.
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::error('publica-connector: '.$e->getMessage(), ['exception' => $e]);

            return response()->json([
                'message' => 'This site could not store the file. Its log has the reason.',
            ], 500);
        }

        return response()->json([
            'id' => $answer['id'],
            'url' => $answer['url'],
        ], 201);
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    protected function validated(Request $request): array
    {
        try {
            $request->validate([
                'filename' => ['required', 'string', 'max:255'],
                'data' => ['required', 'string'],
                'alt' => ['nullable', 'string'],
            ]);
        } catch (ValidationException $e) {
            abort(response()->json([
                'message' => 'The file was refused: '.$e->validator->errors()->first(),
                'errors' => $e->errors(),
            ], 422));
        }

        // Strict, because base64_decode() otherwise skips whatever it does not
        // recognise and cheerfully returns a shorter, corrupt file - a picture
        // that stores without an error and renders as a broken icon.
        $bytes = base64_decode((string) $request->input('data'), true);

        if (! is_string($bytes) || $bytes === '') {
            abort(response()->json([
                'message' => 'The file did not arrive as valid base64.',
            ], 422));
        }

        $limit = (int) config('publica.media.max_bytes', 6 * 1024 * 1024);

        if ($limit > 0 && strlen($bytes) > $limit) {
            abort(response()->json([
                'message' => 'The file is larger than this site accepts ('
                    .round($limit / 1024 / 1024, 1).' MB).',
            ], 422));
        }

        return [$bytes, (string) $request->input('filename'), (string) $request->input('alt', '')];
    }
}
