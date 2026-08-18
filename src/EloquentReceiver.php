<?php

namespace Flowiteam\PublicaConnector;

use Flowiteam\PublicaConnector\Contracts\ReceivesDocuments;
use Illuminate\Database\Eloquent\Model;

/**
 * The default: a payload becomes a row, through the field map in the config.
 *
 * This is what makes `composer require` plus a token a working destination. A
 * site with its own `Post` model points `publica.model` at it and lists which
 * incoming field lands in which column; a site with nothing at all gets the
 * package's own table and has to decide nothing.
 *
 * Only mapped fields are written. A field PUBLICA adds to the payload next year
 * is ignored here rather than throwing, so an upgrade on our side is not a
 * broken site on theirs.
 */
class EloquentReceiver implements ReceivesDocuments
{
    /** @param  array<string, mixed>  $payload */
    public function store(array $payload): array
    {
        $model = $this->newModel();

        $model->fill($this->attributes($payload))->save();

        return $this->answer($model);
    }

    /** @param  array<string, mixed>  $payload */
    public function update(string $id, array $payload): array
    {
        $model = $this->newModel()->newQuery()->findOrFail($id);

        $model->fill($this->attributes($payload))->save();

        return $this->answer($model);
    }

    /**
     * Off the site, not out of the database.
     *
     * Withdrawal is reversible everywhere else in PUBLICA, and a customer who
     * pressed "withdraw" once by accident should be able to press "publish"
     * again — which they cannot if the row is gone. A site that wants it gone
     * implements {@see ReceivesDocuments} itself.
     */
    public function withdraw(string $id): void
    {
        $model = $this->newModel()->newQuery()->find($id);

        if ($model === null) {
            return;
        }

        $column = (string) (config('publica.map.status') ?: 'status');

        if ($column !== '' && $this->hasColumn($model, $column)) {
            $model->forceFill([$column => 'withdrawn'])->save();

            return;
        }

        $model->delete();
    }

    /**
     * The payload, reduced to this site's columns.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function attributes(array $payload): array
    {
        $attributes = [];

        foreach ((array) config('publica.map', []) as $field => $column) {
            if (! is_string($column) || $column === '' || ! array_key_exists($field, $payload)) {
                continue;
            }

            $attributes[$column] = $payload[$field];
        }

        return $attributes;
    }

    /** @return array{id: string|int, url: string|null, status: string|null} */
    protected function answer(Model $model): array
    {
        $statusColumn = (string) (config('publica.map.status') ?: 'status');

        return [
            'id' => $model->getKey(),
            'url' => $this->url($model),
            'status' => $this->hasColumn($model, $statusColumn) ? (string) $model->getAttribute($statusColumn) : null,
        ];
    }

    /**
     * Where a reader would find this article.
     *
     * Built from a route name, because a closure in the config file cannot
     * survive `config:cache` and every production site runs it. When no route
     * is named — a site still wiring up its front end — the answer is null,
     * and PUBLICA shows the article as published without a link rather than
     * showing a link that 404s.
     */
    protected function url(Model $model): ?string
    {
        $route = config('publica.url.route');

        if (blank($route) || ! app('router')->has($route)) {
            return null;
        }

        $parameter = (string) config('publica.url.parameter', 'slug');
        $value = $this->hasColumn($model, $parameter) ? $model->getAttribute($parameter) : $model->getKey();

        return route($route, [$parameter => $value]);
    }

    protected function newModel(): Model
    {
        $class = (string) config('publica.model', Models\PublicaDocument::class);

        return new $class;
    }

    protected function hasColumn(Model $model, string $column): bool
    {
        return $column !== '' && array_key_exists($column, $model->getAttributes());
    }
}
