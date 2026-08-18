<?php

namespace Flowiteam\PublicaConnector\Tests;

use Flowiteam\PublicaConnector\Models\PublicaDocument;
use Illuminate\Support\Facades\Route;

/**
 * What arrives, and what PUBLICA is told about it.
 *
 * The answer shape matters as much as the storing: `id` becomes
 * `publications.remote_id` on our side and every later update is addressed to
 * it, so a connector that stores the article perfectly and answers with the
 * wrong id has published an article nobody can ever edit again.
 */
class ReceivingTest extends TestCase
{
    public function test_an_article_arrives_and_the_answer_says_where_it_went(): void
    {
        Route::get('/blog/{slug}', fn () => '')->name('blog.show');
        config()->set('publica.url.route', 'blog.show');

        $response = $this->signed('POST', '/publica/v1/documents', $this->article())->assertCreated();

        $document = PublicaDocument::firstOrFail();

        $response
            ->assertJsonPath('id', $document->id)
            ->assertJsonPath('status', 'draft')
            ->assertJsonPath('url', url('/blog/como-elegir-un-tueste'));

        $this->assertSame('Cómo elegir un tueste', $document->title);
        $this->assertSame('<h2>El tueste</h2>', $document->html);
        $this->assertSame('El tueste', $document->blocks[0]['content']['text']);
    }

    /**
     * A site still building its front end has no route to name. It should be a
     * destination anyway — PUBLICA shows the article as published without a
     * link, which is true, instead of a link that 404s, which is not.
     */
    public function test_a_site_with_no_public_route_yet_still_accepts_articles(): void
    {
        $this->signed('POST', '/publica/v1/documents', $this->article())
            ->assertCreated()
            ->assertJsonPath('url', null);
    }

    public function test_an_update_edits_the_article_it_names(): void
    {
        $id = $this->signed('POST', '/publica/v1/documents', $this->article())->json('id');

        $this->signed('PUT', "/publica/v1/documents/{$id}", $this->article([
            'title' => 'Cómo elegir un tueste, revisado',
        ]))->assertOk()->assertJsonPath('id', $id);

        $this->assertSame(1, PublicaDocument::count(), 'the update created a second article');
        $this->assertSame('Cómo elegir un tueste, revisado', PublicaDocument::firstOrFail()->title);
    }

    /**
     * Withdrawal is reversible everywhere else in PUBLICA, and somebody who
     * pressed it by accident has to be able to press publish again.
     */
    public function test_withdrawing_takes_it_off_the_site_without_deleting_it(): void
    {
        $id = $this->signed('POST', '/publica/v1/documents', $this->article())->json('id');

        $this->signed('DELETE', "/publica/v1/documents/{$id}")->assertNoContent();

        $this->assertSame('withdrawn', PublicaDocument::findOrFail($id)->status);
    }

    /**
     * The article was deleted on the site by hand. PUBLICA is told which end is
     * out of step, rather than being sent into a retry backoff for something
     * that is never coming back.
     */
    public function test_updating_something_this_site_no_longer_has_is_a_404(): void
    {
        $this->signed('PUT', '/publica/v1/documents/999', $this->article())
            ->assertNotFound()
            ->assertJsonStructure(['message']);
    }

    /**
     * PUBLICA will add fields to this payload. A site that refused the whole
     * article because of one it had never heard of would break on our release
     * day, on somebody else's server.
     */
    public function test_a_field_this_version_never_heard_of_is_ignored(): void
    {
        $this->signed('POST', '/publica/v1/documents', $this->article([
            'reading_time' => 7,
            'benchmarks' => ['words' => ['min' => 1180]],
        ]))->assertCreated();

        $this->assertSame(1, PublicaDocument::count());
    }

    public function test_an_article_with_no_title_is_refused_with_a_readable_reason(): void
    {
        $response = $this->signed('POST', '/publica/v1/documents', $this->article(['title' => '']));

        $response->assertStatus(422);
        $this->assertStringContainsString('refused', $response->json('message'));
    }

    /**
     * The mapping is what lets a site keep its own schema. Nothing outside it
     * is written, so a column the site did not offer is never invented.
     */
    public function test_only_mapped_fields_are_written(): void
    {
        config()->set('publica.map', ['title' => 'title', 'slug' => 'slug']);

        $this->signed('POST', '/publica/v1/documents', $this->article())->assertCreated();

        $document = PublicaDocument::firstOrFail();

        $this->assertSame('Cómo elegir un tueste', $document->title);
        $this->assertNull($document->html, 'a field the site did not map was written anyway');
    }
}
