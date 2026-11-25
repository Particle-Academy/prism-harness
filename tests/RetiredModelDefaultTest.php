<?php

declare(strict_types=1);

use Prism\Harness\Context\SummarisingCompaction;

/*
 * A SHIPPED DEFAULT MUST NAME A MODEL THE PROVIDER STILL SERVES (G-369).
 *
 * `agent.model` is what every install without HARNESS_MODEL sends. When the
 * id it names is retired, every such install fails on its first call, and no
 * test that sets its own model notices. So this asks the question directly, of
 * the values a consumer actually gets: the config file as published, and the
 * constructor default of every class that names a model.
 *
 * Every Claude *-4-* id (and anything older) is retired here by the owner's
 * rule of 2026-11-25, so the pattern refuses the whole generation rather than
 * the one id that broke.
 */

function retiredClaudeModel(string $model): bool
{
    return preg_match('/^claude-(?:opus|sonnet|haiku|instant|fable)-(?:[1-4])(?:-|$)/', $model) === 1
        || preg_match('/^claude-[1-4](?:[.-]|$)/', $model) === 1;
}

it('recognises a retired id, so the guard below is not vacuous', function (): void {
    expect(retiredClaudeModel('claude-sonnet-4-5'))->toBeTrue()
        ->and(retiredClaudeModel('claude-haiku-4-5-20251001'))->toBeTrue()
        ->and(retiredClaudeModel('claude-opus-4-1'))->toBeTrue()
        ->and(retiredClaudeModel('claude-3-5-sonnet-latest'))->toBeTrue()
        ->and(retiredClaudeModel('claude-sonnet-5-5'))->toBeFalse()
        ->and(retiredClaudeModel('claude-haiku-5-5'))->toBeFalse();
});

it('ships an agent model default the provider still serves', function (): void {
    $previous = getenv('HARNESS_MODEL');
    putenv('HARNESS_MODEL');
    unset($_ENV['HARNESS_MODEL'], $_SERVER['HARNESS_MODEL']);

    try {
        // The published file, read fresh: what a consumer gets with no env set.
        $config = require dirname(__DIR__).'/config/prism-harness.php';
    } finally {
        if ($previous !== false) {
            putenv('HARNESS_MODEL='.$previous);
        }
    }

    $model = $config['agent']['model'];

    expect($model)->toBe('claude-sonnet-5-5')
        ->and(retiredClaudeModel($model))->toBeFalse();
});

it('ships a summarising model default the provider still serves', function (): void {
    $parameter = collect((new ReflectionMethod(SummarisingCompaction::class, '__construct'))->getParameters())
        ->first(fn (ReflectionParameter $p): bool => $p->getName() === 'model');

    expect($parameter)->not->toBeNull();

    $model = $parameter->getDefaultValue();

    expect($model)->toBe('claude-haiku-5-5')
        ->and(retiredClaudeModel($model))->toBeFalse();
});

it('names no retired Claude model anywhere in the shipped source or config', function (): void {
    $root = dirname(__DIR__);
    $files = [];

    foreach (['src', 'config'] as $dir) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
    }

    expect($files)->not->toBeEmpty();

    $hits = [];
    foreach ($files as $path) {
        preg_match_all('/claude-[a-z0-9.-]+/', (string) file_get_contents($path), $matches);
        foreach ($matches[0] as $id) {
            if (retiredClaudeModel(rtrim($id, '-.'))) {
                $hits[] = substr($path, strlen($root) + 1).': '.$id;
            }
        }
    }

    expect($hits)->toBe([]);
});
