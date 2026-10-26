<?php

declare(strict_types=1);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setRules([
        '@PSR12' => true,
        'binary_operator_spaces' => ['default' => 'single_space'],
        'concat_space' => ['spacing' => 'one'],
        'method_argument_space' => ['on_multiline' => 'ensure_fully_multiline'],
        'no_multiple_statements_per_line' => true,
        'whitespace_after_comma_in_array' => true,
        'cast_spaces' => ['space' => 'single'],
        'space_after_semicolon' => true,
        'single_import_per_statement' => ['group_to_single_imports' => true],
        'class_attributes_separation' => [
            'elements' => ['method' => 'one', 'property' => 'one', 'trait_import' => 'one'],
        ],
    ])
    ->setFinder(
        PhpCsFixer\Finder::create()
            ->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/bin'])
            ->append([__FILE__])
    );
