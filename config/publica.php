<?php

use Flowiteam\PublicaConnector\Models\PublicaDocument;

return [

    /*
     * The shared secret. Every request from PUBLICA is signed with it, and a
     * request that is not signed with it is not answered.
     *
     * Empty means the connector is off: the routes still exist and refuse
     * everything, which is the safe reading of "somebody installed the package
     * and has not configured it yet". Publishing to a site that silently
     * accepted unsigned requests would be worse than publishing nowhere.
     */
    'token' => env('PUBLICA_TOKEN'),

    /*
     * Where the four routes live. Change it only if something on the site
     * already owns `/publica` — PUBLICA builds its URLs from the site address
     * plus this prefix, so the channel in PUBLICA has to be told too.
     */
    'prefix' => env('PUBLICA_PREFIX', 'publica'),

    /*
     * Middleware the routes run through.
     *
     * Deliberately not `web`: there is no session, no cookie and no CSRF token
     * in a machine-to-machine call, and putting the group on would mean every
     * request from PUBLICA starts a session on the site for nobody.
     */
    'middleware' => ['api'],

    /*
     * How long a signed request stays valid. The signature covers the
     * timestamp, so a captured request cannot be replayed tomorrow — this is
     * how long "tomorrow" takes to arrive.
     */
    'tolerance' => 300,

    /*
     * What an article becomes on this site.
     *
     * The default is the package's own table, so `composer require` plus a
     * token is a working destination on a site that has nothing yet. A site
     * with its own Post model points this at it and maps the fields.
     */
    'model' => PublicaDocument::class,

    /*
     * Incoming field => column on that model. Anything not listed is ignored,
     * which is what keeps a new field in the payload from being a breaking
     * change for every site that installed this.
     *
     * `blocks`, `seo`, `og` and `schema_org` arrive as arrays; cast those
     * columns to `array` on the model or they will not survive the save.
     */
    'map' => [
        'title' => 'title',
        'slug' => 'slug',
        'excerpt' => 'excerpt',
        'html' => 'html',
        'blocks' => 'blocks',
        'locale' => 'locale',
        'status' => 'status',
        'published_at' => 'published_at',
        'seo' => 'seo',
        'og' => 'og',
        'schema_org' => 'schema_org',
    ],

    /*
     * The public address of a saved article, which PUBLICA stores and shows to
     * the customer as "it is live here".
     *
     * A route name rather than a closure: `config:cache` cannot serialise a
     * closure, and a site that caches its config in production — which is all
     * of them — would fail to boot. For anything this cannot express,
     * implement {@see \Flowiteam\PublicaConnector\Contracts\ReceivesDocuments}
     * and put the class in `receiver` below.
     */
    'url' => [
        'route' => env('PUBLICA_URL_ROUTE'),
        'parameter' => 'slug',
    ],

    /*
     * Full control, when the mapping above is not enough: a class implementing
     * `ReceivesDocuments` that takes the payload and does whatever this site
     * does — its own model, its own media handling, its own statuses.
     */
    'receiver' => null,

    /*
     * What this site can do, answered at /ping. PUBLICA trusts this over any
     * assumption of its own, so turn something off here and PUBLICA stops
     * offering it for this destination.
     */
    'capabilities' => [
        'publish' => true,
        'update' => true,
        'withdraw' => true,
        'schedule' => true,
        'blocks' => true,
        'media' => false,
    ],

];
