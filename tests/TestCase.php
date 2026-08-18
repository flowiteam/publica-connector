<?php

namespace Flowiteam\PublicaConnector\Tests;

use Flowiteam\PublicaConnector\PublicaConnectorServiceProvider;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected string $token = 'a-shared-secret';

    protected function getPackageProviders($app): array
    {
        return [PublicaConnectorServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('publica.token', $this->token);
        $app['config']->set('database.default', 'testing');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    /**
     * A request signed the way PUBLICA signs it.
     *
     * The body is encoded here, once, and both the signature and the request
     * use that exact string. Re-encoding it anywhere in between is the bug this
     * whole helper exists to make impossible: the signature would be over a
     * different string than the one sent, and every call would 401 with nothing
     * visibly wrong on either side.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function signed(string $method, string $uri, array $payload = [], ?int $timestamp = null): TestResponse
    {
        $body = $payload === [] ? '' : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) ($timestamp ?? time());

        return $this->call(
            $method,
            $uri,
            [],
            [],
            [],
            $this->headers([
                'X-Publica-Timestamp' => $timestamp,
                'X-Publica-Signature' => hash_hmac('sha256', $timestamp.'.'.$body, $this->token),
            ]),
            $body === '' ? null : $body,
        );
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    protected function headers(array $headers): array
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

        foreach ($headers as $name => $value) {
            $server['HTTP_'.str_replace('-', '_', strtoupper($name))] = $value;
        }

        return $server;
    }

    /** @return array<string, mixed> */
    protected function article(array $overrides = []): array
    {
        return array_merge([
            'locale' => 'es',
            'type' => 'article',
            'title' => 'Cómo elegir un tueste',
            'slug' => 'como-elegir-un-tueste',
            'excerpt' => 'Un resumen.',
            'blocks' => [['type' => 'heading', 'content' => ['level' => 2, 'text' => 'El tueste']]],
            'html' => '<h2>El tueste</h2>',
            'seo' => ['title' => 'Cómo elegir un tueste'],
            'og' => [],
            'schema_org' => [],
            'published_at' => '2026-08-18T10:00:00+00:00',
            'status' => 'draft',
            'source' => ['document_id' => 42, 'brand' => 'Demo agency'],
        ], $overrides);
    }
}
