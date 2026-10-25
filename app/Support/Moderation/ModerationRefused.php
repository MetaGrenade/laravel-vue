<?php

namespace App\Support\Moderation;

use RuntimeException;

/**
 * A moderation action that is not allowed, with the reason in words a moderator can be shown (docs/moderation.md).
 */
class ModerationRefused extends RuntimeException {}
