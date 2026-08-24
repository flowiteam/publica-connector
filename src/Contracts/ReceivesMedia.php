<?php

namespace Flowiteam\PublicaConnector\Contracts;

use Flowiteam\PublicaConnector\DiskMediaStore;
use Flowiteam\PublicaConnector\MediaRefused;

/**
 * Where a picture lands on this site.
 *
 * Separate from {@see ReceivesDocuments} on purpose, and checked with
 * `instanceof` rather than being a fifth method on it: every site that
 * implemented the document contract before this existed would have stopped
 * working the day it grew a method, and those are somebody else's sites.
 *
 * A site with a media library of its own — one that wants an attachment row, a
 * thumbnail, an image it can reuse in its own admin — implements this and does
 * whatever it does. Everything else gets {@see DiskMediaStore},
 * which writes the file to a disk and answers with its address.
 */
interface ReceivesMedia
{
    /**
     * Store the bytes and say where a reader would find them.
     *
     * `storeMedia` rather than `store`, so that one class can implement this
     * and {@see ReceivesDocuments} at once - which is the whole point of the
     * shortcut in the service provider, and impossible if the two contracts
     * both want a method called `store` with different arguments.
     *
     * The URL must be **absolute**. It is written into the article's `src` on
     * this site and PUBLICA repeats it back to the customer as "the picture is
     * here"; a relative one means a different address in every context that
     * reads it.
     *
     * `id` is this site's own handle for the file and is only ever repeated
     * back — nothing addresses a later request to it today.
     *
     * Refusals — too large, a type this site will not hold — throw
     * {@see MediaRefused} with a sentence a person
     * can act on. PUBLICA shows it verbatim.
     *
     * @return array{id: string|int, url: string}
     */
    public function storeMedia(string $bytes, string $filename, string $alt = ''): array;
}
