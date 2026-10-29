<?php

declare(strict_types=1);

// No root or SDK autoloading until every installed blob and the tree match.
require __DIR__.'/verify.php';
echo verifyFormulaFixture().PHP_EOL;
// A changed installed runtime file must fail before any dependency is loaded.
$stubFile = __DIR__.'/vendor/particle-academy/fancy-formula/php/src/StubEngine.php';
$original = file_get_contents($stubFile);
try {
    file_put_contents($stubFile, $original."\n// pin negative control\n");
    $process = proc_open([PHP_BINARY, __DIR__.'/verify.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start the pin negative control.');
    }
    $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) === 0 || ! str_contains($output, 'Formula dist blob differs: php/src/StubEngine.php')) {
        throw new RuntimeException('The pin verifier admitted changed SDK code.');
    }
} finally {
    file_put_contents($stubFile, $original);
}
echo 'PASS installed-runtime tamper negative control before SDK loading.'.PHP_EOL;
verifyFormulaFixture();
require dirname(__DIR__, 3).'/vendor/autoload.php';
foreach (['FormulaError', 'Violations', 'StubEngine'] as $class) {
    require __DIR__.'/vendor/particle-academy/fancy-formula/php/src/'.$class.'.php';
}
require __DIR__.'/vendor/particle-academy/fancy-formula/php/tests/Support/SchemaValidator.php';

use FancyFormula\StubEngine;
use FancyFormula\Tests\Support\SchemaValidator;
use Prism\Harness\Math\DoMathManager;
use Prism\Harness\Math\DoMathService;
use Prism\Harness\Math\FancyFormulaEngine;
use Prism\Harness\Math\FormulaPolicy;
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

$engine = FancyFormulaEngine::detect();
fixtureCheck($engine instanceof FancyFormulaEngine, 'Installed SDK was not detected.');
fixtureCheck($engine->version() === 'fancy-formula 0.0.0-stub', 'Stub identity changed.');
$policy = new FormulaPolicy(documents: ['pricing'], functionPolicy: ['SUM'], seed: 'host-seed');
$document = ['version' => 1, 'formulas' => ['total' => '=seats*10'], 'inputs' => [
    'seats' => ['type' => 'number', 'constraints' => ['min' => 1, 'max' => 100, 'required' => true]],
    'label' => ['type' => 'text', 'constraints' => ['required' => false]],
    'items' => ['type' => 'range', 'header' => false, 'columns' => ['text'], 'maxRows' => 10],
    'tableData' => ['type' => 'table', 'constraints' => ['columns' => ['text']]],
]];
$service = new DoMathService($policy, $engine);
$service->registerDocument('pricing', $document);
fixtureCheck($service->documentStatus('pricing') === 'CHECKED', 'Present SDK did not check registration.');
$inputs = ['seats' => 12, 'label' => '=WEBSERVICE("https://evil")', 'items' => [['one']], 'tableData' => ['columns' => ['sku'], 'rows' => [['one']]]];
$report = $service->evaluate(['document' => 'pricing', 'inputs' => $inputs]);
fixtureCheck($report['ok'] === true, 'Stub response failed.');
fixtureCheck($report['engine'] === 'fancy-formula 0.0.0-stub', 'Stub response lost identity.');
fixtureCheck($report['results']->total['value']['t'] === 'error' && $report['results']->total['value']['v'] === '#N/A', 'Stub returned a plausible formula result.');
fixtureCheck(str_contains($report['results']->total['value']['detail'], 'nothing was computed'), 'Stub detail was hidden.');
fixtureCheck(in_array('/traces/total/formula', $report['text_safety']['formula_like_paths'], true), 'Formula trace was not marked as JSON data.');
fixtureCheck($report['fingerprint'] === $service->evaluate(['document' => 'pricing', 'inputs' => $inputs])['fingerprint'], 'Identical stub requests changed fingerprint.');

