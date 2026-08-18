# PUBLICA connector

Receive articles from [PUBLICA](https://publica.flowiteam.com) into a Laravel
site: four signed routes, no admin user, no plugin, and nothing else opened to
the outside.

```bash
composer require flowiteam/publica-connector
php artisan migrate
```

Then one line in `.env`:

```ini
PUBLICA_TOKEN=a-long-random-string
```

Paste the same string into the channel in PUBLICA, press **Test connection**,
and the site is a publishing destination. That is the whole integration on a
site that has no articles table yet — the package brings its own.

## What it adds

| Route | What it does |
|---|---|
| `GET /publica/v1/ping` | Answers with the version and what this site can do. This is what **Test connection** calls |
| `POST /publica/v1/documents` | A new article. Answers `{id, url, status}` |
| `PUT /publica/v1/documents/{id}` | Edits the one with that id |
| `DELETE /publica/v1/documents/{id}` | Takes it off the site |

Nothing else. The routes run on the `api` middleware group and are not part of
your site's session, so no article that arrives opens a session for nobody.

## Into your own model

Most sites already have somewhere for articles to live. Publish the config and
point the package at it:

```bash
php artisan vendor:publish --tag=publica-config
```

```php
'model' => App\Models\Post::class,

'map' => [
    'title'        => 'title',
    'slug'         => 'slug',
    'excerpt'      => 'summary',
    'html'         => 'body',
    'blocks'       => 'blocks',
    'status'       => 'status',
    'published_at' => 'published_at',
],

'url' => [
    'route'     => 'blog.show',
    'parameter' => 'slug',
],
```

Only mapped fields are written, so a field PUBLICA adds later is ignored rather
than breaking your site. Cast `blocks`, `seo`, `og` and `schema_org` to `array`
on your model — they arrive as arrays.

Skip the package's own migration if you are not using its table.

### The article, in two shapes

Every article arrives as both `blocks` and `html`:

```json
{
  "title": "Choosing a roast",
  "slug": "choosing-a-roast",
  "locale": "en",
  "blocks": [{"type": "heading", "content": {"level": 2, "text": "Light roasts"}}],
  "html": "<h2>Light roasts</h2>…",
  "seo": {}, "og": {}, "schema_org": {},
  "status": "draft",
  "published_at": "2026-08-18T10:00:00+00:00",
  "source": {"document_id": 42, "brand": "Acme Coffee"}
}
```

Render the blocks with your own components if you have them; take `html` if you
do not. Sending both costs a few kilobytes and saves negotiating a version.

## When the mapping is not enough

Implement the contract and name your class in `publica.receiver`:

```php
use Flowiteam\PublicaConnector\Contracts\ReceivesDocuments;

class ArticleReceiver implements ReceivesDocuments
{
    public function store(array $payload): array
    {
        $post = Post::create([...]);

        return ['id' => $post->id, 'url' => route('blog.show', $post), 'status' => $post->status];
    }

    public function update(string $id, array $payload): array { /* … */ }

    public function withdraw(string $id): void { /* … */ }
}
```

The `id` you return is what PUBLICA stores and addresses every later update to,
so key articles however you like — just answer consistently.

## Authentication

Every request carries two headers:

```
X-Publica-Timestamp: 1755082800
X-Publica-Signature: hash_hmac('sha256', "{timestamp}.{raw body}", $token)
```

The timestamp is inside the signature, so a request captured once cannot be
replayed later with its signature intact. Requests older than five minutes are
refused (`publica.tolerance`), which means the two machines need roughly
correct clocks.

A site with no `PUBLICA_TOKEN` refuses everything. An unconfigured connector is
not an open one.

## Telling PUBLICA about edits made here

Off until you give it somewhere to report to. Two more lines in `.env`, both
shown on the channel screen in PUBLICA:

```ini
PUBLICA_CALLBACK_URL=https://publica.flowiteam.com/connector/v1/channels/42/events
PUBLICA_CALLBACK_SECRET=the-webhook-secret-from-that-screen
```

That secret is **not** the publishing token. Two directions, two secrets: the
one this site holds only lets it report its own changes, so leaking it does not
let anybody publish here.

With those set, the package watches whatever `publica.model` points at. Somebody
edits an article in your admin, and PUBLICA hears about it:

```json
{
  "event": "updated",
  "id": "42",
  "title": "Choosing a roast",
  "status": "publish",
  "url": "https://your-site.test/blog/choosing-a-roast",
  "hash": "9f2c…",
  "occurred_at": "2026-08-18T21:14:00+00:00"
}
```

Deleting one sends `{"event": "deleted", "id": "42"}`. Signed the same way
PUBLICA signs its own requests — `hash_hmac('sha256', "{timestamp}.{body}",
$secret)` — so a captured report cannot be replayed later.

The `hash` covers the title, body, blocks and status. PUBLICA keeps the hash of
what it last sent, and an equal hash means this site saved something it does not
care about — a view counter, a touched timestamp — which it drops without
bothering anybody.

**PUBLICA's own writes are never reported back.** An article arriving here is
saved through the same model as an editor's change, and reporting that would
tell PUBLICA about the thing PUBLICA just did, which it would act on by writing
again. Every write the connector performs runs inside `Publica::silently()`.

Reports go through the queue, so an editor pressing Save never waits for another
server and never sees an error page because that server is down. Set
`PUBLICA_CALLBACK_QUEUE` to put them on a queue of their own.

### Reporting by hand

A site that stores articles some other way, or would rather choose the moments
itself, turns the watcher off and calls it:

```php
'watch' => false,
```

```php
use Flowiteam\PublicaConnector\Publica;

Publica::changed($article);      // an edit worth telling PUBLICA about
Publica::removed($article->id);  // it is gone from this site
```

## What it does not do

- **No media upload yet.** Images arrive as URLs on the PUBLICA side; the
  `media` capability is off, and PUBLICA does not offer what this says it
  cannot do.
- **Withdrawal does not delete.** The default receiver sets the status to
  `withdrawn`, because withdrawal is reversible everywhere else in PUBLICA and
  somebody who pressed it by accident has to be able to publish again.
- **It reports, it does not merge.** Sending a change is this end's whole job.
  What PUBLICA does with it — show a diff, ask a person, ignore it — is decided
  over there, and deliberately: nothing here overwrites an article in PUBLICA.

## Testing it locally

The package runs as a real site:

```bash
composer install
vendor/bin/testbench migrate --force
PUBLICA_TOKEN=integration-secret vendor/bin/testbench serve --port=8124
```

Point a PUBLICA channel at `http://127.0.0.1:8124` with the same token.

```bash
vendor/bin/phpunit
```

## Requirements

PHP 8.2+, Laravel 12.

Laravel 11 is not supported: every 11.x release is under an unpatched security
advisory, and composer refuses to install one. A site still on 11 should be
upgraded before it is given a publishing endpoint.

## Licence

MIT © [flowITeam](https://flowiteam.com)
