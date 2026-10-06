<?php

use App\Support\OpenApi\Specification;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('api:docs', function () {
    $path = Specification::generate();

    $this->info('OpenAPI specification written to: '.$path);
})->purpose('Generate the OpenAPI specification for the JSON API');

Artisan::command('content:sanitize {--dry-run : Report how many records would change without saving}', function () {
    $sanitizer = app(\App\Support\Security\HtmlSanitizer::class);

    $targets = [
        'forum_posts' => 'forum',
        'forum_post_revisions' => 'forum',
        'blogs' => 'article',
        'blog_revisions' => 'article',
    ];

    foreach ($targets as $table => $policy) {
        $changed = 0;

        \Illuminate\Support\Facades\DB::table($table)
            ->select(['id', 'body'])
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($sanitizer, $policy, $table, &$changed) {
                foreach ($rows as $row) {
                    if ($row->body === null) {
                        continue;
                    }

                    $clean = $sanitizer->{$policy}($row->body);

                    if ($clean === $row->body) {
                        continue;
                    }

                    $changed++;

                    if (! $this->option('dry-run')) {
                        \Illuminate\Support\Facades\DB::table($table)->where('id', $row->id)->update(['body' => $clean]);
                    }
                }
            });

        $this->line(sprintf('%s: %d record(s) %s', $table, $changed, $this->option('dry-run') ? 'would change' : 'sanitised'));
    }
})->purpose('Re-sanitise stored rich-text content (forum posts, blogs and their revisions)');
