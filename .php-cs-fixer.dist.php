<?php

declare(strict_types=1);

/**
 * PSR-12 plus reguły, które w tym projekcie wynikają z konwencji kodu:
 * wymuszony strict_types, importy zamiast pełnych nazw, spójna składnia tablic.
 */
$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/bin', __DIR__ . '/tests'])
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        'declare_strict_types' => true,
        'array_syntax' => ['syntax' => 'short'],
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'no_unused_imports' => true,
        'single_quote' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'arguments', 'parameters']],
        'phpdoc_align' => false,
        'native_function_invocation' => false,
    ])
    ->setFinder($finder);
