<?php

declare(strict_types=1);

namespace Rask\Sdk\Core;

use Rask\Sdk\Exception\UnexpectedResponseError;

/**
 * One page of a list. `foreach` walks every item of this page and of the pages after it, fetching them as it goes;
 * data, next_cursor, has_more and nextPage() give page-level access. A short or empty page does not end the walk: only
 * a null next_cursor does. A cursor the list has already given raises, because following it would never end.
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

    /**
     * @return \Generator<int, TItem>
     * @throws UnexpectedResponseError when the list gives a cursor a second time
     */
    public function getIterator(): \Generator
    {
        $page = $this;
        // Every cursor given on this walk, not only the last: a cycle such as A, B, A never repeats one in a row.
        $given = [];
        while ($page !== null) {
            foreach ($page->items() as $item) {
                yield $item;
            }
            $cursor = $page->nextCursor();
            if ($cursor !== null) {
                // Following a cursor a second time would get the same pages, for ever.
                if (isset($given[$cursor])) {
                    $response = $page->response();
                    throw new UnexpectedResponseError('The list gave a cursor it had already given, so walking it would never end.', $response->status ?? 0, null, $response);
                }
                $given[$cursor] = true;
            }
            $page = $page->nextPage();
        }
    }
}
