<?php
declare(strict_types=1);

namespace YouthSync\Http;

/**
 * Shared page/perPage/search handling for every list endpoint.
 *
 * Contract (see API_DOCUMENTATION.md "Search, filters and pagination"):
 * - `page`: positive integer. Missing, non-numeric, 0 or negative becomes 1.
 *   Values above MAX_PAGE are capped there (keeps OFFSET integer-safe).
 * - `perPage` (alias `per_page`): positive integer. Missing, non-numeric or
 *   below 1 becomes the endpoint default; above the endpoint max it is capped.
 * - `total` is counted with the same WHERE as the data query, before LIMIT.
 * - `pages` / `totalPages` = max(1, ceil(total / perPage)). An empty result
 *   is one empty page, so total = 0 gives totalPages = 1.
 * - A page past `totalPages` is not an error and is not moved: it returns
 *   `items: []` with the requested `page` and the real `total`.
 * - LIMIT / OFFSET are built from validated integers only.
 * - `q` is trimmed, capped at MAX_SEARCH characters, and matched literally
 *   (`%`, `_` and the escape character are escaped) with one placeholder per
 *   column, because emulated prepares are off.
 */
final class Pagination
{
    public const MAX_PAGE = 1000000;
    public const MAX_SEARCH = 100;

    private function __construct(
        public readonly int $page,
        public readonly int $perPage,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     */
    public static function fromQuery(array $query, int $default, int $max = 100): self
    {
        $max = max(1, $max);
        $default = min(max(1, $default), $max);

        $page = self::positiveInt($query['page'] ?? null) ?? 1;
        $perPage = self::positiveInt($query['perPage'] ?? $query['per_page'] ?? null) ?? $default;

        return new self(min($page, self::MAX_PAGE), min($perPage, $max));
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    public function limitSql(): string
    {
        return sprintf('LIMIT %d OFFSET %d', $this->perPage, $this->offset());
    }

    /**
     * @return array{page: int, perPage: int, total: int, pages: int, totalPages: int}
     */
    public function meta(int $total): array
    {
        $total = max(0, $total);
        $totalPages = max(1, (int) ceil($total / $this->perPage));

        return [
            'page' => $this->page,
            'perPage' => $this->perPage,
            'total' => $total,
            'pages' => $totalPages,
            'totalPages' => $totalPages,
        ];
    }

    /**
     * Trimmed search text from the query string (empty when absent).
     *
     * @param array<string, mixed> $query
     */
    public static function search(array $query, string $key = 'q'): string
    {
        $value = $query[$key] ?? '';
        if (!is_scalar($value)) {
            return '';
        }
        return mb_substr(trim((string) $value), 0, self::MAX_SEARCH);
    }

    /**
     * `(col1 LIKE :q0 ESCAPE '!' OR col2 LIKE :q1 ESCAPE '!' ...)` with one bound
     * placeholder per column. Adds the parameters to $params.
     *
     * @param list<string> $columns trusted SQL column expressions
     * @param array<string, mixed> $params
     */
    public static function likeAny(array $columns, string $term, string $prefix, array &$params): string
    {
        $pattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term) . '%';
        $parts = [];
        foreach (array_values($columns) as $i => $column) {
            $name = $prefix . $i;
            $parts[] = "{$column} LIKE :{$name} ESCAPE '!'";
            $params[$name] = $pattern;
        }
        return '(' . implode(' OR ', $parts) . ')';
    }

    private static function positiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 1 ? $value : null;
        }
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '' || !ctype_digit($value)) {
            return null;
        }
        $value = ltrim($value, '0');
        if ($value === '') {
            return null;
        }
        if (strlen($value) > 9) {
            return PHP_INT_MAX;
        }
        return (int) $value;
    }
}
