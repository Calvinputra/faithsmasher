<?php

declare(strict_types=1);

namespace App\Support;

final class Paginator
{
    public function __construct(
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
    ) {
    }

    public static function fromTotal(int $total, int $page, int $perPage): self
    {
        return new self($total, max(1, $page), max(1, $perPage));
    }

    public function totalPages(): int
    {
        return (int) max(1, ceil($this->total / $this->perPage));
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    public function hasPages(): bool
    {
        return $this->totalPages() > 1;
    }

    public function hasPrevious(): bool
    {
        return $this->page > 1;
    }

    public function hasNext(): bool
    {
        return $this->page < $this->totalPages();
    }

    /** @return list<int> */
    public function pages(int $window = 2): array
    {
        $total = $this->totalPages();
        $start = max(1, $this->page - $window);
        $end = min($total, $this->page + $window);

        return range($start, $end);
    }
}
