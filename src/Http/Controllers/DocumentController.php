<?php

namespace Flowiteam\PublicaConnector\Http\Controllers;

use Flowiteam\PublicaConnector\Contracts\ReceivesDocuments;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * The three writing routes. Everything they do is handed to a receiver.
 *
 * The controller validates the shape and answers in the shape PUBLICA reads;
 * what an article *becomes* on this site is the receiver's business, because
 * that is the part every site does differently.
 *
 * Failures answer in JSON with a sentence somebody can act on. PUBLICA shows
 * `message` to the customer verbatim, so "SQLSTATE[23000]" reaches a person
 * who runs a coffee shop — hence the deliberate 500 body below.
 */
class DocumentController
{
    public function __construct(protected ReceivesDocuments $receiver) {}

    public function store(Request $request): JsonResponse
    {
        $payload = $this->validated($request);

        return $this->attempt(fn () => $this->receiver->store($payload), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $payload = $this->validated($request);

        return $this->attempt(fn () => $this->receiver->update($id, $payload));
    }

    public function destroy(string $id): JsonResponse
    {
        return $this->attempt(function () use ($id) {
            $this->receiver->withdraw($id);

            return null;
        }, 204);
    }

    /**
     * The parts of the payload this end insists on.
     *
     * Loose on purpose beyond the title: PUBLICA sends fields this version has
     * never heard of, and a site that rejected the payload for carrying one
     * would break the day PUBLICA ships a feature.
     *
     * @return array<string, mixed>
     */
    protected function validated(Request $request): array
    {
        try {
            $request->validate([
                'title' => ['required', 'string'],
                'slug' => ['nullable', 'string'],
                'locale' => ['nullable', 'string', 'max:5'],
                'blocks' => ['nullable', 'array'],
                'html' => ['nullable', 'string'],
                'status' => ['nullable', 'string'],
                'published_at' => ['nullable', 'date'],
            ]);
        } catch (ValidationException $e) {
            abort(response()->json([
                'message' => 'The article was refused: '.$e->validator->errors()->first(),
                'errors' => $e->errors(),
            ], 422));
        }

        return $request->all();
    }

    /**
     * @param  callable(): (array{id: string|int, url: string|null, status: string|null}|null)  $work
     */
    protected function attempt(callable $work, int $status = 200): JsonResponse
    {
        try {
            $answer = $work();
        } catch (ModelNotFoundException) {
            /*
             * PUBLICA is updating something this site no longer has — deleted
             * by hand, or restored from a backup taken before it arrived. A 404
             * says which of the two ends is out of step; a 500 would send
             * PUBLICA into its retry backoff for an article that is never
             * coming back.
             */
            return response()->json([
                'message' => 'This site has no article with that id. It was probably deleted here.',
            ], 404);
        } catch (\Throwable $e) {
            // The site's own logs get the exception; PUBLICA gets a sentence.
            Log::error('publica-connector: '.$e->getMessage(), ['exception' => $e]);

            return response()->json([
                'message' => 'This site could not store the article. Its log has the reason.',
            ], 500);
        }

        return $answer === null
            ? response()->json(null, $status)
            : response()->json($answer, $status);
    }
}
