<?php

namespace Flowiteam\PublicaConnector\Http\Controllers;

use Flowiteam\PublicaConnector\Contracts\DescribesStructure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * What this site is made of, so PUBLICA can file an article instead of
 * dropping it in unsorted.
 *
 * Read-only and writes nothing, which is why it is allowed to be a GET like
 * `/ping`. The `locale` query parameter is outside the signature — a GET has
 * no body to sign — and that is acceptable here for one reason: the most an
 * altered parameter can do is narrow a read that a valid signature had already
 * authorised. Nothing about it decides what happens to anybody's data.
 *
 * A site that describes nothing answers with two empty lists rather than a
 * 404. "We looked and there is nothing to file into" is a fact PUBLICA can act
 * on; a missing route is a version negotiation.
 */
class StructureController
{
    public function __construct(protected DescribesStructure $structure) {}

    public function __invoke(Request $request): JsonResponse
    {
        $locale = (string) $request->query('locale', '');

        try {
            $described = $this->structure->describeStructure($locale !== '' ? $locale : null);
        } catch (\Throwable $e) {
            Log::error('publica-connector: '.$e->getMessage(), ['exception' => $e]);

            return response()->json([
                'message' => 'This site could not describe itself. Its log has the reason.',
            ], 500);
        }

        return response()->json([
            'terms' => $this->terms($described['terms'] ?? []),
            'authors' => $this->authors($described['authors'] ?? []),
        ]);
    }

    /**
     * @param  iterable<array<string, mixed>>  $terms
     * @return list<array<string, mixed>>
     */
    protected function terms(iterable $terms): array
    {
        $rows = [];

        foreach ($terms as $term) {
            // A term with no id or no name is one PUBLICA would store as a
            // blank line in a list somebody has to choose from.
            if (blank($term['remote_id'] ?? null) || blank($term['name'] ?? null)) {
                continue;
            }

            $rows[] = [
                'taxonomy' => (string) ($term['taxonomy'] ?? 'category'),
                'remote_id' => (string) $term['remote_id'],
                'name' => (string) $term['name'],
                'slug' => filled($term['slug'] ?? null) ? (string) $term['slug'] : null,
                'parent_remote_id' => filled($term['parent_remote_id'] ?? null)
                    ? (string) $term['parent_remote_id']
                    : null,
                'count' => (int) ($term['count'] ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * @param  iterable<array<string, mixed>>  $authors
     * @return list<array<string, mixed>>
     */
    protected function authors(iterable $authors): array
    {
        $rows = [];

        foreach ($authors as $author) {
            if (blank($author['remote_id'] ?? null) || blank($author['name'] ?? null)) {
                continue;
            }

            $rows[] = [
                'remote_id' => (string) $author['remote_id'],
                'name' => (string) $author['name'],
                'slug' => filled($author['slug'] ?? null) ? (string) $author['slug'] : null,
            ];
        }

        return $rows;
    }
}
