<?php

namespace Flowiteam\PublicaConnector\Tests;

use Flowiteam\PublicaConnector\Jobs\ReportChange;
use Flowiteam\PublicaConnector\Models\PublicaDocument;
use Flowiteam\PublicaConnector\Publica;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * The other direction: an article edited on this site, reported to PUBLICA.
 *
 * One test here matters more than the rest. An article arriving from PUBLICA is
 * saved through the same model as an editor's change, so the naive version of
 * this feature reports PUBLICA's own write back to PUBLICA, which acts on it
 * and writes again. Two servers, notifying each other about one edit, forever.
 */
class ReportingBackTest extends TestCase
{
    protected string $secret = 'the-channels-webhook-secret';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('publica.callback.url', 'https://publica.example/connector/v1/channels/42/events');
        $app['config']->set('publica.callback.secret', $this->secret);
    }

    public function test_an_edit_made_here_is_reported(): void
    {
        Queue::fake();

        $article = PublicaDocument::create(['title' => 'Un tueste', 'slug' => 'un-tueste', 'status' => 'publish']);

        Queue::assertPushed(ReportChange::class, function (ReportChange $job) use ($article) {
            return $job->event === 'updated' && $job->id === (string) $article->id;
        });
    }

    public function test_a_deletion_is_reported(): void
    {
        // Faked before the row exists: creating it reports too, and a real
        // queue here would want a jobs table this site does not have.
        Queue::fake();

        $article = PublicaDocument::create(['title' => 'Un tueste', 'slug' => 'un-tueste']);
        $article->delete();

        Queue::assertPushed(ReportChange::class, fn (ReportChange $job) => $job->event === 'deleted'
            && $job->id === (string) $article->id);
    }

    /**
     * The loop. An article published from PUBLICA must not come straight back
     * as news.
     */
    public function test_what_publica_writes_is_not_reported_back_to_publica(): void
    {
        Queue::fake();

        $id = $this->signed('POST', '/publica/v1/documents', $this->article())->assertCreated()->json('id');

        $this->signed('PUT', "/publica/v1/documents/{$id}", $this->article(['title' => 'Revisado']))->assertOk();
        $this->signed('DELETE', "/publica/v1/documents/{$id}")->assertNoContent();

        Queue::assertNothingPushed();
    }

    /** And the suppression lifts afterwards, or the site goes quiet for good. */
    public function test_an_edit_after_a_publish_is_still_reported(): void
    {
        $id = $this->signed('POST', '/publica/v1/documents', $this->article())->json('id');

        Queue::fake();

        PublicaDocument::findOrFail($id)->update(['title' => 'Edited by a person']);

        Queue::assertPushed(ReportChange::class);
    }

    /** A site nobody gave a callback address stays what it was: one-way. */
    public function test_nothing_is_reported_when_there_is_nowhere_to_report_to(): void
    {
        config()->set('publica.callback.url', null);

        Queue::fake();

        PublicaDocument::create(['title' => 'Un tueste']);

        Queue::assertNothingPushed();
    }

    public function test_the_report_is_signed_the_way_publica_will_check_it(): void
    {
        Http::fake(['publica.example/*' => Http::response(['ok' => true])]);

        (new ReportChange('updated', '7', ['title' => 'Un tueste', 'hash' => 'abc']))->handle();

        Http::assertSent(function (Request $request) {
            $timestamp = $request->header('X-Publica-Timestamp')[0] ?? '';
            $signature = $request->header('X-Publica-Signature')[0] ?? '';

            $this->assertTrue(
                hash_equals(hash_hmac('sha256', $timestamp.'.'.$request->body(), $this->secret), $signature),
                'PUBLICA would refuse this signature',
            );

            $body = json_decode($request->body(), true);

            $this->assertSame('updated', $body['event']);
            $this->assertSame('7', $body['id']);
            $this->assertArrayHasKey('occurred_at', $body);

            return true;
        });
    }

    /**
     * The hash is what lets PUBLICA ignore a save that changed nothing it
     * cares about — a view counter, a touched timestamp — without asking a
     * person about it.
     */
    public function test_the_report_carries_a_hash_of_the_content(): void
    {
        Queue::fake();

        $article = PublicaDocument::create(['title' => 'Un tueste', 'html' => '<p>Uno</p>']);

        Queue::assertPushed(ReportChange::class, function (ReportChange $job) {
            $this->assertNotEmpty($job->details['hash'] ?? null);
            $this->assertSame('Un tueste', $job->details['title']);

            return true;
        });
    }

    /**
     * A wrong secret fails the same way forever, so it is logged rather than
     * retried until the queue gives up.
     */
    public function test_a_refused_report_is_not_retried_forever(): void
    {
        Http::fake(['publica.example/*' => Http::response(['message' => 'no'], 401)]);

        (new ReportChange('updated', '7'))->handle();

        $this->assertTrue(true, 'a 401 threw instead of being logged and dropped');
    }

    public function test_a_publica_outage_is_retried(): void
    {
        Http::fake(['publica.example/*' => Http::response('', 503)]);

        $this->expectException(RequestException::class);

        (new ReportChange('updated', '7'))->handle();
    }
}
