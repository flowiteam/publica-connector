<?php

namespace Flowiteam\PublicaConnector\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Somewhere for articles to land on a site that has nowhere yet.
 *
 * A site with its own `Post` model should use it — point `publica.model` at it
 * and map the fields. This exists so that installing the package and setting a
 * token is already a destination that works, which is the difference between
 * "connect your site" taking a minute and taking an afternoon of somebody
 * else's development time.
 *
 * `blocks` is the structured article and `html` is the same thing rendered.
 * Both are kept: a site that grows its own block renderer later will want the
 * structure for articles it has already received.
 */
class PublicaDocument extends Model
{
    protected $table = 'publica_documents';

    protected $guarded = [];

    protected $casts = [
        'blocks' => 'array',
        'seo' => 'array',
        'og' => 'array',
        'schema_org' => 'array',
        'published_at' => 'datetime',
    ];
}
