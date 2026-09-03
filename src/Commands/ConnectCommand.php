<?php

namespace Flowiteam\PublicaConnector\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

/**
 * The last step of the one-paste install.
 *
 * PUBLICA's connect screen hands the site one command; this is its tail. It
 * writes the three lines the connector reads into `.env` - the publishing
 * token, and the callback pair when the screen provided one - so nobody
 * opens an editor over SSH to finish an integration that is otherwise two
 * composer lines.
 */
class ConnectCommand extends Command
{
    protected $signature = 'publica:connect
        {token : The publishing token - the same string entered in the PUBLICA channel}
        {--callback-url= : Where edits made on this site are reported}
        {--callback-secret= : The secret those reports are signed with}';

    protected $description = 'Write the PUBLICA connector settings into .env';

    public function handle(): int
    {
        $pairs = array_filter([
            'PUBLICA_TOKEN' => (string) $this->argument('token'),
            'PUBLICA_CALLBACK_URL' => (string) ($this->option('callback-url') ?? ''),
            'PUBLICA_CALLBACK_SECRET' => (string) ($this->option('callback-secret') ?? ''),
        ], fn (string $value) => $value !== '');

        $path = $this->laravel->environmentFilePath();

        if (! File::exists($path)) {
            $this->error('No .env file at '.$path.' - nothing to write into.');

            return self::FAILURE;
        }

        $env = File::get($path);

        foreach ($pairs as $key => $value) {
            $line = $key.'='.$this->quoted($value);

            // Replace the line where it exists, append where it does not; a
            // commented-out line is left alone rather than uncommented.
            if (preg_match('/^'.preg_quote($key, '/').'=.*$/m', $env)) {
                $env = preg_replace('/^'.preg_quote($key, '/').'=.*$/m', $line, $env);
            } else {
                $env = rtrim($env, "\n")."\n\n".$line;
            }

            $this->info($key.' written.');
        }

        File::put($path, rtrim($env, "\n")."\n");

        /*
         * A production site usually runs with the config cached, and a cached
         * config never rereads .env: without this the token is on disk and
         * the connector still answers 401.
         */
        if ($this->laravel->configurationIsCached()) {
            Artisan::call('config:cache');
            $this->info('Configuration cache rebuilt.');
        }

        $this->line('Done. Press "Test connection" in PUBLICA - or just wait, the channel screen checks by itself.');

        return self::SUCCESS;
    }

    /** Quote a value the way .env wants it: only when it needs it. */
    protected function quoted(string $value): string
    {
        return preg_match('/^[A-Za-z0-9_.:\/-]*$/', $value) ? $value : '"'.addslashes($value).'"';
    }
}
