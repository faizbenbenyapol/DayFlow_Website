<?php
// =====================================================
// core/Paginator.php — page bounds for list endpoints
//
// The records that accumulate — transactions, workouts, focus sessions, AI
// history — were read in full on every request. That is fine for a new
// account and slower every year, and the page renders every row it is given.
//
// Endpoints read their window through here so the caps are the same
// everywhere and a caller cannot ask for an unbounded read.
// =====================================================

final class Paginator
{
    public const DEFAULT_LIMIT = 100;
    public const MAX_LIMIT     = 500;

    /**
     * The window the current request is asking for.
     *
     * @return array{limit: int, offset: int}
     */
    public static function fromRequest(int $defaultLimit = self::DEFAULT_LIMIT): array
    {
        $limit = Request::query('limit');
        $limit = $limit === null ? $defaultLimit : (int)$limit;
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        // Offset wins when both are given: a caller paging by offset is being
        // explicit, and mixing the two silently would skip or repeat rows.
        $offset = Request::query('offset');
        if ($offset !== null) {
            return ['limit' => $limit, 'offset' => max(0, (int)$offset)];
        }

        $page = max(1, (int)(Request::query('page') ?? 1));
        return ['limit' => $limit, 'offset' => ($page - 1) * $limit];
    }

    /**
     * The block a response carries alongside its rows, so the client knows
     * whether to offer "load more" without counting anything itself.
     *
     * $total is optional: counting costs a second query, and a list that only
     * ever scrolls forward does not need it.
     */
    public static function meta(array $window, int $returned, ?int $total = null): array
    {
        $meta = [
            'limit'    => $window['limit'],
            'offset'   => $window['offset'],
            // A full page means there may be more; a short one means the end.
            'has_more' => $returned >= $window['limit'],
        ];

        if ($total !== null) {
            $meta['total']    = $total;
            $meta['has_more'] = ($window['offset'] + $returned) < $total;
        }

        return $meta;
    }
}
