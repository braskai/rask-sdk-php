<?php

declare(strict_types=1);

namespace Rask\Sdk\Core;

/**
 * One page of a list. `foreach` walks every item of this page and of the pages after it, fetching them as it goes;
 * data, next_cursor, has_more and nextPage() give page-level access. A short or empty page does not end the walk: only
 * a null next_cursor does.
 *
 * @template TItem
 * @implements \IteratorAggregate<int, TItem>
 */
abstract class CursorPage extends Model implements \IteratorAggregate
{
    /** @var (\Closure(string): static)|null */
    private ?\Closure $nextPage = null;

    /**
     * Items of this page only.
     *
     * @return list<TItem>
     */
    abstract public function items(): array;

    /**
     * @param \Closure(string): static $nextPage
     * @internal
     */
    public function withNextPage(\Closure $nextPage): static
    {
        $this->nextPage = $nextPage;
        return $this;
    }

    public function nextCursor(): ?string
    {
        $cursor = $this->get('/next_cursor');
        return is_string($cursor) && $cursor !== '' ? $cursor : null;
    }

    public function hasNextPage(): bool
    {
        return $this->nextCursor() !== null;
    }

    /** Fetches the next page, with the same filters; null on the last page. */
    public function nextPage(): ?static
    {
        $cursor = $this->nextCursor();
        if ($cursor === null || $this->nextPage === null) {
            return null;
        }
        return ($this->nextPage)($cursor);
    }

    /** @return \Generator<int, TItem> */
    public function getIterator(): \Generator
    {
        $page = $this;
        while ($page !== null) {
            foreach ($page->items() as $item) {
                yield $item;
            }
            $page = $page->nextPage();
        }
    }
}
