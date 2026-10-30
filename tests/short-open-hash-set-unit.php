<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use PhpMinecraftJavaServer\World\ShortOpenHashSet;

// Each line: shorts added in order, then fastutil 8.5's ShortOpenHashSet iteration order for them.
$checks = 0;
foreach (file(__DIR__ . '/fixtures/short-open-hash-set.txt', FILE_IGNORE_NEW_LINES) as $line) {
    [$added, $expected] = explode('|', $line);
    $set = new ShortOpenHashSet();
    foreach (array_filter(explode(',', $added), 'strlen') as $value) {
        $set->add((int) $value);
    }
    if (implode(',', $set->values()) . ',' !== $expected) {
        throw new RuntimeException('ShortOpenHashSet order differs for: ' . $added);
    }
    ++$checks;
}
echo "PASS: {$checks} ShortOpenHashSet iteration orders match fastutil\n";
