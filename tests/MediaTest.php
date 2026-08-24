<?php

namespace Flowiteam\PublicaConnector\Tests;

use Flowiteam\PublicaConnector\Contracts\ReceivesDocuments;
use Flowiteam\PublicaConnector\Contracts\ReceivesMedia;
use Illuminate\Support\Facades\Storage;

/**
 * The pictures, which used not to travel at all.
 *
 * Until this route existed an article arrived here carrying `src` back into
 * PUBLICA's own storage: a hotlink out of this site's page into somebody
 * else's machine, serving these pictures until the day that machine moved a
 * file. Every Laravel site that installed the package had it, and it was found
 * by looking at a published article rather than by anything failing.
 *
 * So the tests below are about the two ways this can be broken while looking
 * fine: a file that stores but cannot be fetched, and a file that stores when
 * it should have been refused.
 */
class MediaTest extends TestCase
{
    /** A one-pixel WebP, so the bytes under test are a real file rather than a string. */
    protected function bytes(): string
    {
        return (string) base64_decode('UklGRhIAAABXRUJQVlA4TAYAAAAvAAAAAAfQ//73v/+BiOh/AAA=', true);
    }

    /** @param  array<string, mixed>  $overrides */
    protected function upload(array $overrides = [])
    {
        return $this->signed('POST', '/publica/v1/media', array_merge([
            'filename' => 'un tueste, medio.WEBP',
            'alt' => 'Una taza de café',
            'data' => base64_encode($this->bytes()),
        ], $overrides));
    }

    /**
     * A disk that is not `public`, because the symlink guard below is the one
     * test that wants the default and every other test wants a working site.
     */
    protected function onOwnDisk(): void
    {
        Storage::fake('media', ['url' => '/storage/media']);
        config()->set('publica.media.disk', 'media');
    }

    public function test_a_picture_is_stored_and_the_answer_says_where_a_reader_would_find_it(): void
    {
        $this->onOwnDisk();

        $response = $this->upload()->assertCreated();

        $path = $response->json('id');

        Storage::disk('media')->assertExists($path);
        $this->assertSame($this->bytes(), Storage::disk('media')->get($path));

        // Foldered by month, and named from the file rather than from whatever
        // was sent.
        $this->assertStringStartsWith('publica/'.date('Y/m').'/un-tueste-medio-', $path);
        $this->assertStringEndsWith('.webp', $path);

        /*
         * Absolute. This URL is written into the article's `src` on this site
         * and repeated back to PUBLICA as "the picture is here"; a relative one
         * means a different address in every context that reads it.
         */
        $this->assertSame(url('/storage/media/'.$path), $response->json('url'));
    }

    /**
     * The same photograph, published again next month, is the same file rather
     * than a second copy of it — the stored name carries a hash of the bytes,
     * so writing it twice writes the same path twice.
     */
    public function test_the_same_picture_sent_twice_does_not_become_two_files(): void
    {
        $this->onOwnDisk();

        $first = $this->upload()->json('id');
        $second = $this->upload()->json('id');

        $this->assertSame($first, $second);
        $this->assertCount(1, Storage::disk('media')->allFiles());
    }

    /**
     * And the other half of the same rule: two different pictures that happen
     * to be called `cover.webp` are two files. Naming by the name alone would
     * have the second article quietly replace the first one's picture.
     */
    public function test_two_different_pictures_with_one_name_do_not_overwrite_each_other(): void
    {
        $this->onOwnDisk();

        $first = $this->upload(['filename' => 'cover.webp'])->json('id');
        $second = $this->upload([
            'filename' => 'cover.webp',
            'data' => base64_encode($this->bytes().'and a different tail'),
        ])->json('id');

        $this->assertNotSame($first, $second);
        $this->assertCount(2, Storage::disk('media')->allFiles());
    }

    /**
     * This is a signed request writing a file into a publicly served
     * directory. A leaked token is bad; a leaked token that can drop a `.php`
     * into `public/` is the whole server.
     */
    public function test_a_type_this_site_does_not_hold_is_refused_by_name(): void
    {
        $this->onOwnDisk();

        $this->upload(['filename' => 'shell.php'])
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'This site does not accept ".php" files. It holds: jpg, jpeg, png, gif, webp, avif, mp4, webm.',
            );

