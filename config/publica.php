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
     * Where an arriving picture is put.
     *
     * PUBLICA uploads the article's images and videos before it sends the
     * article, and the article then points at what this returns. Without it a
     * published post carries `src` back into PUBLICA's own storage - somebody
     * else's machine, serving this site's pictures until the day it moves a
     * file and stops.
     */
    'media' => [
        // Any disk this site already has. `public` is the one every Laravel
        // application ships with, and the one that needs `storage:link`.
        'disk' => env('PUBLICA_MEDIA_DISK', 'public'),

        // Inside that disk. Files land under `{path}/{year}/{month}/`.
        'path' => env('PUBLICA_MEDIA_PATH', 'publica'),

        /*
         * The biggest file this site will take, in bytes.
         *
         * Under PHP's default `post_max_size` of 8M on purpose: the file
         * travels base64-encoded, which is a third bigger than the file, and
         * anything over this limit dies inside the web server with an answer
         * no human being can read. Raise both together or neither.
         */
        'max_bytes' => 6 * 1024 * 1024,

        /*
         * Extensions this site will hold, and nothing else.
         *
         * This is a signed request writing a file into a publicly served
         * directory: a leaked token is bad, and a leaked token that can drop a
         * `.php` into `public/` is the whole server. `svg` is deliberately not
         * here either - it is a document that can carry script, not a picture.
         */
        'types' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'mp4', 'webm'],

        /*
         * Full control, the same way `receiver` gives it for articles: a class
         * implementing {@see \Flowiteam\PublicaConnector\Contracts\ReceivesMedia}
         * for a site with a media library of its own. A `receiver` that
         * implements that interface is used for files too, so a site that has
         * written one already needs nothing here.
         */
        'store' => null,
    ],

    /*
     * What this site is made of, answered at /structure.
     *
     * PUBLICA keeps a mirror of every destination's sections, labels and
     * bylines, and files each article by rules the customer set once - "the
     * roasting cluster goes in Coffee, ten a month under this byline". A site
     * that cannot be asked what it has gets articles that arrive unfiled and
     * wait for somebody.
     *
     * Empty by default, because the package's own table has no taxonomy at
     * all. Two lines here are usually the whole job.
     */
    'structure' => [

        /*
         * Taxonomy name => the model behind it.
         *
         * **`category` and `post_tag` are not free-form.** PUBLICA's rules are
         * keyed on the first and its tag matcher on the second; a site that
         * answers `sections` describes itself perfectly and gets nothing
         * filed. The names come from WordPress, where the mirror was first
         * built, and renaming them now would mean migrating stored rules on
         * every customer's installation.
         *
         * Columns are read from the table rather than assumed: `name` or
         * `title`, `slug` if there is one, `parent_id` for a tree. Name them
         * explicitly when the guess would be wrong.
         *
         *     'category' => ['model' => App\Models\Category::class],
         *     'post_tag' => ['model' => App\Models\Tag::class, 'counts' => 'posts'],
         */
        'taxonomies' => [],

        /*
         * Who can be the byline. PUBLICA divides a month between them by the
         * quotas somebody sets on its own screen - "ten under one, fifteen
         * under another" - so this list is people who may sign an article,
         * not every account on the site.
         *
         *     'authors' => ['model' => App\Models\Author::class],
         */
        'authors' => [],

        /*
         * The column that says which language a section belongs to, when this
         * site keeps a set per language. PUBLICA sends the language it is
         * publishing in and gets only that one back - otherwise an English
         * article is offered Ukrainian sections, and somebody files it into a
         * category its readers will never see.
         */
        'locale' => 'locale',

        /*
         * A ceiling per taxonomy, busiest first. A blog that collected nine
         * thousand labels over a decade is exactly the site somebody
         * connects, and what survives the cut should be the part of it people
         * actually use.
         */
        'limit' => 500,

        /*
         * Full control: a class implementing
         * {@see \Flowiteam\PublicaConnector\Contracts\DescribesStructure}.
         * A `receiver` that implements it is used for this too, so a site that
         * has written one already needs nothing here.
         */
        'provider' => null,
    ],

    /*
     * Telling PUBLICA that something changed here.
     *
     * The other direction, and the one that makes an article editable on the
     * site without the two copies drifting apart for good. Off until both
     * lines are set: a connector that has never been given a callback address
     * stays a one-way destination, which is exactly what it was before.
     *
     * `secret` is the channel's `webhook_secret` from PUBLICA - **not** the
     * publishing token. Two directions, two secrets: the site holds one that
     * only lets it report its own changes, and losing it does not let anybody
     * publish.
     *
     * Both are shown by PUBLICA on the channel screen when the destination is
     * created.
     */
    'callback' => [
        'url' => env('PUBLICA_CALLBACK_URL'),
        'secret' => env('PUBLICA_CALLBACK_SECRET'),

        // Reporting a change must never be what makes saving an article slow,
        // or what makes it fail. Queued when the site has a queue; a site on
        // the sync driver sends it inline and that is still fine.
        'queue' => env('PUBLICA_CALLBACK_QUEUE'),
    ],

    /*
     * Watch the configured model and report edits automatically.
     *
     * True is the whole point - somebody edits an article in the site's own
     * admin and PUBLICA finds out. A site whose articles are written by
     * machinery of its own may prefer to call `Publica::changed($model)` at
     * the moments it considers meaningful, and turns this off.
     */
    'watch' => true,

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
        // True because filing under a byline here is one column on the
        // receiving model, not a permission. WordPress needs
        // `edit_others_posts` and answers 201 without it while quietly filing
        // everything under the connected account - which is what this flag
        // exists to warn about, and what does not happen here. A site whose
        // receiver ignores `placement` describes no authors either, so the
        // question never reaches anybody.
        'other_authors' => true,
        'blocks' => true,

        // True since 1.2.0: the package receives files at /publica/v1/media
        // and puts them on `media.disk`. A site that would rather keep its
        // articles pointing at PUBLICA's storage turns this off and gets the
        // hotlink it asked for.
        'media' => true,
    ],

];
