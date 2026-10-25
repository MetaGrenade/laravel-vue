<?php

namespace App\Http\Controllers;

use App\Models\ForumAttachment;
use App\Support\Forum\ForumAttachments;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ForumAttachmentController extends Controller
{
    public function show(ForumAttachment $attachment, ForumAttachments $attachments): StreamedResponse
    {
        abort_unless($attachments->visibleTo(request()->user(), $attachment), 404);

        return $attachments->response($attachment);
    }
}
