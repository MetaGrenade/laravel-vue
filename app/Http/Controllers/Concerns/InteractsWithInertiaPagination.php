<?php

namespace App\Http\Controllers\Concerns;

use App\Support\Http\RequestText;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

trait InteractsWithInertiaPagination
{
    /**
     * How many rows a list shows, from `?per_page=`: a whole number kept between 1 and $max, and $default for anything else.
     * `paginate(-1)` drops the LIMIT but keeps `OFFSET 0`, which no database accepts, so a negative number was a 500 on every list
     * that had a row to show.
     */
    protected function requestedPerPage(Request $request, int $default, int $max = 100, string $key = 'per_page'): int
    {
        $asked = filter_var(trim(RequestText::query($request, $key)), FILTER_VALIDATE_INT);

        return $asked === false ? $default : max(1, min($asked, $max));
    }

    protected function inertiaPaginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'from' => $paginator->firstItem(),
            'last_page' => max($paginator->lastPage(), 1),
            'per_page' => $paginator->perPage(),
            'to' => $paginator->lastItem(),
            'total' => $paginator->total(),
        ];
    }

    protected function inertiaPaginationLinks(LengthAwarePaginator $paginator): array
    {
        $lastPage = max($paginator->lastPage(), 1);

        return [
            'first' => $paginator->url(1),
            'last' => $paginator->url($lastPage),
            'prev' => $paginator->previousPageUrl(),
            'next' => $paginator->nextPageUrl(),
        ];
    }

    protected function inertiaPagination(LengthAwarePaginator $paginator): array
    {
        return [
            'meta' => $this->inertiaPaginationMeta($paginator),
            'links' => $this->inertiaPaginationLinks($paginator),
        ];
    }
}
