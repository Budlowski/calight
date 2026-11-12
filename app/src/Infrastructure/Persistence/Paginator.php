<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Support\ListQuery;
use PDO;

/**
 * Gemeinsame serverseitige Pagination für Übersichtslisten.
 *
 * Repositories liefern nur ihre FROM-/WHERE-Bausteine und eine Whitelist
 * sortierbarer SQL-Ausdrücke. Nutzereingaben gelangen ausschließlich als
 * gebundene Parameter in die Abfrage.
 */
final class Paginator
{
    /** @var list<string> */
    private array $conditions = [];
    /** @var array<string,string|int> */
    private array $parameters = [];
    private int $parameterIndex = 0;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string,string|int> $parameters */
    public function where(string $condition, array $parameters = []): self
    {
        $this->conditions[] = '(' . $condition . ')';
        $this->parameters = [...$this->parameters, ...$parameters];
        return $this;
    }

    /**
     * Jeder Suchbegriff muss in mindestens einer der Spalten vorkommen.
     * Voraussetzung ist die von ConnectionFactory registrierte Funktion calight_fold().
     *
     * @param list<string> $columns SQL-Ausdrücke aus dem Repository, niemals Nutzereingaben
     */
    public function search(ListQuery $query, array $columns): self
    {
        foreach ($query->searchTerms() as $term) {
            $pattern = '%' . addcslashes(mb_strtolower($term, 'UTF-8'), '%_\\') . '%';
            $matches = [];
            $parameters = [];
            // Eigener Platzhalter je Spalte: PDO erlaubt ohne Emulation keine Wiederholung.
            foreach ($columns as $column) {
                $name = 'search_' . $this->parameterIndex++;
                $matches[] = "calight_fold({$column}) LIKE :{$name} ESCAPE '\\'";
                $parameters[$name] = $pattern;
            }
            $this->where(implode(' OR ', $matches), $parameters);
        }
        return $this;
    }

    /**
     * @param array<string,string> $sortExpressions Sortierschlüssel => SQL-Ausdruck
     * @return array{items:list<array<string,mixed>>,total:int,page:int,perPage:int,pageCount:int,sort:string,dir:string}
     */
    public function fetch(string $select, string $from, array $sortExpressions, string $tieBreaker, ListQuery $query): array
    {
        $where = $this->conditions === [] ? '' : ' WHERE ' . implode(' AND ', $this->conditions);

        $count = $this->pdo->prepare("SELECT COUNT(*) FROM {$from}{$where}");
        $count->execute($this->parameters);
        $total = (int) $count->fetchColumn();

        // Ungültige Seitenzahlen auf die letzte vorhandene Seite begrenzen,
        // etwa nach dem Löschen des letzten Eintrags einer Seite.
        $pageCount = max(1, (int) ceil($total / $query->perPage));
        $page = min($query->page, $pageCount);

        $expression = $sortExpressions[$query->sort];
        $direction = $query->direction === 'desc' ? 'DESC' : 'ASC';
        // Leere Werte (z. B. Entwürfe ohne Nummer) stehen unabhängig von der Richtung am Ende;
        // der eindeutige Tie-Breaker macht die Reihenfolge bei gleichen Werten stabil.
        $orderBy = "({$expression}) IS NULL, {$expression} {$direction}, {$tieBreaker} {$direction}";

        $items = [];
        if ($total > 0) {
            $statement = $this->pdo->prepare("SELECT {$select} FROM {$from}{$where} ORDER BY {$orderBy} LIMIT :list_limit OFFSET :list_offset");
            foreach ($this->parameters as $name => $value) {
                $statement->bindValue(':' . $name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            $statement->bindValue(':list_limit', $query->perPage, PDO::PARAM_INT);
            $statement->bindValue(':list_offset', ($page - 1) * $query->perPage, PDO::PARAM_INT);
            $statement->execute();
            $items = $statement->fetchAll();
        }

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'perPage' => $query->perPage,
            'pageCount' => $pageCount,
            'sort' => $query->sort,
            'dir' => $query->direction,
        ];
    }
}
