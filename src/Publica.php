<?php

namespace Flowiteam\PublicaConnector;

use Flowiteam\PublicaConnector\Jobs\ReportChange;
use Illuminate\Database\Eloquent\Model;

/**
 * Telling PUBLICA that an article changed on this site.
 *
 * Called for you when `publica.watch` is on and the article is an Eloquent
 * model. A site that stores articles some other way calls these directly at
 * the moments it considers meaningful.
 *
 * The one thing this class exists to get right is not reporting our own
 * writes. An article arriving from PUBLICA is saved through the same model as
 * an editor's change, and reporting it would send PUBLICA a notification about
 * the thing PUBLICA just did — which it would then act on, writing again. That
 * is not a subtle bug, it is an infinite loop between two servers, and the
 * only reliable place to stop it is at the source: {@see silently()} wraps
 * every write the connector itself performs.
 */
class Publica
{
    /** True while the connector is writing, so the observer keeps quiet. */
    protected static bool $silent = false;

    /**
     * Run a write without reporting it back.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function silently(callable $callback): mixed
    {
        $was = static::$silent;
        static::$silent = true;

        try {
            return $callback();
        } finally {
            static::$silent = $was;
        }
    }

    public static function isSilent(): bool
    {
        return static::$silent;
    }

    /** Configured to talk back at all. */
    public static function reports(): bool
    {
        return filled(config('publica.callback.url')) && filled(config('publica.callback.secret'));
    }

    /** An article on this site was edited by somebody here. */
    public static function changed(Model $article): void
    {
        static::send('updated', $article->getKey(), static::describe($article));
    }

    /**
     * An article is gone from this site.
     *
     * Takes the key rather than the model: by the time a site knows it wants to
     * report a deletion, the row it would have described is often already gone.
     */
    public static function removed(string|int $id): void
    {
        static::send('deleted', $id, []);
    }

    /** @param  array<string, mixed>  $details */
    protected static function send(string $event, string|int $id, array $details): void
    {
        if (static::$silent || ! static::reports()) {
            return;
        }

        ReportChange::dispatch($event, (string) $id, $details)
            ->onQueue(config('publica.callback.queue') ?: null);
    }

    /**
     * What PUBLICA is told about the article, in its own vocabulary.
     *
     * The hash is the point of the rest of it. PUBLICA keeps the hash of what
     * it last sent; equal means the site is reporting a save that changed
     * nothing it cares about — a view counter, a touched timestamp — and it can
     * be dropped without asking a person about it.
     *
     * @return array<string, mixed>
     */
    protected static function describe(Model $article): array
    {
        $map = (array) config('publica.map', []);
        $value = fn (string $field) => isset($map[$field]) ? $article->getAttribute($map[$field]) : null;

        $content = [
            'title' => $value('title'),
            'html' => $value('html'),
            'blocks' => $value('blocks'),
            'status' => $value('status'),
        ];

        return [
            'title' => $content['title'],
            'status' => $content['status'],
            'url' => app(EloquentReceiver::class)->publicUrl($article),
            'hash' => hash('sha256', json_encode($content, JSON_UNESCAPED_UNICODE)),
        ];
    }
}
