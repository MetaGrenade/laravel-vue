<?php

use App\Support\Text\PlainText;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A blog post's `body` is HTML as the sanitiser stores it (`<p>Don&#039;t panic</p>`), and the database search looked for the term in that:
 * a search for *don't* found nothing in a post that says exactly that, and one for *https*, *href*, *noopener* or *blockquote* found every
 * post with a link or a quote, with nothing to highlight, because it matched the markup and not the words.
 *
 * `body_text` is the words of the body (App\Support\Text\PlainText), kept by the model whenever the body is saved, and what the search
 * reads. The posts that are there are filled in here, a chunk at a time. `body` is not touched.
 *
 * `down()` drops the column; the search then has to be put back to `body` too, which is what the code before this did.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('blogs', 'body_text')) {
            Schema::table('blogs', function (Blueprint $table) {
                $table->longText('body_text')->nullable()->after('body');
            });
        }

        DB::table('blogs')
            ->select(['id', 'body'])
            ->chunkById(200, function ($blogs): void {
                foreach ($blogs as $blog) {
                    DB::table('blogs')->where('id', $blog->id)->update(['body_text' => PlainText::of($blog->body)]);
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('blogs', 'body_text')) {
            Schema::table('blogs', function (Blueprint $table) {
                $table->dropColumn('body_text');
            });
        }
    }
};
