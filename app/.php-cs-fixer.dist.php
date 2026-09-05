<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/config', __DIR__ . '/public'])
    ->exclude(['var', 'vendor'])
    ->notPath('reference.php')
    ->append([__FILE__]);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setCacheFile(__DIR__ . '/var/cache/.php-cs-fixer.cache')
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        '@PER-CS' => true,
        'declare_strict_types' => true,
        'native_function_invocation' => ['include' => ['@compiler_optimized'], 'scope' => 'namespaced', 'strict' => true],
        'phpdoc_to_comment' => false,
        'phpdoc_align' => false,
        'phpdoc_summary' => false,
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'arguments', 'parameters']],
        'php_unit_method_casing' => false,
        'php_unit_test_class_requires_covers' => false,
        'php_unit_internal_class' => false,
        // Tests read as prose; the snake_case names are a deliberate house style.
        'php_unit_test_annotation' => false,
    ])
    ->setFinder($finder);
