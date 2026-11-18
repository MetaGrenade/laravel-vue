<?php

namespace App\Jobs;

use App\Models\Blog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Publishes the blog posts whose scheduled time has come (`Blog::publishDue()`). A visit to the blog does it as well, but a post that nobody
 * has asked for yet would stay `scheduled` and, with Meilisearch, out of the search until somebody did (docs/search-index.md).
 */
class PublishDueBlogs implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        Blog::publishDue();
    }
}
