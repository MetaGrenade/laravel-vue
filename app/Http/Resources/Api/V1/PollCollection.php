<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * A page of polls, already written as arrays by the controller (what the key's owner voted for is worked out once for the page, not per poll).
 *
 * It is wrapped in `polls`, as the list always was, and carries `links` and `meta` like every other list of the API (the framework writes them
 * for a paginator), so a script that read `polls` keeps working and one that wants the rest follows `links.next`.
 */
class PollCollection extends ResourceCollection
{
    public static $wrap = 'polls';

    /**
     * @return array<int, array<string, mixed>>
     */
    public function toArray(Request $request): array
    {
        return $this->collection->all();
    }
}
