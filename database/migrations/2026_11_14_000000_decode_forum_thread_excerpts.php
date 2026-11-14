<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A thread's `excerpt` is the start of its first post, kept as text for the board lists, the feeds, the API and the search results. It was
 * made with `strip_tags` from the post as the sanitiser stores it, and the sanitiser stores `Don't` as `Don&#039;t` and `Q&A` as `Q&amp;A`,
 * so every excerpt with an apostrophe, a quotation mark or an ampersand in it showed the entity to the reader. New threads are made right
 * (App\Support\Text\PlainText); this makes the ones that are there right: the entities in the stored text become the characters.
 *
 * Only that. Paragraphs that were run together when the excerpt was made (`...Second paragraph`) stay run together: the words are not
 * in the text any more to be told apart. A search index is not touched by a migration: `php artisan metaforger:search:reindex` brings the
 * threads in it up to date.
 *
 * Written out here and not read from the app, as a migration is: what it does must not change when the app's text code does.
 *
 * `down()` does nothing: nothing asks for the entities, and which excerpts had them is not kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('forum_threads')
            ->where('excerpt', 'like', '%&%;%')
            ->chunkById(200, function ($threads): void {
                foreach ($threads as $thread) {
                    $decoded = html_entity_decode((string) $thread->excerpt, ENT_QUOTES | ENT_HTML5, 'UTF-8');

                    if ($decoded === $thread->excerpt) {
                        continue;
                    }

                    DB::table('forum_threads')->where('id', $thread->id)->update(['excerpt' => $decoded]);
                }
            });
    }

    public function down(): void
    {
        // Nothing to put back.
    }
};
