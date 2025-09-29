<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

trait InteractsWithInertiaPagination
{
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
