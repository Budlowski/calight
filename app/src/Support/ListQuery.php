<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Validierter Listenstatus einer Übersichtsseite (Suche, Filter, Sortierung, Seite).
 *
 * Ungültige oder veraltete URL-Parameter führen nie zu Fehlern, sondern fallen
 * auf die fachlichen Standardwerte zurück.
 */
final readonly class ListQuery
{
    public const PAGE_SIZES = [10, 25, 50, 100];
    public const DEFAULT_PAGE_SIZE = 25;
    private const MAX_SEARCH_LENGTH = 200;
    private const MAX_FILTER_LENGTH = 100;

    /** @param array<string,string> $filters */
    public function __construct(
        public string $search,
        public array $filters,
        public string $sort,
        public string $direction,
        public int $page,
        public int $perPage,
    ) {
    }

    /**
     * @param array<string,mixed> $query Rohwerte aus der Anfrage ($_GET)
     * @param array<string,string> $sortDirections erlaubte Sortierfelder mit ihrer natürlichen Richtung (asc|desc)
     * @param list<string> $filterNames erlaubte Filterparameter des Moduls
     */
    public static function fromArray(array $query, array $sortDirections, string $defaultSort, array $filterNames = []): self
    {
        $sort = self::text($query['sort'] ?? null, 50);
        if (!array_key_exists($sort, $sortDirections)) {
            $sort = $defaultSort;
        }
        $direction = strtolower(self::text($query['dir'] ?? null, 4));
        if (!in_array($direction, ['asc', 'desc'], true)) {
            $direction = $sortDirections[$sort];
        }

        $perPage = self::integer($query['per_page'] ?? null) ?? self::DEFAULT_PAGE_SIZE;
        if (!in_array($perPage, self::PAGE_SIZES, true)) {
            $perPage = self::DEFAULT_PAGE_SIZE;
        }

        $filters = [];
        foreach ($filterNames as $name) {
            $value = self::text($query[$name] ?? null, self::MAX_FILTER_LENGTH);
            if ($value !== '') {
                $filters[$name] = $value;
            }
        }

        return new self(
            search: self::text($query['q'] ?? null, self::MAX_SEARCH_LENGTH),
            filters: $filters,
            sort: $sort,
            direction: $direction,
            page: max(1, self::integer($query['page'] ?? null) ?? 1),
            perPage: $perPage,
        );
    }

    public function filter(string $name): ?string
    {
        return $this->filters[$name] ?? null;
    }

    /** @return list<string> Suchbegriffe; jeder Begriff muss in mindestens einem Feld vorkommen. */
    public function searchTerms(): array
    {
        $terms = preg_split('/\s+/u', $this->search, -1, PREG_SPLIT_NO_EMPTY);
        return array_slice($terms === false ? [] : $terms, 0, 8);
    }

    private static function text(mixed $value, int $maxLength): string
    {
        if (!is_string($value)) {
            return '';
        }
        return mb_substr(trim($value), 0, $maxLength);
    }

    private static function integer(mixed $value): ?int
    {
        if (!is_string($value) || preg_match('/^\d{1,9}$/D', $value) !== 1) {
            return null;
        }
        return (int) $value;
    }
}
