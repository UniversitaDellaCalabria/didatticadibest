<?php

declare(strict_types=1);

// Stile PSR-12 per il codice OOP (src/, tests/): vendor/bin/php-cs-fixer fix  (o composer cs)
$finder = PhpCsFixer\Finder::create()->in([__DIR__ . '/src', __DIR__ . '/tests']);

return (new PhpCsFixer\Config())
    ->setRules(['@PSR12' => true, 'declare_strict_types' => true, 'no_unused_imports' => true, 'ordered_imports' => true])
    ->setRiskyAllowed(true)
    ->setFinder($finder);
