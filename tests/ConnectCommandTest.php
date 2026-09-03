<?php

namespace Flowiteam\PublicaConnector\Tests;

use Illuminate\Support\Facades\File;

/**
 * The tail of the one-paste install: the command that writes .env so nobody
 * opens an editor over SSH.
 */
class ConnectCommandTest extends TestCase
{
    protected string $env;

    protected function setUp(): void
    {
        parent::setUp();

        $this->env = $this->app->environmentFilePath();
        File::put($this->env, "APP_NAME=Testbench\nPUBLICA_TOKEN=old-token\n");
    }

    protected function tearDown(): void
    {
        File::delete($this->env);

        parent::tearDown();
    }

    public function test_it_replaces_the_token_and_appends_the_callback_pair(): void
    {
        $this->artisan('publica:connect', [
            'token' => 'fresh-token',
            '--callback-url' => 'https://publica.example/connector/v1/channels/7/events',
            '--callback-secret' => 'hush hush',
        ])->assertSuccessful();

        $env = File::get($this->env);

        $this->assertStringContainsString('PUBLICA_TOKEN=fresh-token', $env);
        $this->assertStringNotContainsString('old-token', $env);
        $this->assertStringContainsString('PUBLICA_CALLBACK_URL=https://publica.example/connector/v1/channels/7/events', $env);
        // A value with a space is quoted; one without is not.
        $this->assertStringContainsString('PUBLICA_CALLBACK_SECRET="hush hush"', $env);
    }

    public function test_the_token_alone_leaves_the_callback_lines_out(): void
    {
        $this->artisan('publica:connect', ['token' => 'only-token'])->assertSuccessful();

        $env = File::get($this->env);

        $this->assertStringContainsString('PUBLICA_TOKEN=only-token', $env);
        $this->assertStringNotContainsString('PUBLICA_CALLBACK_URL', $env);
    }

    public function test_without_an_env_file_it_says_so_and_fails(): void
    {
        File::delete($this->env);

        $this->artisan('publica:connect', ['token' => 'x'])->assertFailed();
    }
}