        $this->assertEmpty(Storage::disk('media')->allFiles());
    }

    /** And a name that tries to climb out of the directory does not. */
    public function test_a_filename_cannot_walk_up_out_of_the_media_directory(): void
    {
        $this->onOwnDisk();

        $path = $this->upload(['filename' => '../../../../public/evil.webp'])->assertCreated()->json('id');

        $this->assertStringStartsWith('publica/', $path);
        $this->assertStringNotContainsString('..', $path);
    }

    /**
     * The message is the point, not the status. PUBLICA shows it verbatim to
     * whoever published the article, and "413" tells them nothing to do.
     */
    public function test_a_file_too_big_for_this_site_is_refused_in_words(): void
    {
        $this->onOwnDisk();
        config()->set('publica.media.max_bytes', 2 * 1024 * 1024);

        $this->upload(['data' => base64_encode(str_repeat('x', 3 * 1024 * 1024))])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The file is larger than this site accepts (2 MB).');

        $this->assertEmpty(Storage::disk('media')->allFiles());
    }

    /**
     * base64_decode() without the strict flag skips what it does not
     * recognise and returns a shorter, corrupt file — one that stores without
     * an error and renders as a broken icon.
     */
    public function test_something_that_is_not_base64_is_refused_rather_than_stored_corrupt(): void
    {
        $this->onOwnDisk();

        $this->upload(['data' => 'not base64 at all!!'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The file did not arrive as valid base64.');

        $this->assertEmpty(Storage::disk('media')->allFiles());
    }

    /**
     * A file nobody can fetch is not a stored file, and `storage:link` is the
     * step every deploy script forgets exactly once.
     *
     * The public path is moved somewhere empty rather than trusting whatever
     * the machine running the tests happens to have in `public/` — this
     * assertion is about a site without the link, and it has to mean that on
     * a developer's laptop that does have one.
     */
    public function test_a_site_with_no_storage_symlink_is_told_so_instead_of_storing_a_404(): void
    {
        Storage::fake('public', ['url' => '/storage']);
        config()->set('publica.media.disk', 'public');
        config()->set('filesystems.disks.public.driver', 'local');

        $this->app->usePublicPath($this->emptyPublicPath());

        $this->upload()
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'This site has no public/storage symlink, so an uploaded picture would not be reachable. '
                .'Run `php artisan storage:link` on the site.',
            );

        $this->assertEmpty(Storage::disk('public')->allFiles());
    }

    /**
     * And the same site once the link is there stores the file. `file_exists`
     * rather than `is_link`: on Windows the link is a junction, and PHP
     * answers false to `is_link` and `is_dir` alike for one — the tidier check
     * refuses uploads on a site that is set up perfectly well.
     */
    public function test_the_default_public_disk_stores_once_the_link_exists(): void
    {
        Storage::fake('public', ['url' => '/storage']);
        config()->set('publica.media.disk', 'public');
        config()->set('filesystems.disks.public.driver', 'local');

        $public = $this->emptyPublicPath();
        mkdir($public.'/storage');
        $this->app->usePublicPath($public);

        $path = $this->upload()->assertCreated()->json('id');

        Storage::disk('public')->assertExists($path);
    }

    /** A public directory of this test's own, emptied and removed with it. */
    protected function emptyPublicPath(): string
    {
        $path = sys_get_temp_dir().'/publica-connector-public-'.uniqid();

        mkdir($path, 0777, true);

        $this->beforeApplicationDestroyed(function () use ($path) {
            @rmdir($path.'/storage');
            @rmdir($path);
        });

        return $path;
    }

    /**
     * A site that already wrote a receiver for its articles and taught it to
     * hold pictures too needs no second line of configuration: implementing
     * the interface is how it says so.
     */
    public function test_a_receiver_that_holds_files_itself_is_used_for_them(): void
    {
        $this->onOwnDisk();
        config()->set('publica.receiver', ReceiverWithItsOwnLibrary::class);

        $this->upload()
            ->assertCreated()
            ->assertJsonPath('id', 'attachment-77')
            ->assertJsonPath('url', 'https://luna.example/media/77.webp');

        $this->assertEmpty(Storage::disk('media')->allFiles(), 'the default disk store ran as well');
    }

    /** The route is behind the same signature check as the other four. */
    public function test_an_unsigned_upload_is_refused(): void
    {
        $this->onOwnDisk();

        $this->postJson('/publica/v1/media', [
            'filename' => 'x.webp',
            'data' => base64_encode($this->bytes()),
        ])->assertUnauthorized();

        $this->assertEmpty(Storage::disk('media')->allFiles());
    }

    /**
     * PUBLICA uploads only to a destination that says it can hold files, so
     * this line is what turns the whole thing on for a site.
     */
    public function test_ping_says_this_site_holds_files(): void
    {
        $this->signed('GET', '/publica/v1/ping')
            ->assertOk()
            ->assertJsonPath('capabilities.media', true);
    }
}

/** A site with a media library of its own, answering for articles and files alike. */
class ReceiverWithItsOwnLibrary implements ReceivesDocuments, ReceivesMedia
{
    public function store(array $payload): array
    {
        return ['id' => 1, 'url' => null, 'status' => 'draft'];
    }

    public function update(string $id, array $payload): array
    {
        return ['id' => $id, 'url' => null, 'status' => 'draft'];
    }

    public function withdraw(string $id): void {}

    public function storeMedia(string $bytes, string $filename, string $alt = ''): array
    {
        return ['id' => 'attachment-77', 'url' => 'https://luna.example/media/77.webp'];
    }
}
