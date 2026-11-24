<?php

declare(strict_types=1);

namespace Leadveyor;

/**
 * One page of a list endpoint, plus iteration over every item.
 *
 * ```php
 * $page = $lv->leads->list(['tier' => 'hot', 'limit' => 50]);
 * $page->data; $page->hasMore; $page->nextCursor;   // this page
 *
 * foreach ($lv->leads->list(['outcome' => 'none']) as $lead) { … }   // every item; next pages fetched as you go
 *
 * $recent = $lv->events->list(['type' => 'lead.*'])->toArray(500);   // up to 500 items as an array
 * ```
 *
 * @template T of LeadveyorObject
 * @implements \IteratorAggregate<int, T>
 */
final class Page implements \IteratorAggregate, \Countable
{
    /** @var list<T> Items of this page. */
    public readonly array $data;
    public readonly bool $hasMore;
    public readonly ?string $nextCursor;

    /** @var \Closure(?string): LeadveyorObject */
    private \Closure $fetch;

    /**
     * @internal Created by the list methods.
     * @param \Closure(?string): LeadveyorObject $fetch fetches the page after a cursor
     * @param class-string<T> $itemClass
     */
    public function __construct(\Closure $fetch, private readonly string $itemClass, LeadveyorObject $response)
    {
        $this->fetch = $fetch;
        $items = [];
        foreach ($response['data'] ?? [] as $item) {
            $items[] = $item instanceof LeadveyorObject ? $itemClass::constructFrom($item->toStdClass()) : $item;
        }
        $this->data = $items;
        $this->hasMore = (bool) ($response['has_more'] ?? false);
        $next = $response['next_cursor'] ?? null;
        $this->nextCursor = is_string($next) ? $next : null;
    }

    /**
     * The next page, or null on the last one.
     *
     * @return Page<T>|null
     */
    public function nextPage(): ?Page
    {
        if (!$this->hasMore || $this->nextCursor === null) {
            return null;
        }
        return new Page($this->fetch, $this->itemClass, ($this->fetch)($this->nextCursor));
    }

    /**
     * Every item from this page on, fetching the next pages as needed.
     *
     * @return \Generator<int, T>
     */
    public function getIterator(): \Generator
    {
        $page = $this;
        $i = 0;
        while ($page !== null) {
            foreach ($page->data as $item) {
                yield $i++ => $item;
            }
            $page = $page->nextPage();
        }
    }

    /** Same as iterating the page: every item across pages. @return \Generator<int, T> */
    public function autoPagingIterator(): \Generator
    {
        return $this->getIterator();
    }

    /**
     * Up to `$limit` items (across pages) as an array; every item when null.
     *
     * @return list<T>
     */
    public function toArray(?int $limit = null): array
    {
        $out = [];
        if ($limit !== null && $limit <= 0) {
            return $out;
        }
        foreach ($this as $item) {
            $out[] = $item;
            if ($limit !== null && count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /** Items on this page. */
    public function count(): int
    {
        return count($this->data);
    }
}
