<?php

namespace Flowiteam\PublicaConnector\Contracts;

/**
 * The way out of the configured mapping.
 *
 * The field map in the config covers a site whose articles are rows with a
 * title and a body. Everything else — a site that files articles under a
 * section, one that runs its own slug rules, one that turns blocks into its own
 * components at save time — implements this instead and does whatever it does.
 *
 * The return shape is the contract PUBLICA reads: `id` goes into
 * `publications.remote_id` and is what every later update and withdrawal is
 * addressed to, `url` is shown to the customer as the live address, `status` is
 * repeated back to them as the state on this site.
 */
interface ReceivesDocuments
{
    /**
     * Store an article arriving for the first time.
     *
     * @param  array<string, mixed>  $payload
     * @return array{id: string|int, url: string|null, status: string|null}
     */
    public function store(array $payload): array;

    /**
     * Update the one already stored under `$id`.
     *
     * `$id` is whatever this connector returned when it was created, so a site
     * is free to key articles however it likes.
     *
     * @param  array<string, mixed>  $payload
     * @return array{id: string|int, url: string|null, status: string|null}
     */
    public function update(string $id, array $payload): array;

    /**
     * Take it off the site.
     *
     * Withdrawal is reversible everywhere else in PUBLICA — WordPress goes back
     * to draft rather than being deleted — and an implementation here should do
     * the same unless the site genuinely wants articles gone.
     */
    public function withdraw(string $id): void;
}
