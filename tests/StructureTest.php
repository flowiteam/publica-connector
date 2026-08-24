<?php

namespace Flowiteam\PublicaConnector\Tests;

use Flowiteam\PublicaConnector\Contracts\DescribesStructure;
use Flowiteam\PublicaConnector\Contracts\ReceivesDocuments;
use Flowiteam\PublicaConnector\Models\PublicaDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The site, describing itself.
 *
 * PUBLICA files an article by rules a customer set once — this cluster goes in
 * that section, ten a month under that byline — and none of that can happen
 * for a destination it cannot ask what it has. Before this route existed an
 * article arrived here unfiled and waited for a person, which is fine for one
 * article and is the whole problem for thirty a month.
 *
 * What the tests below are actually protecting: that a site describes itself
 * *accurately*. A mirror full of blank names or of another language's sections
 * is worse than an empty one, because somebody then chooses from it.
 */
class StructureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('sections', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug');
            $table->string('locale', 5)->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
        });

        Schema::create('labels', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug');
        });

        Schema::create('writers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug');
        });

        Schema::table('publica_documents', function (Blueprint $table) {
            $table->unsignedBigInteger('section_id')->nullable();
        });
    }

    protected function describedSite(): void
    {
        config()->set('publica.structure.taxonomies', [
            'category' => ['model' => Section::class],
            'post_tag' => ['model' => Label::class],
        ]);
        config()->set('publica.structure.authors', ['model' => Writer::class]);
    }

    /** Two sections, one under the other, and one of them is where things go. */
    protected function sections(): void
    {
        $coffee = Section::create(['name' => 'Coffee', 'slug' => 'coffee', 'locale' => 'en']);
        Section::create(['name' => 'Equipment', 'slug' => 'equipment', 'locale' => 'en', 'parent_id' => $coffee->id]);
        Section::create(['name' => 'Кава', 'slug' => 'kava', 'locale' => 'uk']);

        Label::create(['name' => 'roasting', 'slug' => 'roasting']);
        Writer::create(['name' => 'flowITeam', 'slug' => 'flowiteam']);

        // Two articles in Coffee, none in Equipment: the busy one first.
        PublicaDocument::create(['title' => 'One', 'section_id' => $coffee->id]);
        PublicaDocument::create(['title' => 'Two', 'section_id' => $coffee->id]);
    }

    public function test_a_site_describes_its_sections_labels_and_bylines(): void
    {
        $this->describedSite();
        $this->sections();

        $response = $this->signed('GET', '/publica/v1/structure')->assertOk();

        $coffee = $response->json('terms.0');

        $this->assertSame('category', $coffee['taxonomy']);
        $this->assertSame('Coffee', $coffee['name']);
        $this->assertSame('coffee', $coffee['slug']);
        $this->assertNull($coffee['parent_remote_id']);
        // Busiest first, so a ceiling that cuts the list keeps the part of the
        // site people use.
        $this->assertSame(2, $coffee['count']);

        $equipment = collect($response->json('terms'))->firstWhere('name', 'Equipment');
        $this->assertSame($coffee['remote_id'], $equipment['parent_remote_id']);

        $this->assertSame('post_tag', collect($response->json('terms'))->firstWhere('name', 'roasting')['taxonomy']);
        $this->assertSame('flowITeam', $response->json('authors.0.name'));
    }

    /**
     * A blog in two languages has two sets of sections, and offering an
     * English article the Ukrainian ones is how somebody files it where its
     * readers will never look.
     */
    public function test_sections_come_back_in_the_language_publica_asked_for(): void
    {
        $this->describedSite();
        $this->sections();

        $names = collect($this->signed('GET', '/publica/v1/structure?locale=en')->assertOk()->json('terms'))
            ->pluck('name');

        $this->assertTrue($names->contains('Coffee'));
        $this->assertFalse($names->contains('Кава'), 'another language\'s sections came back');

        // A taxonomy with no locale column is not filtered out of existence.
        $this->assertTrue($names->contains('roasting'));
    }

    /**
     * A site with nothing to file into says so, rather than 404ing. "We looked
     * and there is nothing" is a fact PUBLICA can act on; a missing route is a
     * version negotiation.
     */
    public function test_a_site_that_describes_nothing_answers_with_empty_lists(): void
    {
        $this->signed('GET', '/publica/v1/structure')
            ->assertOk()
            ->assertExactJson(['terms' => [], 'authors' => []]);
    }

    /** A model named in the config that this site does not have is skipped, not fatal. */
    public function test_a_taxonomy_pointing_at_nothing_does_not_break_the_answer(): void
    {
        config()->set('publica.structure.taxonomies', [
            'category' => ['model' => 'App\\Models\\NotHere'],
            'post_tag' => ['model' => Label::class],
        ]);
        $this->sections();

        $this->signed('GET', '/publica/v1/structure')
            ->assertOk()
            ->assertJsonPath('terms.0.name', 'roasting');
    }

    /** The receiver answers for the site's shape too, if it wants to. */
    public function test_a_receiver_that_describes_the_site_itself_is_used(): void
    {
        config()->set('publica.receiver', ReceiverThatKnowsTheSite::class);

        $this->signed('GET', '/publica/v1/structure')
            ->assertOk()
            ->assertJsonPath('terms.0.name', 'Everything')
            ->assertJsonPath('terms.0.remote_id', '7')
            // Blank rows are dropped: a mirror with empty lines in it is a
            // list somebody has to choose from.
            ->assertJsonCount(1, 'terms');
    }

    public function test_an_unsigned_request_gets_no_description(): void
    {
        $this->describedSite();

        $this->getJson('/publica/v1/structure')->assertUnauthorized();
    }

    /**
     * The other direction of the same feature: PUBLICA says where the article
     * belongs, in this site's own ids, and the receiver gets it verbatim.
     */
    public function test_placement_arrives_and_reaches_the_receiver(): void
    {
        config()->set('publica.receiver', ReceiverThatKnowsTheSite::class);

        $this->signed('POST', '/publica/v1/documents', $this->article([
            'placement' => ['categories' => ['3'], 'tags' => ['9', '11'], 'author' => '2'],
        ]))->assertCreated();

        $this->assertSame(
            ['categories' => ['3'], 'tags' => ['9', '11'], 'author' => '2'],
            ReceiverThatKnowsTheSite::$seen,
        );
    }
}

class Section extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    public function posts(): HasMany
    {
        return $this->hasMany(PublicaDocument::class, 'section_id');
    }
}

class Label extends Model
{
    protected $guarded = [];

    public $timestamps = false;
}

class Writer extends Model
{
    protected $guarded = [];

    public $timestamps = false;
}

/** A site that answers for its own shape, and remembers what it was told. */
class ReceiverThatKnowsTheSite implements DescribesStructure, ReceivesDocuments
{
    /** @var array<string, mixed>|null */
    public static ?array $seen = null;

    public function describeStructure(?string $locale = null): array
    {
        return [
            'terms' => [
                ['taxonomy' => 'category', 'remote_id' => 7, 'name' => 'Everything'],
                ['taxonomy' => 'category', 'remote_id' => 8, 'name' => ''],
            ],
            'authors' => [],
        ];
    }

    public function store(array $payload): array
    {
        static::$seen = $payload['placement'] ?? null;

        return ['id' => 1, 'url' => null, 'status' => 'draft'];
    }

    public function update(string $id, array $payload): array
    {
        static::$seen = $payload['placement'] ?? null;

        return ['id' => $id, 'url' => null, 'status' => 'draft'];
    }

    public function withdraw(string $id): void {}
}
