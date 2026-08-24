<?php

namespace Flowiteam\PublicaConnector;

use Flowiteam\PublicaConnector\Contracts\DescribesStructure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The default: a site describes itself by naming its models.
 *
 * Most Laravel blogs are the same three tables under different names — a
 * sections table with a name, a slug and maybe a parent; a labels table; a
 * people table. Naming them in the config is two lines and needs no code,
 * which is the difference between "PUBLICA files our articles" being a
 * configuration change and being a ticket.
 *
 * A site whose taxonomy is anything less ordinary — terms in a pivot with a
 * type column, sections that live in a CMS, bylines that are really accounts —
 * implements {@see DescribesStructure} and answers for itself.
 *
 * Column names are read from the table rather than assumed, because the one
 * thing worse than not describing a site is describing it wrongly: a `name`
 * that is really `title` produces a mirror full of empty labels, and the
 * customer picks from a list of blanks.
 */
class ConfiguredStructure implements DescribesStructure
{
    /** @var array<string, bool> */
    protected array $columns = [];

    /** @return array{terms: list<array<string, mixed>>, authors: list<array<string, mixed>>} */
    public function describeStructure(?string $locale = null): array
    {
        return [
            'terms' => $this->terms($locale),
            'authors' => $this->authors($locale),
        ];
    }

    /** @return list<array<string, mixed>> */
    protected function terms(?string $locale): array
    {
        $rows = [];

        foreach ((array) config('publica.structure.taxonomies', []) as $taxonomy => $config) {
            $config = is_array($config) ? $config : ['model' => $config];
            $model = $this->model($config['model'] ?? null);

            if ($model === null) {
                continue;
            }

            $name = $this->pick($model, $config['name'] ?? null, ['name', 'title']);

            if ($name === null) {
                continue;
            }

            $slug = $this->pick($model, $config['slug'] ?? null, ['slug']);
            $parent = $this->pick($model, $config['parent'] ?? null, ['parent_id']);
            $counts = $this->countable($model, $config['counts'] ?? null);

            $query = $model->newQuery();

            if ($counts !== null) {
                $query->withCount($counts);
            }

            $this->onlyLocale($query, $model, $config['locale'] ?? null, $locale);

            /*
             * Busiest first, and a ceiling under it. A site that has collected
             * nine thousand labels over a decade is exactly the site somebody
             * connects, and what survives the cut should be the part of it
             * people actually use.
             */
            $counts !== null
                ? $query->orderByDesc($counts.'_count')->orderBy($name)
                : $query->orderBy($name);

            foreach ($query->limit($this->limit())->get() as $row) {
                $rows[] = [
                    'taxonomy' => (string) $taxonomy,
                    'remote_id' => (string) $row->getKey(),
                    'name' => (string) $row->getAttribute($name),
                    'slug' => $slug ? $row->getAttribute($slug) : null,
                    'parent_remote_id' => $parent && filled($row->getAttribute($parent))
                        ? (string) $row->getAttribute($parent)
                        : null,
                    'count' => $counts !== null ? (int) $row->getAttribute($counts.'_count') : 0,
                ];
            }
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    protected function authors(?string $locale): array
    {
        $config = (array) config('publica.structure.authors', []);
        $model = $this->model($config['model'] ?? null);

        if ($model === null) {
            return [];
        }

        $name = $this->pick($model, $config['name'] ?? null, ['name', 'title']);

        if ($name === null) {
            return [];
        }

        $slug = $this->pick($model, $config['slug'] ?? null, ['slug']);

        $query = $model->newQuery()->orderBy($name);

        $this->onlyLocale($query, $model, $config['locale'] ?? null, $locale);

        return $query->limit($this->limit())->get()->map(fn (Model $row) => [
            'remote_id' => (string) $row->getKey(),
            'name' => (string) $row->getAttribute($name),
            'slug' => $slug ? $row->getAttribute($slug) : null,
        ])->all();
    }

    /**
     * Sections in one language only, when the site keeps them that way.
     *
     * A blog that publishes in two languages has two sets of everything, and
     * offering an English article the Ukrainian sections is how a customer
     * files something into a category its readers will never see.
     */
    protected function onlyLocale(Builder $query, Model $model, ?string $column, ?string $locale): void
    {
        if (blank($locale)) {
            return;
        }

        $column = $this->pick($model, $column, [(string) config('publica.structure.locale', 'locale')]);

        if ($column !== null) {
            $query->where($column, $locale);
        }
    }

    protected function model(mixed $class): ?Model
    {
        return is_string($class) && class_exists($class) && is_a($class, Model::class, true)
            ? new $class
            : null;
    }

    /**
     * The configured column, or the first candidate the table actually has.
     *
     * @param  list<string>  $candidates
     */
    protected function pick(Model $model, ?string $configured, array $candidates): ?string
    {
        foreach ($configured ? [$configured] : $candidates as $column) {
            if ($column !== '' && $this->hasColumn($model, $column)) {
                return $column;
            }
        }

        return null;
    }

    /** A relation worth counting, or null. */
    protected function countable(Model $model, ?string $relation): ?string
    {
        $relation ??= 'posts';

        return method_exists($model, $relation) ? $relation : null;
    }

    protected function hasColumn(Model $model, string $column): bool
    {
        $key = $model::class.'.'.$column;

        return $this->columns[$key] ??= $model->getConnection()
            ->getSchemaBuilder()
            ->hasColumn($model->getTable(), $column);
    }

    protected function limit(): int
    {
        return max(1, (int) config('publica.structure.limit', 500));
    }
}
