<?php

namespace Kompo\Auth\Teams;

use Illuminate\Support\Collection;

/**
 * A Collection that carries a known total count and skips re-slicing.
 * Designed to work with Kompo's CollectionQuery::getPaginated() which calls
 * $this->query->slice(offset, perPage)->values() and $this->query->count().
 */
class PaginatedCollection extends Collection
{
    protected ?int $paginatedTotal = null;

    public static function fromItems($items, int $total): static
    {
        $instance = new static($items);
        $instance->paginatedTotal = $total;

        return $instance;
    }

    public function count(): int
    {
        if ($this->paginatedTotal !== null) {
            return $this->paginatedTotal;
        }

        return parent::count();
    }

    public function slice($offset, $length = null)
    {
        // Data is already sliced for the current page — skip re-slicing
        if ($this->paginatedTotal !== null) {
            return new static($this->items);
        }

        return parent::slice($offset, $length);
    }
}
