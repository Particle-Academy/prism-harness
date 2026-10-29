<?php

declare(strict_types=1);

// Discovery/version forwarding only: these Engine doubles claim no formula
// semantics. The parent fixture tests the actual published Engine separately.
require __DIR__.'/verify.php';
verifyFormulaFixture();
require dirname(__DIR__, 3).'/vendor/autoload.php';

use FancyFormula\Options;
use FancyFormula\Seed;
use Prism\Harness\Math\DoMathManager;
use Prism\Harness\Math\FancyFormulaEngine;

class FuturePatchEngine
{
    public const ENGINE = 'fancy-formula 0.1.7';

    public const SPEC = '1';

    public static int $constructed = 0;

    public function __construct(public object $options)
    {
        self::$constructed++;
        if (! $options instanceof Options || $options->limits->get('maxSteps') !== 120
            || $options->functions->allows('SUM') || $options->providers !== []
            || $options->clock->localDateTime() !== '2026-10-27T18:00:00'
            || $options->random->state() !== Seed::fromString('contract-probe')->state()) {
            throw new RuntimeException('Future-compatible Engine did not receive host Options.');
        }
    }

    public function check(array $request): array
    {
        return ['ok' => true, 'engine' => static::ENGINE, 'diagnostics' => []];
    }

    public function evaluate(array $request): array
    {
        return ['ok' => true, 'engine' => static::ENGINE, 'fingerprint' => 'sha256:'.str_repeat('b', 64), 'results' => [], 'traces' => []];
    }
}

class PrereleaseEngine extends FuturePatchEngine
{
    public const ENGINE = 'fancy-formula 0.1.7-rc.1';
}

class UnknownSpecEngine extends FuturePatchEngine
{
    public const SPEC = '2';
}

class MalformedEngine extends FuturePatchEngine
{
    public const ENGINE = 'other-formula 0.1.7';
}

class NewlineEngine extends FuturePatchEngine
{
    public const ENGINE = "fancy-formula 0.1.7\n";
}

$mode = $argv[1];
$class = match ($mode) {
    'future' => FuturePatchEngine::class,
    'prerelease' => PrereleaseEngine::class,
    'spec2' => UnknownSpecEngine::class,
    'malformed' => MalformedEngine::class,
    'newline' => NewlineEngine::class,
    'partial-Options', 'partial-Limits', 'partial-FixedClock', 'partial-Seed', 'partial-FunctionPolicy' => FuturePatchEngine::class,
    default => throw new RuntimeException('Unknown fixture contract row.'),
};
class_alias($class, 'FancyFormula\\Engine');
$missing = str_starts_with($mode, 'partial-') ? substr($mode, 8) : null;
spl_autoload_register(function (string $class) use ($missing): void {
    if (str_starts_with($class, 'FancyFormula\\') && $class !== 'FancyFormula\\'.$missing) {
        $file = __DIR__.'/vendor/particle-academy/fancy-formula/php/src/'.str_replace('\\', '/', substr($class, 13)).'.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
$options = ['documents' => ['version_probe'], 'function_policy' => [], 'limits' => ['maxSteps' => 120],
    'clock' => ['localDateTime' => '2026-10-27T18:00:00', 'zone' => 'Europe/London'], 'seed' => 'contract-probe'];
$service = (new DoMathManager(['tools' => ['do_math' => $options]]))->forAgent();
$service->registerDocument('version_probe', ['version' => 1, 'formulas' => ['total' => '=1']]);
$result = $service->evaluate(['document' => 'version_probe']);
if (in_array($mode, ['future', 'prerelease'], true)) {
    if ($result['ok'] !== true || $result['engine'] !== $class::ENGINE || FuturePatchEngine::$constructed !== 2) {
        throw new RuntimeException('Compatible SPEC1 engine was refused or lost its actual version.');
    }
} elseif ($service->documentStatus('version_probe') !== 'UNCHECKED' || FuturePatchEngine::$constructed !== 0
    || $result !== ['ok' => false, 'engine' => null, 'error' => ['code' => 'FORMULA_ENGINE_UNAVAILABLE', 'package' => 'particle-academy/fancy-formula', 'detail' => 'engine_factory_required']]
    || FancyFormulaEngine::detect() !== null) {
    throw new RuntimeException('Incompatible or partial SDK did not fail closed before construction.');
}
echo json_encode(['ok' => true, 'mode' => $mode, 'engine' => $result['engine']], JSON_THROW_ON_ERROR).PHP_EOL;
