<?php

namespace Flowiteam\PublicaConnector\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * One report, sent to PUBLICA: this article changed here.
 *
 * A job rather than a call inside the save, for the ordinary reason — an
 * editor pressing Save on this site must not wait for another server, and must
 * not see an error page because that server is having an afternoon. On a site
 * with no queue configured this runs inline anyway, which is still better than
 * doing it inside the model event.
 *
 * Signed with `webhook_secret`, not with the publishing token: this direction
 * has its own secret, and a site that leaks the one it holds cannot be used to
 * publish anything.
 */
class ReportChange implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 300];

    /** @param  array<string, mixed>  $details */
    public function __construct(
        public string $event,
        public string $id,
        public array $details = [],
    ) {}

    public function handle(): void
    {
        $url = (string) config('publica.callback.url');
        $secret = (string) config('publica.callback.secret');

        if ($url === '' || $secret === '') {
            return;
        }

        $payload = array_merge([
            'event' => $this->event,
            'id' => $this->id,
            'occurred_at' => now()->toIso8601String(),
        ], $this->details);

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) now()->timestamp;

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'X-Publica-Timestamp' => $timestamp,
            // The same scheme PUBLICA uses coming the other way: the timestamp
            // is signed with the body, so a captured report cannot be replayed
            // next week to make PUBLICA think an article changed again.
            'X-Publica-Signature' => hash_hmac('sha256', $timestamp.'.'.$body, $secret),
        ])
            ->withUserAgent('publica-connector/1.1')
            ->acceptJson()
            ->timeout(15)
            ->send('POST', $url, ['body' => $body]);

        if ($response->failed()) {
            /*
             * Two failures that look the same and are not. A 401 is a wrong
             * secret and will fail identically forever, so retrying it is
             * noise; anything else may be PUBLICA restarting, and is worth
             * another go.
             */
            if ($response->status() === 401) {
                Log::warning('publica-connector: PUBLICA refused the change report — PUBLICA_CALLBACK_SECRET does not match the channel.');

                return;
            }

            $response->throw();
        }
    }
}
