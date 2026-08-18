<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The default landing place for articles arriving from PUBLICA.
 *
 * A site with its own posts table does not need this and can skip the
 * migration entirely — point `publica.model` at the model it already has. It
 * exists so a site with nothing is a working destination the moment the token
 * is set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('publica_documents', function (Blueprint $table) {
            $table->id();

            $table->string('title');
            $table->string('slug')->nullable()->index();
            $table->string('locale', 5)->nullable();
            $table->text('excerpt')->nullable();

            // The article twice: as blocks, which is what it is, and as HTML,
            // which is what a site without a block renderer needs today.
            $table->json('blocks')->nullable();
            $table->longText('html')->nullable();

            $table->json('seo')->nullable();
            $table->json('og')->nullable();
            $table->json('schema_org')->nullable();

            // draft until somebody on this site says otherwise. PUBLICA sends
            // draft by default too, and neither end should be the one that
            // quietly decided to publish.
            $table->string('status')->default('draft')->index();
            $table->timestamp('published_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publica_documents');
    }
};