// An independent request assembled with the frozen SDK shape proves raw values
// reached the correct fields without replacing declarations or constraints.
$expectedRequest = $document;
foreach ($inputs as $name => $value) {
    $field = match ($document['inputs'][$name]['type']) {
        'range' => 'rows', 'table' => 'table', default => 'value'
    };
    $expectedRequest['inputs'][$name][$field] = $value;
}
$expectedRequest['limits'] = $policy->limits;
$expectedRequest['trace'] = 'lookups';
$schema = new SchemaValidator(__DIR__.'/vendor/particle-academy/fancy-formula/schema');
$errors = $schema->validate(json_decode(json_encode($expectedRequest, JSON_THROW_ON_ERROR)), 'request.schema.json');
fixtureCheck($errors === [], 'Fixture request violates the actual frozen SDK schema: '.implode('; ', $errors));
$native = (new StubEngine)->evaluate($expectedRequest);
fixtureCheck($report['fingerprint'] === $native['fingerprint'], 'Input declarations, constraints or values changed in the adapter.');
fixtureCheck($report['results'] == $native['results'] && $report['traces'] == $native['traces'], 'Native SDK result or trace changed.');
fixtureCheck($service->evaluate(['document' => 'other'])['error']['code'] === 'DO_MATH_DOCUMENT_NOT_ALLOWED', 'Unknown document reached the SDK.');
fixtureCheck($service->evaluate(['document' => $document])['error']['code'] === 'DO_MATH_AD_HOC_DISABLED', 'Ad-hoc mode enabled by default.');
fixtureCheck($service->evaluate(['document' => 'pricing', 'seed' => 'model'])['error']['code'] === 'DO_MATH_REQUEST_INVALID', 'Model changed host seed.');
$wire = (new DoMathTool($service))->tool()->handle(document: 'pricing', inputs: $inputs);
fixtureCheck(is_string($wire) && json_decode($wire, true, flags: JSON_THROW_ON_ERROR)['engine'] === $engine->version(), 'Tool did not return a normal JSON result.');

$silent = new DoMathService(new FormulaPolicy(documents: ['pricing'], trace: 'none'), $engine);
$silent->registerDocument('pricing', $document);
fixtureCheck($silent->evaluate(['document' => 'pricing'])['traces'] == new stdClass, 'Trace none was expanded.');
$small = new DoMathService(new FormulaPolicy(documents: ['pricing'], maxOutputBytes: 512, maxTraceBytes: 64), $engine);
$small->registerDocument('pricing', $document);
fixtureCheck($small->evaluate(['document' => 'pricing'])['error']['code'] === 'FORMULA_OUTPUT_LIMIT', 'Native stub output escaped host bounds.');
$adhoc = new DoMathService(new FormulaPolicy(allowAdHoc: true), $engine);
fixtureCheck($adhoc->evaluate(['document' => $document])['ok'] === true, 'Explicit ad-hoc path did not check and evaluate with the SDK.');

// Presence sentinel only: no SDK API is mirrored or guessed. The REAL installed
// StubEngine is still present, so falling back to it would make this test fail.
class FormulaEngineConstructionTrap
{
    public function __construct()
    {
        throw new RuntimeException('Unknown real Engine was constructed.');
    }
}
class_alias(FormulaEngineConstructionTrap::class, 'FancyFormula\\Engine');
fixtureCheck(FancyFormulaEngine::detect() === null, 'Unknown real Engine fell back to the stub.');
fixtureCheck(FancyFormulaEngine::unavailableDetail() === 'engine_factory_required', 'Unknown real Engine lacks the factory explanation.');
$config = ['tools' => ['do_math' => ['documents' => ['pricing']]]];
$unknown = (new DoMathManager($config))->forAgent();
$unknown->registerDocument('pricing', $document);
fixtureCheck($unknown->documentStatus('pricing') === 'UNCHECKED', 'Unknown engine checked a document.');
fixtureCheck($unknown->evaluate(['document' => 'pricing'])['error']['detail'] === 'engine_factory_required', 'Unknown real Engine did not fail closed.');
$override = (new DoMathManager($config, fn (FormulaPolicy $policy) => $engine))->forAgent();
$override->registerDocument('pricing', $document);
fixtureCheck($override->evaluate(['document' => 'pricing'])['ok'] === true, 'Host factory failed to take precedence.');
echo 'PASS '.$assertions.' actual Packagist SDK assertions; stub only, no formula results.'.PHP_EOL;
