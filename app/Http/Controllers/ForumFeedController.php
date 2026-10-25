<?php

namespace App\Http\Controllers;

use App\Models\ForumBoard;
use App\Support\Forum\ForumFeed;
use Illuminate\Http\Response;

/**
 * The public Atom feed of published threads (docs/forum-rss.md).
 */
class ForumFeedController extends Controller
{
    public function index(ForumFeed $feed): Response
    {
        return $feed->response();
    }

    public function board(ForumFeed $feed, ForumBoard $board): Response
    {
        return $feed->response($board);
    }
}
