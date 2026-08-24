<?php

namespace Flowiteam\PublicaConnector;

use Flowiteam\PublicaConnector\Contracts\ReceivesMedia;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The default: a file becomes a file, on a disk this site already has.
 *
 * This is what makes `composer require` plus a token enough to receive an
 * article *with its pictures*. Before it, a picture arrived as a URL back into
 * PUBLICA's storage — a hotlink out of this site's article into somebody
 * else's machine, which breaks the day that file moves.
 *
 * Three things it insists on, and none of them is decoration:
 *
 * - **An extension allowlist.** This writes a file, from a signed request,
 *   into a publicly served directory. A token that leaks is bad; a token that
 *   leaks and can drop a `.php` into `public/` is the whole server. The list
 *   is the list, and anything not on it is refused.
 * - **The name is the content.** The stored name carries a hash of the bytes,
 *   so the same photograph sent twice lands on the same path instead of
 *   accumulating `-1`, `-2`, `-3` copies of itself.
 * - **The symlink is checked, not assumed.** A site that never ran
 *   `storage:link` stores the file perfectly and serves 404 for it, which is
 *   exactly the shape of failure this class exists to end.
 */
class DiskMediaStore implements ReceivesMedia
{
    /** @return array{id: string, url: string} */
    public function storeMedia(string $bytes, string $filename, string $alt = ''): array
    {
        $disk = (string) config('publica.media.disk', 'public');
        $path = $this->path($bytes, $filename);

        $this->assertServable($disk);

        Storage::disk($disk)->put($path, $bytes);

        return ['id' => $path, 'url' => $this->url($disk, $path)];
    }

    /**
     * `Un tueste, medio.WEBP` → `publica/2026/08/un-tueste-medio-3f9a1c22.webp`
     *
     * Foldered by month because a blog that publishes weekly for three years
     * is a directory with a few hundred files in it either way, and every
     * backup tool and every human being reads the dated one faster.
     */
    protected function path(string $bytes, string $filename): string
    {
        $extension = Str::lower((string) pathinfo($filename, PATHINFO_EXTENSION));
        $allowed = array_map('strval', (array) config('publica.media.types', []));

        if ($extension === '' || ! in_array($extension, $allowed, true)) {
            throw new MediaRefused(
                'This site does not accept ".'.$extension.'" files. It holds: '.implode(', ', $allowed).'.',
            );
        }

        $name = Str::slug(pathinfo($filename, PATHINFO_FILENAME)) ?: 'file';

        return implode('/', array_filter([
            trim((string) config('publica.media.path', 'publica'), '/'),
            date('Y/m'),
            $name.'-'.substr(hash('sha256', $bytes), 0, 8).'.'.$extension,
        ]));
    }

    /**
     * A file nobody can fetch is not a stored file.
     *
     * Only the local `public` disk can be wrong this way, and it is wrong this
     * way often: `storage:link` is a step every deploy script forgets once.
     * Said here rather than discovered later as a broken picture in a
     * published article.
     */
    protected function assertServable(string $disk): void
    {
        if ($disk !== 'public' || config('filesystems.disks.public.driver') !== 'local') {
            return;
        }

        /*
         * `file_exists`, not `is_link` or `is_dir`. On Windows `storage:link`
         * makes a junction rather than a symbolic link, and PHP answers false
         * to both of those for one - so the tidier-looking check refuses
         * uploads on a site that is set up perfectly well. What is actually
         * being asked here is whether anything is at that path at all.
         */
        if (! file_exists(public_path('storage'))) {
            throw new MediaRefused(
                'This site has no public/storage symlink, so an uploaded picture would not be reachable. '
                .'Run `php artisan storage:link` on the site.',
            );
        }
    }

    /**
     * Absolute, always.
     *
     * A local disk answers `/storage/…`, which is a different address
     * depending on who reads it — and this one is read by PUBLICA, by the feed,
     * and by whoever shares the article. S3 and friends already answer
     * absolutely and are left alone.
     */
    protected function url(string $disk, string $path): string
    {
        $url = Storage::disk($disk)->url($path);

        return Str::startsWith($url, ['http://', 'https://']) ? $url : url($url);
    }
}
