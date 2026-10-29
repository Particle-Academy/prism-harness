<?php

declare(strict_types=1);

// Verify the normal Packagist dist, including every blob, before any autoload.
require __DIR__.'/verify.php';
echo verifyFormulaFixture().PHP_EOL;
$engineFile = __DIR__.'/vendor/particle-academy/fancy-formula/php/src/Engine.php';
$original = file_get_contents($engineFile);
try {
    file_put_contents($engineFile, $original."\n// pin negative control\n");
    $process = proc_open([PHP_BINARY, __DIR__.'/verify.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start the pin negative control.');
    }
    $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) === 0 || ! str_contains($output, 'Formula dist blob differs: php/src/Engine.php')) {
        throw new RuntimeException('The pin verifier admitted changed SDK code.');
    }
} finally {
    file_put_contents($engineFile, $original);
}
echo 'PASS installed-runtime tamper negative control before SDK loading.'.PHP_EOL;
verifyFormulaFixture();
require dirname(__DIR__, 3).'/vendor/autoload.php';
spl_autoload_register(function (string $class): void {
    if (str_starts_with($class, 'FancyFormula\\')) {
        $file = __DIR__.'/vendor/particle-academy/fancy-formula/php/src/'.str_replace('\\', '/', substr($class, 13)).'.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

use FancyFormula\Engine;
use FancyFormula\ExternalProvider;
use FancyFormula\FunctionPolicy;
use FancyFormula\Limits;
use FancyFormula\Options;
use FancyFormula\ProviderContext;
use FancyFormula\Seed;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use Prism\Harness\Math\DoMathManager;
use Prism\Harness\Math\DoMathService;
use Prism\Harness\Math\FancyFormulaEngine;
use Prism\Harness\Math\FormulaDocumentRejected;
use Prism\Harness\Math\FormulaPolicy;
use Prism\Harness\Math\FormulaRequestRefused;
use Prism\Harness\PrismHarnessServiceProvider;
use Prism\Harness\Tools\DoMathTool;

$assertions = 0;
function fixtureCheck(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function fixtureService(array $document, array $options = []): DoMathService
{
    $service = (new DoMathManager(['tools' => ['do_math' => ['documents' => ['pricing'], ...$options]]]))->forAgent();
    $service->registerDocument('pricing', $document);

    return $service;
}

function fixtureThrows(Closure $operation, string $class, string $message): void
{
    try {
        $operation();
    } catch (Throwable $error) {
        fixtureCheck($error instanceof $class, $message.' (wrong exception: '.$error::class.')');

        return;
    }
    fixtureCheck(false, $message.' (did not throw)');
}

fixtureCheck(class_exists('FancyFormula\\StubEngine'), 'Published SDK no longer contains its legacy stub.');
fixtureCheck(FancyFormulaEngine::detect()->version() === Engine::ENGINE, 'Real engine did not win over the installed stub.');
$document = ['version' => 1, 'formulas' => [
    'unit_price' => '=VLOOKUP(qty,price_breaks,2,TRUE)',
    'total' => '=qty*unit_price',
], 'inputs' => [
    'qty' => ['type' => 'number', 'constraints' => ['integer' => true, 'min' => 1, 'max' => 500]],
    'price_breaks' => ['type' => 'range', 'header' => true, 'rows' => [['min_qty', 'unit_price'], [1, 12.5], [50, 11], [100, 9.75], [500, 8.4]]],
]];
$service = fixtureService($document, ['function_policy' => ['VLOOKUP'], 'seed' => 'quote-8812', 'limits' => ['maxSteps' => 2000000]]);
fixtureCheck($service->documentStatus('pricing') === 'CHECKED', 'Real engine did not check registration.');
$report = $service->evaluate(['document' => 'pricing', 'inputs' => ['qty' => 120]]);
fixtureCheck($report['ok'] === true, 'Pricing-shaped document failed.');
fixtureCheck($report['engine'] === 'fancy-formula 0.1.0', 'Real engine identity lost.');
fixtureCheck($report['results']['unit_price']['value'] === ['t' => 'number', 'v' => 9.75], '120 units must cost9.75 each.');
fixtureCheck($report['results']['total']['value'] === ['t' => 'number', 'v' => 1170], '120 *9.75 must be1170.');
$request = $document;
$request['inputs']['qty']['value'] = 120;
$request['trace'] = 'lookups';
$request['limits'] = [...FormulaPolicy::ENGINE_LIMITS, 'maxSteps' => 2000000];
$native = new Engine(new Options(limits: Limits::defaults()->with(maxSteps: 2000000), random: Seed::fromString('quote-8812'), functions: FunctionPolicy::only(['VLOOKUP'])));
$expected = $native->evaluate($request)->toArray();
fixtureCheck($report['fingerprint'] === $expected['fingerprint'], 'Host seed/limits/functions or raw input declarations changed.');
fixtureCheck($report['results'] === $expected['results'] && $report['traces'] === $expected['traces'], 'Native values/traces changed.');
fixtureCheck($report['fingerprint'] === $service->evaluate(['document' => 'pricing', 'inputs' => ['qty' => 120]])['fingerprint'], 'Identical fixed policy changed fingerprint.');
fixtureCheck($report['text_safety']['complete'] === true && in_array('/traces/total/formula', $report['text_safety']['formula_like_paths'], true), 'Real formula traces were not marked.');
$request['inputs']['qty']['value'] = 0;
$expectedFailure = $native->evaluate($request)->toArray();
$failure = $service->evaluate(['document' => 'pricing', 'inputs' => ['qty' => 0]]);
fixtureCheck($failure['error']['code'] === 'FORMULA_INPUT_CONSTRAINT', 'Native constraint refusal lost.');
fixtureCheck($failure['error'] === $expectedFailure, 'Native constraint report was changed or truncated.');
fixtureCheck($failure['fingerprint'] === null && $failure['engine'] === Engine::ENGINE, 'Native error metadata lost.');
fixtureCheck($service->evaluate(['document' => 'pricing', 'limits' => ['maxSteps' => 2000001]])['error']['code'] === 'DO_MATH_POLICY_WIDENING', 'Request widened the host limit.');
$steps = fixtureService(['version' => 1, 'formulas' => ['r' => '=SUM(1,2,3,4,5)']]);
$limited = $steps->evaluate(['document' => 'pricing', 'limits' => ['maxSteps' => 3]]);
fixtureCheck($limited['error']['code'] === 'FORMULA_LIMIT_STEPS' && $limited['error']['limit'] === 3, 'Request did not tighten the real engine budget.');
fixtureCheck(! isset($limited['results']), 'Limit abort leaked partial results.');
foreach ([[], ['SUM']] as $functions) {
    try {
        fixtureService($document, ['function_policy' => $functions]);
        fixtureCheck(false, 'Host function exclusion was ignored.');
    } catch (FormulaDocumentRejected $error) {
        fixtureCheck(in_array('FORMULA_REFUSED_FUNCTION', array_column($error->result->report['diagnostics'], 'code'), true), 'Host exclusion lost its native diagnostic.');
    }
}
foreach (['clock', 'seed', 'providers', 'function_policy', 'engine_factory'] as $key) {
    fixtureCheck($service->evaluate(['document' => 'pricing', $key => []])['error']['code'] === 'DO_MATH_REQUEST_INVALID', 'Model supplied host policy: '.$key);
}
fixtureCheck($service->evaluate(['document' => $document])['error']['code'] === 'DO_MATH_AD_HOC_DISABLED', 'Ad-hoc documents enabled by default.');
fixtureCheck($service->evaluate(['document' => 'other'])['error']['code'] === 'DO_MATH_DOCUMENT_NOT_ALLOWED', 'Unknown document was evaluated.');

// All hard ceilings are SDK-validated at detection, never clamped.
foreach (Limits::TABLE as $name => $pair) {
    fixtureThrows(fn () => (new DoMathManager(['tools' => ['do_math' => ['limits' => [$name => $pair[1] + 1]]]]))->forAgent(), InvalidArgumentException::class, 'Host limit was clamped: '.$name);
}
$app = new Application(sys_get_temp_dir());
$app->instance('config', new Repository(['prism-harness' => ['tools' => ['do_math' => ['limits' => ['maxSteps' => 50000001]]]]]));
$provider = new PrismHarnessServiceProvider($app);
$provider->register();
fixtureThrows(fn () => $provider->boot(), InvalidArgumentException::class, 'Bad opted-in host limit did not fail at provider boot.');

// Explicit clocks are deterministic; null has no ambient clock fallback.
$clockDocument = ['version' => 1, 'formulas' => ['r' => '=NOW()']];
$clock = ['localDateTime' => '2026-10-27T18:00:00', 'zone' => 'Europe/London'];
$clockService = fixtureService($clockDocument, ['clock' => $clock]);
$dated = $clockService->evaluate(['document' => 'pricing']);
fixtureCheck($dated['results']['r']['display'] === '2026-10-27T18:00:00', 'Host FixedClock was ignored.');
fixtureCheck($dated['traces']['r']['volatile']['clock'] === '2026-10-27T18:00:00 Europe/London', 'Host zone was lost.');
fixtureThrows(fn () => fixtureService($clockDocument), FormulaDocumentRejected::class, 'Null clock consulted ambient wall time.');
$reads = 0;
$throwClock = false;
$dynamic = fixtureService($clockDocument, ['clock' => function () use (&$reads, &$throwClock, $clock): array {
    $reads++;
    if ($throwClock) {
        throw new RuntimeException('host clock failed');
    }

    return $clock;
}]);
fixtureCheck($reads === 1, 'Clock was not sampled exactly once during check.');
$dynamicResult = $dynamic->evaluate(['document' => 'pricing']);
fixtureCheck($reads === 2 && $dynamicResult['fingerprint'] === $dated['fingerprint'], 'Clock was not rebuilt/sampled once for evaluate.');
$throwClock = true;
fixtureThrows(fn () => $dynamic->evaluate(['document' => 'pricing']), RuntimeException::class, 'Host clock exception was swallowed at call time.');
foreach ([[], ['local' => $clock['localDateTime'], 'zone' => 'UTC'], [...$clock, 'extra' => 'x'], ['localDateTime' => 12, 'zone' => 'UTC'], ['localDateTime' => '2026-02-30T00:00:00', 'zone' => 'UTC']] as $badClock) {
    fixtureThrows(fn () => fixtureService($clockDocument, ['clock' => $badClock]), InvalidArgumentException::class, 'Bad host clock became no clock.');
}
fixtureThrows(fn () => fixtureService($clockDocument, ['clock' => fn () => null]), InvalidArgumentException::class, 'Closure returned null instead of a clock array.');
fixtureThrows(fn () => fixtureService($clockDocument, ['clock' => fn () => throw new RuntimeException('host clock failed')]), RuntimeException::class, 'Host clock exception was swallowed at registration.');
$currentClock = $clock;
$changing = fixtureService($clockDocument, ['clock' => function () use (&$currentClock): array {
    return $currentClock;
}]);
$before = $changing->evaluate(['document' => 'pricing']);
$currentClock['localDateTime'] = '2026-10-28T18:00:00';
$after = $changing->evaluate(['document' => 'pricing']);
fixtureCheck($after['results']['r']['display'] === '2026-10-28T18:00:00' && $after['fingerprint'] !== $before['fingerprint'], 'Engine reused the previous operation clock.');
$wrongChannel = false;
$hostFailure = new FormulaRequestRefused('HOST_CLOCK_FAILURE', 'host-only failure');
$refusingClock = fixtureService($clockDocument, ['clock' => function () use (&$wrongChannel, $hostFailure, $clock): array {
    if ($wrongChannel) {
        throw $hostFailure;
    }

    return $clock;
}]);
$wrongChannel = true;
try {
    $refusingClock->evaluate(['document' => 'pricing']);
    fixtureCheck(false, 'Host clock exception became a model refusal result.');
} catch (RuntimeException $error) {
    fixtureCheck($error->getPrevious() === $hostFailure, 'Host clock exception lost its cause.');
}

// Autodetection never installs an external provider; only the host factory can.
$external = ['version' => 1, 'formulas' => ['r' => '=GOOGLEFINANCE("GOOG")*2']];
fixtureThrows(fn () => fixtureService($external), FormulaDocumentRejected::class, 'Autodetection enabled an external provider.');
$calls = 0;
$externalProvider = new class($calls) implements ExternalProvider
{
    public function __construct(public int &$calls) {}

    public function name(): string
    {
        return 'GOOGLEFINANCE';
    }

    public function call(array $args, ProviderContext $context): array
    {
        $this->calls++;

        return ['t' => 'number', 'v' => 101.5];
    }
};
$factory = fixtureService($external, ['engine_factory' => fn (FormulaPolicy $policy) => FancyFormulaEngine::fromEngine(new Engine(new Options(providers: [$externalProvider])), Engine::ENGINE)]);
$provided = $factory->evaluate(['document' => 'pricing']);
fixtureCheck($provided['results']['r']['value']['v'] === 203 && $calls === 1, 'Host factory did not override autodetection.');
fixtureCheck($provided['traces']['r']['external'][0]['fn'] === 'GOOGLEFINANCE', 'Factory provider lost its native trace.');

// Real output bytes remain data, with complete markers or a payload-free refusal.
$textDocument = ['version' => 1, 'formulas' => ['r' => '=label'], 'inputs' => ['label' => ['type' => 'text']]];
$texts = fixtureService($textDocument, ['trace' => 'none']);
foreach (['=', '+', '-', '@', "\t", "\r"] as $prefix) {
    $value = $prefix.'WEBSERVICE("https://example.invalid")';
    $text = $texts->evaluate(['document' => 'pricing', 'inputs' => ['label' => $value]]);
    fixtureCheck($text['results']['r']['value'] === ['t' => 'string', 'v' => $value], 'Real engine evaluated/changed a raw text input.');
    fixtureCheck($text['text_safety']['complete'] === true && $text['text_safety']['formula_like_paths'] === ['/results/r/value/v'], 'Real text output was not marked.');
}
$oversized = $texts->evaluate(['document' => 'pricing', 'inputs' => ['label' => str_repeat('x', 4000)], 'limits' => ['maxOutputBytes' => 512]]);
fixtureCheck($oversized['error']['code'] === 'FORMULA_OUTPUT_LIMIT' && ! isset($oversized['results']), 'Oversized real output did not fail closed.');
foreach ([32, 33] as $count) {
    $many = $textDocument;
    $many['formulas'] = [];
    for ($i = 0; $i < $count; $i++) {
        $many['formulas']['output_'.$i] = '=label';
    }
    $marked = fixtureService($many, ['trace' => 'none'])->evaluate(['document' => 'pricing', 'inputs' => ['label' => '=hostile()']]);
    if ($count === 32) {
        fixtureCheck($marked['ok'] === true && $marked['text_safety']['complete'] === true && count($marked['text_safety']['formula_like_paths']) === 32, '32 real outputs were not completely marked.');
    } else {
        fixtureCheck($marked['error']['code'] === 'FORMULA_OUTPUT_LIMIT' && $marked['text_safety'] === ['complete' => false], '33 real outputs were partially marked.');
        fixtureCheck(! isset($marked['results'], $marked['traces']) && ! str_contains(json_encode($marked), '=hostile()'), 'Marker overflow leaked original output.');
    }
}
$wire = (new DoMathTool($service))->tool()->handle(document: 'pricing', inputs: ['qty' => 120]);
fixtureCheck(is_string($wire) && json_decode($wire, true, flags: JSON_THROW_ON_ERROR)['results']['total']['value']['v'] === 1170, 'doMath tool did not return the real normal JSON result.');
echo 'PASS '.$assertions.' actual real-engine assertions; engine '.Engine::ENGINE.PHP_EOL;
