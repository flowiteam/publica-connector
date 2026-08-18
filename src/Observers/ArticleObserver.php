<?php

namespace Flowiteam\PublicaConnector\Observers;

use Flowiteam\PublicaConnector\Publica;
use Illuminate\Database\Eloquent\Model;

/**
 * Somebody edited the article in this site's own admin.
 *
 * Registered on whatever `publica.model` points at, so the site does not have
 * to remember to tell us — remembering is exactly what nobody does, and an
 * integration that only works when it is thought about is an integration that
 * silently stops working.
 *
 * `Publica::changed()` drops the report when the connector itself is the one
 * writing, which is what keeps two servers from notifying each other forever.
 */
class ArticleObserver
{
    public function saved(Model $article): void
    {
        Publica::changed($article);
    }

    public function deleted(Model $article): void
    {
        /*
         * The key, not the model. PUBLICA addresses articles by the id this
         * connector gave it, and after a delete the rest of the row is not
         * something to describe — it is gone, and that is the whole message.
         */
        Publica::removed($article->getKey());
    }
}
