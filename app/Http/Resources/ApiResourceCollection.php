<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Base for every list endpoint. Replaces Laravel's `links` + `meta` block with
 * the API's own pagination meta:
 *
 *   page-based (default)       { "data": [...], "meta": { "page", "per_page", "total" } }
 *   cursor-based (large logs)  { "data": [...], "meta": { "per_page", "next_cursor", "prev_cursor" } }
 *
 * Feed it `->paginate()` or `->cursorPaginate()` with the sizes from
 * PaginatedRequest.
 */
abstract class ApiResourceCollection extends ResourceCollection
{
    /**
     * Called by Laravel's PaginatedResourceResponse in place of its default.
     *
     * @param  array<string, mixed>  $paginated
     * @param  array<string, mixed>  $default
     * @return array{meta: array<string, int|string|null>}
     */
    public function paginationInformation(Request $request, array $paginated, array $default): array
    {
        $paginator = $this->resource;

        if ($paginator instanceof CursorPaginator) {
            return ['meta' => [
                'per_page' => $paginator->perPage(),
                'next_cursor' => $paginator->nextCursor()?->encode(),
                'prev_cursor' => $paginator->previousCursor()?->encode(),
            ]];
        }

        if ($paginator instanceof LengthAwarePaginator) {
            return ['meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ]];
        }

        return ['meta' => []];
    }
}
