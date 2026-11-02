<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\World;

/**
 * StackedContents: the items available, by item ID, and RecipePicker's search for an assignment
 * of one item type to each ingredient, each taking the same number of items, as augmenting paths
 * through the ingredients and the items they accept. Vanilla keeps the items in an identity hash
 * map, so which acceptable item it picks varies; here the first accounted is tried first.
 */
final class StackedContents
{
    /** @var array<int, int> */
    public array $amounts = [];

    public function account(int $item, int $count): void
    {
        $this->amounts[$item] = ($this->amounts[$item] ?? 0) + $count;
    }

    /**
     * @param list<array<int, true>> $ingredients the items each ingredient accepts
     * @param list<int>|null $output the item picked for each ingredient, in ingredient order
     */
    public function tryPick(array $ingredients, int $amount, ?array &$output = null): bool
    {
        return $this->picker($ingredients)->pick($amount, $output);
    }

    /** RecipePicker.tryPickAll: the largest amount every ingredient can take, by bisection. */
    public function tryPickAll(array $ingredients, int $maxSize, ?array &$output = null): int
    {
        $picker = $this->picker($ingredients);
        $min = 0;
        $max = min($maxSize, $this->resultUpperBound($ingredients)) + 1;
        while (true) {
            $mid = intdiv($min + $max, 2);
            $none = null;
            if ($picker->pick($mid, $none)) {
                if ($max - $min <= 1) {
                    if ($mid > 0) {
                        $picker->pick($mid, $output);
                    }
                    return $mid;
                }
                $min = $mid;
            } else {
                $max = $mid;
            }
        }
    }

    /** getResultUpperBound: the smallest, over ingredients, of the largest stack one accepts. */
    private function resultUpperBound(array $ingredients): int
    {
        $min = PHP_INT_MAX;
        foreach ($ingredients as $accepted) {
            $max = 0;
            foreach ($this->amounts as $item => $count) {
                if ($count > $max) {
                    if (isset($accepted[$item])) {
                        $max = $count;
                    }
                    if ($max >= $min) {
                        continue 2;
                    }
                }
            }
            $min = $max;
            if ($max === 0) {
                break;
            }
        }
        return $min;
    }

    private function picker(array $ingredients): object
    {
        $contents = $this;
        // getUniqueAvailableIngredientItems: the items in stock that some ingredient accepts.
        $items = [];
        foreach ($this->amounts as $item => $count) {
            if ($count > 0) {
                foreach ($ingredients as $accepted) {
                    if (isset($accepted[$item])) {
                        $items[] = $item;
                        break;
                    }
                }
            }
        }
        return new class ($contents, $ingredients, $items) {
            /** @var array<string, true> connections, assignments, visits and satisfied ingredients */
            private array $connected = [];

            private array $assigned = [];

            private array $visitedIngredients = [];

            private array $visitedItems = [];

            private array $satisfied = [];

            public function __construct(private StackedContents $contents, private array $ingredients, private array $items)
            {
                foreach ($ingredients as $ingredient => $accepted) {
                    foreach ($items as $index => $item) {
                        if (isset($accepted[$item])) {
                            $this->connected[$index . ',' . $ingredient] = true;
                        }
                    }
                }
            }

            public function pick(int $capacity, ?array &$output): bool
            {
                if ($capacity <= 0) {
                    return true;
                }
                $satisfiedCount = 0;
                while (true) {
                    $path = $this->tryAssigningNewItem($capacity);
                    if ($path === null) {
                        $valid = $satisfiedCount === count($this->ingredients);
                        $this->visitedIngredients = $this->visitedItems = $this->satisfied = [];
                        $picked = [];
                        foreach (array_keys($this->ingredients) as $ingredient) {
                            foreach (array_keys($this->items) as $index) {
                                if (isset($this->assigned[$index . ',' . $ingredient])) {
                                    unset($this->assigned[$index . ',' . $ingredient]);
                                    $this->contents->amounts[$this->items[$index]] += $capacity;
                                    $picked[] = $this->items[$index];
                                    break;
                                }
                            }
                        }
                        if ($valid) {
                            $output = $picked;
                        }
                        return $valid;
                    }
                    $this->contents->amounts[$this->items[$path[0]]] -= $capacity;
                    $this->satisfied[$path[count($path) - 1]] = true;
                    ++$satisfiedCount;
                    for ($i = 0; $i < count($path) - 1; ++$i) {
                        if (($i & 1) === 0) {
                            $this->assigned[$path[$i] . ',' . $path[$i + 1]] = true;
                        } else {
                            unset($this->assigned[$path[$i + 1] . ',' . $path[$i]]);
                        }
                    }
                }
            }

            private function tryAssigningNewItem(int $capacity): ?array
            {
                $this->visitedIngredients = $this->visitedItems = [];
                foreach ($this->items as $index => $item) {
                    if (($this->contents->amounts[$item] ?? 0) >= $capacity) {
                        $path = $this->findPath($index);
                        if ($path !== null) {
                            return $path;
                        }
                    }
                }
                return null;
            }

            /** findNewItemAssignmentPath: alternating items and ingredients, ending at an unsatisfied ingredient. */
            private function findPath(int $start): ?array
            {
                $this->visitedItems[$start] = true;
                $path = [$start];
                while ($path !== []) {
                    $length = count($path);
                    if ((($length - 1) & 1) === 0) {
                        $item = $path[$length - 1];
                        foreach (array_keys($this->ingredients) as $ingredient) {
                            if (!isset($this->visitedIngredients[$ingredient]) && isset($this->connected[$item . ',' . $ingredient]) && !isset($this->assigned[$item . ',' . $ingredient])) {
                                $this->visitedIngredients[$ingredient] = true;
                                $path[] = $ingredient;
                                break;
                            }
                        }
                    } else {
                        $ingredient = $path[$length - 1];
                        if (!isset($this->satisfied[$ingredient])) {
                            return $path;
                        }
                        foreach (array_keys($this->items) as $index) {
                            if (!isset($this->visitedItems[$index]) && isset($this->assigned[$index . ',' . $ingredient])) {
                                $this->visitedItems[$index] = true;
                                $path[] = $index;
                                break;
                            }
                        }
                    }
                    if (count($path) === $length) {
                        array_pop($path);
                    }
                }
                return null;
            }
        };
    }
}
