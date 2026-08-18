<?php

namespace Flowiteam\PublicaConnector\Tests;

use Flowiteam\PublicaConnector\Models\PublicaDocument;

/**
 * The middleware is the only thing between this site and the internet, so it
 * is tested as the security control it is rather than as a formality.
 */
class SignatureTest extends TestCase
{
    public function test_a_signed_request_is_answered(): void
    {
        $this->signed('GET', '/publica/v1/ping')
            ->assertOk()
            ->assertJsonPath('version', 'v1')
            ->assertJsonPath('capabilities.publish', true);
    }

    public function test_an_unsigned_request_is_not(): void
    {
        $this->getJson('/publica/v1/ping')->assertUnauthorized();
    }

    public function test_a_wrong_token_is_refused_with_a_sentence_that_names_the_cause(): void
    {
        $response = $this->call('GET', '/publica/v1/ping', [], [], [], $this->headers([
            'X-Publica-Timestamp' => (string) time(),
            'X-Publica-Signature' => hash_hmac('sha256', time().'.', 'somebody-elses-secret'),
        ]));

        $response->assertUnauthorized();
        $this->assertStringContainsString('token', strtolower($response->json('message')));
    }

    /**
     * The timestamp is inside the signature, so a request captured today
     * cannot be replayed next week to re-publish an article that was
     * withdrawn.
     */
    public function test_a_captured_request_cannot_be_replayed_tomorrow(): void
    {
        $this->signed('POST', '/publica/v1/documents', $this->article(), timestamp: time() - 86400)
            ->assertUnauthorized();

        $this->assertSame(0, PublicaDocument::count());
    }

    /**
     * Signing the parsed body instead of the raw one is the classic version of
     * this bug: `json_encode($request->all())` reorders keys and escapes
     * unicode differently, and every request with an accent in it starts
     * failing for no visible reason.
     */
    public function test_the_signature_is_over_the_body_that_was_actually_sent(): void
    {
        $this->signed('POST', '/publica/v1/documents', $this->article([
            'title' => 'Precio del café — y por qué sube',
        ]))->assertCreated();
    }

    /**
     * A site where somebody installed the package and stopped there is not a
     * site that accepts anything.
     */
    public function test_a_connector_with_no_token_refuses_everything(): void
    {
        config()->set('publica.token', '');

        $this->signed('POST', '/publica/v1/documents', $this->article())->assertUnauthorized();
        $this->assertSame(0, PublicaDocument::count());
    }
}
