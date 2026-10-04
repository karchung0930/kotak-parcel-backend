<?php

namespace App\Support\RateSheets;

use App\Models\RateImport;

/**
 * The problems found in a spreadsheet, each with its row and column where
 * there is one. Only the first 200 are kept, so a file that is wrong
 * throughout still gives a page that loads, with the total beside them.
 *
 * @phpstan-import-type Problem from RateImport
 * @phpstan-import-type Problems from RateImport as ProblemList
 */
final class Problems
{
    /**
     * The most problems kept.
     */
    public const MAX = 200;

    /**
     * How many problems were found.
     */
    private int $total = 0;

    /**
     * The first problems found.
     *
     * @var list<Problem>
     */
    private array $items = [];

    /**
     * Note a problem, at a row and column (A, B…) when it has one.
     */
    public function add(?int $row, ?int $column, string $message): void
    {
        $this->total++;

        if (count($this->items) < self::MAX) {
            $this->items[] = [
                'row' => $row,
                'column' => $column === null ? null : Cells::column($column),
                'message' => $message,
            ];
        }
    }

    /**
     * Get one problem on its own, for a file that cannot be read at all.
     *
     * @return ProblemList
     */
    public static function only(string $message): array
    {
        $problems = new self;
        $problems->add(null, null, $message);

        return $problems->toArray();
    }

    /**
     * Get how many problems were found.
     */
    public function count(): int
    {
        return $this->total;
    }

    /**
     * Get the array form stored on the import: the problems in row order
     * (those found in the order they were found), then the problems of the
     * whole file or a route, which have no row.
     *
     * @return ProblemList
     */
    public function toArray(): array
    {
        $items = $this->items;
        usort($items, fn (array $a, array $b): int => ($a['row'] ?? PHP_INT_MAX) <=> ($b['row'] ?? PHP_INT_MAX));

        return ['total' => $this->total, 'items' => $items];
    }
}
