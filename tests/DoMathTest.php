<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Prism\Harness\AgentRuntime;
use Prism\Harness\Math\DoMathManager;
use Prism\Harness\Math\DoMathService;
use Prism\Harness\Math\FancyFormulaEngine;
use Prism\Harness\Math\FormulaDocumentRejected;
use Prism\Harness\Math\FormulaPolicy;
use Prism\Harness\PrismHarness;
use Prism\Harness\PrismHarnessServiceProvider;
use Prism\Harness\Tools\DoMathTool;
use Prism\Harness\Tools\ToolAuthorizer;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Tests\Fixtures\FormulaProbeEngine;
use Tests\Fixtures\Participant;

function mathDocument(): array
{
    return ['version' => 1, 'formulas' => ['total' => '=seats*10'], 'inputs' => [
        'seats' => ['type' => 'number', 'constraints' => ['min' => 1, 'max' => 100, 'required' => true]],
        'label' => ['type' => 'text', 'constraints' => ['required' => false]],
    ]];
}

function mathService(?FormulaProbeEngine $engine = null, ?FormulaPolicy $policy = null): DoMathService
{
    $service = new DoMathService($policy ?? new FormulaPolicy(documents: ['pricing']), $engine);
    $service->registerDocument('pricing', mathDocument());

    return $service;
}

it('ships disabled with no SDK dependency or fake SDK symbols', function (): void {
    $config = require __DIR__.'/../config/prism-harness.php';
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true, flags: JSON_THROW_ON_ERROR);
    expect($config['tools']['do_math'])->toBeNull()
        ->and($config['agents'])->toBe([])
        ->and($composer['require']['php'])->toBe('^8.2')
        ->and($composer['require'])->not->toHaveKey('particle-academy/fancy-formula')
        ->and($composer['require-dev'])->not->toHaveKey('particle-academy/fancy-formula')
        ->and($composer['suggest'])->toHaveKey('particle-academy/fancy-formula')
        ->and(class_exists('FancyFormula\\StubEngine'))->toBeFalse()
        ->and(class_exists('FancyFormula\\Engine'))->toBeFalse()
        ->and(FancyFormulaEngine::detect())->toBeNull()
        ->and(app(ToolRegistry::class)->names())->not->toContain('doMath');
});

it('registers unchecked and returns the exact normal error without parsing absent-engine calls', function (): void {
    $service = new DoMathService(new FormulaPolicy(documents: ['pricing']));
    $poison = new class implements JsonSerializable
    {
        public function jsonSerialize(): mixed
        {
            throw new RuntimeException('must not execute');
        }
    };
    $service->registerDocument('pricing', ['formula' => $poison]);
    $expected = ['ok' => false, 'engine' => null, 'error' => ['code' => 'FORMULA_ENGINE_UNAVAILABLE', 'package' => 'particle-academy/fancy-formula']];
    expect($service->documentStatus('pricing'))->toBe('UNCHECKED');
    foreach ([[], ['document' => 'forbidden'], ['document' => ['formulas' => $poison], 'clock' => $poison], ['inputs' => $poison]] as $request) {
        expect($service->evaluate($request))->toBe($expected);
    }
    $wire = (new DoMathTool($service))->tool()->handle(document: ['formulas' => $poison], seed: 'model');
    expect(json_decode($wire, true, flags: JSON_THROW_ON_ERROR))->toBe($expected);
});

it('requires a new manager and fresh registration after engine availability changes', function (): void {
    $config = ['tools' => ['do_math' => ['documents' => ['pricing']]]];
    $old = new DoMathManager($config);
    $service = $old->forAgent();
    $service->registerDocument('pricing', mathDocument());
    $engine = new FormulaProbeEngine;
    $fresh = new DoMathManager($config, fn (FormulaPolicy $policy) => $engine);
    expect($old->forAgent())->toBe($service)
        ->and($service->evaluate(['document' => 'pricing'])['error']['code'])->toBe('FORMULA_ENGINE_UNAVAILABLE')
        ->and($fresh->forAgent()->evaluate(['document' => 'pricing'])['error']['code'])->toBe('DO_MATH_DOCUMENT_UNCHECKED')
        ->and($engine->evaluations)->toBe([]);
    $fresh->forAgent()->registerDocument('pricing', mathDocument());
    expect($fresh->forAgent()->evaluate(['document' => 'pricing'])['ok'])->toBeTrue()
        ->and($engine->checks)->toHaveCount(1);
});

it('checks at registration and preserves an invalid document report without evaluation', function (): void {
    $engine = new FormulaProbeEngine;
    $engine->checkReport = ['ok' => false, 'engine' => 'prism-port-probe', 'spec' => '1', 'diagnostics' => [
        ['code' => 'FORMULA_PARSE', 'message' => 'native refusal', 'at' => 8],
    ]];
    $service = new DoMathService(new FormulaPolicy(documents: ['pricing']), $engine);
    try {
        $service->registerDocument('pricing', mathDocument());
        $this->fail('Rejected document registered.');
    } catch (FormulaDocumentRejected $error) {
        expect($error->result->report)->toBe($engine->checkReport);
    }
    expect($service->documentStatus('pricing'))->toBeNull()
        ->and($engine->checks)->toHaveCount(1)->and($engine->evaluations)->toBe([]);
});

it('keeps the previously checked document when replacement fails', function (): void {
    $engine = new FormulaProbeEngine;
    $service = mathService($engine);
    $engine->checkReport = ['ok' => false, 'diagnostics' => [['code' => 'FORMULA_CHECK_FAILED']]];
    expect(fn () => $service->registerDocument('pricing', ['version' => 1, 'formulas' => ['x' => '=BAD()']]))->toThrow(FormulaDocumentRejected::class);
    $service->evaluate(['document' => 'pricing', 'inputs' => ['seats' => 12]]);
    expect($engine->evaluations[0]['document'])->toBe(mathDocument());
});

it('refuses model-selected authority before any check or evaluation', function (array $request, string $code): void {
    $engine = new FormulaProbeEngine;
    $service = mathService($engine);
    $checks = count($engine->checks);
    expect($service->evaluate($request)['error']['code'])->toBe($code)
        ->and($engine->checks)->toHaveCount($checks)->and($engine->evaluations)->toBe([]);
})->with([
    'unknown document' => [['document' => 'other'], 'DO_MATH_DOCUMENT_NOT_ALLOWED'],
    'padded document' => [['document' => 'pricing '], 'DO_MATH_DOCUMENT_NOT_ALLOWED'],
    'path name' => [['document' => '../pricing'], 'DO_MATH_DOCUMENT_NOT_ALLOWED'],
    'adhoc default' => [['document' => ['version' => 1, 'formulas' => ['x' => '=1']]], 'DO_MATH_AD_HOC_DISABLED'],
    'top formula' => [['document' => 'pricing', 'formulas' => ['x' => '=WEBSERVICE("http://127.0.0.1")']], 'DO_MATH_REQUEST_INVALID'],
    'clock' => [['document' => 'pricing', 'clock' => 'tomorrow'], 'DO_MATH_REQUEST_INVALID'],
    'seed' => [['document' => 'pricing', 'seed' => 'model'], 'DO_MATH_REQUEST_INVALID'],
    'providers' => [['document' => 'pricing', 'providers' => ['file:///secret']], 'DO_MATH_REQUEST_INVALID'],
    'functions' => [['document' => 'pricing', 'function_policy' => ['WEBSERVICE']], 'DO_MATH_REQUEST_INVALID'],
    'wider steps' => [['document' => 'pricing', 'limits' => ['maxSteps' => 1000001]], 'DO_MATH_POLICY_WIDENING'],
    'wider output' => [['document' => 'pricing', 'limits' => ['maxOutputBytes' => 65537]], 'DO_MATH_POLICY_WIDENING'],
    'wider trace bytes' => [['document' => 'pricing', 'limits' => ['maxTraceBytes' => 32769]], 'DO_MATH_POLICY_WIDENING'],
    'unknown limit' => [['document' => 'pricing', 'limits' => ['seed' => 1]], 'DO_MATH_POLICY_WIDENING'],
    'fractional limit' => [['document' => 'pricing', 'limits' => ['maxSteps' => 1.5]], 'DO_MATH_POLICY_WIDENING'],
    'zero limit' => [['document' => 'pricing', 'limits' => ['wallClockMs' => 0]], 'DO_MATH_POLICY_WIDENING'],
    'impossible output' => [['document' => 'pricing', 'limits' => ['maxOutputBytes' => 1]], 'DO_MATH_REQUEST_INVALID'],
    'unknown input' => [['document' => 'pricing', 'inputs' => ['formula' => '=1']], 'DO_MATH_INPUT_NOT_ALLOWED'],
    'list inputs' => [['document' => 'pricing', 'inputs' => [1, 2]], 'DO_MATH_REQUEST_INVALID'],
]);

it('narrows engine and output caps while retaining host clock seed functions and input declarations', function (): void {
    $engine = new FormulaProbeEngine;
    $clock = fn () => ['local' => '2026-10-29T10:00:00', 'zone' => 'America/Chicago'];
    $policy = new FormulaPolicy(documents: ['pricing'], functionPolicy: ['SUM'], clock: $clock, seed: 'host-seed');
    $service = mathService($engine, $policy);
    $result = $service->evaluate(['document' => 'pricing', 'inputs' => ['seats' => 12, 'label' => '=WEBSERVICE("https://evil")'],
        'limits' => ['maxSteps' => 100, 'maxOutputBytes' => 2048, 'maxTraceBytes' => 1024]]);
    $call = $engine->evaluations[0];
    expect($result['ok'])->toBeTrue()->and($call['document'])->toBe(mathDocument())
        ->and($call['inputs'])->toBe(['seats' => 12, 'label' => '=WEBSERVICE("https://evil")'])
        ->and($call['policy']->limits['maxSteps'])->toBe(100)
        ->and($call['policy']->maxOutputBytes)->toBe(2048)->and($call['policy']->maxTraceBytes)->toBe(1024)
        ->and($call['policy']->clock)->toBe($clock)->and($call['policy']->seed)->toBe('host-seed')
        ->and($call['policy']->functionPolicy)->toBe(['SUM'])->and($engine->checks)->toHaveCount(2);
});

it('checks every opt-in adhoc call and passes engine refusal through without fallback', function (): void {
    $engine = new FormulaProbeEngine;
    $service = new DoMathService(new FormulaPolicy(allowAdHoc: true, functionPolicy: ['SUM']), $engine);
    $engine->checkReport = ['ok' => false, 'diagnostics' => [['code' => 'FORMULA_FUNCTION_NOT_ALLOWED', 'formula' => '=WEBSERVICE("file:///secret")']]];
    $request = ['document' => ['version' => 1, 'formulas' => ['x' => '=WEBSERVICE("file:///secret")']]];
    for ($i = 0; $i < 2; $i++) {
        expect($service->evaluate($request)['error'])->toBe($engine->checkReport);
    }
    expect($engine->checks)->toHaveCount(2)->and($engine->evaluations)->toBe([]);
});

it('rejects adhoc host-policy keys and expanded trace before checking', function (): void {
    $engine = new FormulaProbeEngine;
    $service = new DoMathService(new FormulaPolicy(allowAdHoc: true), $engine);
    foreach (['seed', 'clock', 'providers', 'function_policy', 'engine_factory'] as $key) {
        expect($service->evaluate(['document' => [...mathDocument(), $key => 'attacker']])['error']['code'])->toBe('DO_MATH_REQUEST_INVALID');
    }
    expect($service->evaluate(['document' => [...mathDocument(), 'trace' => 'full']])['error']['code'])->toBe('DO_MATH_POLICY_WIDENING')
        ->and($engine->checks)->toBe([]);
});

it('narrows document trace without expanding it again in the engine policy', function (): void {
    $engine = new FormulaProbeEngine;
    $service = new DoMathService(new FormulaPolicy(documents: ['pricing'], trace: 'full'), $engine);
    $service->registerDocument('pricing', [...mathDocument(), 'trace' => 'lookups']);
    $service->evaluate(['document' => 'pricing']);
    expect($engine->checks[0]['policy']->trace)->toBe('lookups')
        ->and($engine->evaluations[0]['policy']->trace)->toBe('lookups');
});

it('preserves native violations ordering truncation and warnings without reinterpretation', function (): void {
    $engine = new FormulaProbeEngine;
    $service = mathService($engine);
    $engine->report = ['ok' => false, 'code' => 'FORMULA_INPUT_CONSTRAINT', 'violations' => [
        ['input' => 'z', 'code' => 'REQUIRED'], ['input' => 'a', 'code' => 'RANGE'],
    ], 'truncatedViolations' => ['z' => 7], 'truncatedByRequestCap' => 2];
    expect($service->evaluate(['document' => 'pricing'])['error'])->toBe($engine->report);
    $engine->report = ['ok' => false, 'code' => 'FORMULA_CHECK_FAILED', 'diagnostics' => [['code' => 'FORMULA_PARSE', 'message' => 'original']]];
    expect($service->evaluate(['document' => 'pricing'])['error'])->toBe($engine->report);
    $engine->report = ['ok' => true, 'fingerprint' => 'sha256:'.str_repeat('b', 64), 'results' => (object) ['x' => ['t' => 'error', 'v' => '#N/A']],
        'traces' => (object) [], 'warnings' => [['code' => 'FORMULA_CURRENCY_MIXED']]];
    $result = $service->evaluate(['document' => 'pricing']);
    expect($result['results'])->toEqual($engine->report['results'])->and($result['warnings'])->toBe($engine->report['warnings'])
        ->and($result['engine'])->toBe('prism-port-probe')->and($result['fingerprint'])->toBe($engine->report['fingerprint']);
});

it('returns the approved bounded envelope for an already engine-capped native error', function (): void {
    $engine = new FormulaProbeEngine;
    $service = mathService($engine, new FormulaPolicy(documents: ['pricing'], maxOutputBytes: 512, maxTraceBytes: 64));
    $engine->report = ['ok' => false, 'code' => 'FORMULA_INPUT_CONSTRAINT', 'violations' => array_fill(0, 100, ['input' => 'seats', 'code' => 'RANGE', 'message' => str_repeat('x', 30)]),
        'truncatedViolations' => ['seats' => 400], 'truncatedByRequestCap' => 3];
    $result = $service->evaluate(['document' => 'pricing']);
    expect($result)->toBe(['ok' => false, 'engine' => 'prism-port-probe', 'fingerprint' => null, 'error' => [
        'code' => 'FORMULA_OUTPUT_LIMIT', 'engine_code' => 'FORMULA_INPUT_CONSTRAINT',
        'counts' => ['violations' => 100, 'truncatedViolations' => 400, 'truncatedByRequestCap' => 3], 'limit_bytes' => 512,
    ]])->and(strlen(json_encode($result, JSON_THROW_ON_ERROR)))->toBeLessThanOrEqual(512)
        ->and($engine->report['violations'])->toHaveCount(100);
});

it('bounds success traces separately and refuses oversized success output', function (): void {
    $engine = new FormulaProbeEngine;
    $service = mathService($engine, new FormulaPolicy(documents: ['pricing'], maxOutputBytes: 2048, maxTraceBytes: 64));
    $engine->report['traces'] = ['x' => str_repeat('t', 65)];
    expect($service->evaluate(['document' => 'pricing'])['error']['code'])->toBe('FORMULA_OUTPUT_LIMIT');
    $engine->report['traces'] = [];
    $engine->report['results'] = ['x' => str_repeat('v', 3000)];
    expect($service->evaluate(['document' => 'pricing'])['error']['code'])->toBe('FORMULA_OUTPUT_LIMIT');
});

it('marks every dangerous text prefix as JSON data and leaves its bytes intact', function (): void {
    $engine = new FormulaProbeEngine;
    $service = mathService($engine);
    $values = ['=HYPERLINK("https://evil")', '+SUM(1)', '-cmd', '@import', "\t=1", "\r=1"];
    $engine->report['results'] = (object) ['text' => $values];
    $engine->report['traces'] = (object) ['x' => ['formula' => '=WEBSERVICE("https://evil")']];
    $wire = (new DoMathTool($service))->tool()->handle(document: 'pricing');
    $result = json_decode($wire, true, flags: JSON_THROW_ON_ERROR);
    expect($result['results']['text'])->toBe($values)->and($result['text_safety']['format'])->toBe('json-data')
        ->and($result['text_safety']['formula_like_paths'])->toHaveCount(7)
        ->toContain('/results/text/0', '/results/text/5', '/traces/x/formula');
});

it('rejects executable or cyclic JSON objects without invoking serializers', function (): void {
    $engine = new FormulaProbeEngine;
    $service = mathService($engine);
    $poison = new class implements JsonSerializable
    {
        public function jsonSerialize(): mixed
        {
            throw new RuntimeException('serializer executed');
        }
    };
    expect($service->evaluate(['document' => 'pricing', 'inputs' => ['label' => $poison]])['error']['code'])->toBe('DO_MATH_REQUEST_INVALID');
    $engine->report['results'] = $poison;
    expect($service->evaluate(['document' => 'pricing'])['error']['code'])->toBe('DO_MATH_ENGINE_REPORT_INVALID');
    $cycle = new stdClass;
    $cycle->self = $cycle;
    $engine->report['results'] = $cycle;
    expect($service->evaluate(['document' => 'pricing'])['error']['code'])->toBe('DO_MATH_ENGINE_REPORT_INVALID');
    $engine->report['results'] = [];
    unset($engine->report['fingerprint']);
    expect($service->evaluate(['document' => 'pricing'])['error']['code'])->toBe('DO_MATH_ENGINE_REPORT_INVALID');
});

it('snapshots registered documents so later host aliases cannot change formulas', function (): void {
    $engine = new FormulaProbeEngine;
    $doc = mathDocument();
    $doc['names'] = (object) ['tax' => '=1'];
    $service = new DoMathService(new FormulaPolicy(documents: ['pricing']), $engine);
    $service->registerDocument('pricing', $doc);
    $doc['names']->tax = '=WEBSERVICE("file:///secret")';
    $doc['formulas']['total'] = '=BAD()';
    $service->evaluate(['document' => 'pricing']);
    expect($engine->evaluations[0]['document']['names']->tax)->toBe('=1')
        ->and($engine->evaluations[0]['document']['formulas']['total'])->toBe('=seats*10');
});

it('isolates named document authority and passes only host policy to the factory', function (): void {
    $policies = [];
    $factory = function (FormulaPolicy $policy) use (&$policies): FormulaProbeEngine {
        $policies[] = $policy;

        return new FormulaProbeEngine;
    };
    $manager = new DoMathManager(['tools' => ['do_math' => ['documents' => ['legacy']]], 'agents' => [
        'support' => ['tools' => ['do_math' => ['documents' => ['pricing'], 'seed' => 'host', 'function_policy' => ['SUM']]]],
        'other' => [],
    ]], $factory);
    expect($manager->configuredAgents())->toBe([null, 'support']);
    $support = $manager->forAgent('support');
    $support->registerDocument('pricing', mathDocument());
    expect($support->evaluate(['document' => 'legacy'])['error']['code'])->toBe('DO_MATH_DOCUMENT_NOT_ALLOWED')
        ->and(fn () => $manager->forAgent('other'))->toThrow(InvalidArgumentException::class)
        ->and($policies[0]->seed)->toBe('host')->and($policies[0]->functionPolicy)->toBe(['SUM']);
});

it('registers the configured named tool through ordinary offer call and approval controls without inspection writes', function (): void {
    config()->set('prism-harness.agents', ['support' => [
        'default' => 'reply', 'owner' => 'host.formula-test', 'authorize_tools' => true,
        'tool_ability' => ToolAuthorizer::ABILITY.'.support',
        'tool_call_ability' => ToolAuthorizer::CALL_ABILITY.'.support',
        'tools' => ['do_math' => ['documents' => ['pricing']]],
        'modes' => ['reply' => ['tools' => ['doMath'], 'requires_approval' => ['doMath']]],
    ]]);
    // Rebuild the same service provider bindings after test-time host config.
    app()->forgetInstance(DoMathManager::class);
    app()->forgetInstance(ToolRegistry::class);
    $provider = new PrismHarnessServiceProvider(app());
    $provider->register();
    $participant = Participant::create(['name' => 'formula owner']);
    Gate::define(ToolAuthorizer::ABILITY.'.support', fn () => true);
    Gate::define(ToolAuthorizer::CALL_ABILITY.'.support', fn () => false);
    $session = app(PrismHarness::class)->forAgent('support')->for($participant)->session('formula');
    $writes = [];
    DB::listen(function ($query) use (&$writes): void {
        if (preg_match('/\A\s*(insert|update|delete|replace)/i', $query->sql)) {
            $writes[] = $query->sql;
        }
    });
    $tools = app(AgentRuntime::class)->effectiveTools($session);
    expect($writes)->toBe([])->and(array_column($tools, 'name'))->toBe(['doMath']);
    $resolved = app(ToolRegistry::class)->resolve(['doMath'], $session);
    $authorizer = new ToolAuthorizer(app(GateContract::class), false, config('prism-harness.agents'));
    $offered = $authorizer->allowed($session, $resolved);
    expect($offered)->toHaveCount(1);
    $refusal = json_decode($offered[0]->handle(document: 'pricing'), true, flags: JSON_THROW_ON_ERROR);
    expect($refusal['allowed'])->toBeFalse();
    $fake = Prism::fake([TextResponseFake::make()->withText('done')]);
    $session->send('inspect pricing');
    $fake->assertRequest(function (array $requests): void {
        expect($requests[0]->tools())->toHaveCount(1)
            ->and($requests[0]->tools()[0]->name())->toBe('doMath')
            ->and($requests[0]->tools()[0]->hasApprovalConfigured())->toBeTrue();
    });
    Gate::define(ToolAuthorizer::ABILITY.'.support', fn () => false);
    expect($authorizer->allowed($session, $resolved))->toBe([]);
});

it('adapts host-constructed SDK DTO reports without changing their native payloads', function (): void {
    $sdk = new class
    {
        public array $requests = [];

        public function check(array $request): object
        {
            $this->requests[] = $request;

            return new class
            {
                public function toArray(): array
                {
                    return ['ok' => true, 'diagnostics' => []];
                }
            };
        }

        public function evaluate(array $request): object
        {
            $this->requests[] = $request;

            return new class
            {
                public function toArray(): array
                {
                    return ['ok' => false, 'code' => 'FORMULA_INPUT_CONSTRAINT', 'violations' => [['input' => 'seats', 'rule' => 'min']]];
                }
            };
        }
    };
    $adapter = FancyFormulaEngine::fromEngine($sdk, 'host-constructed-sdk');
    $service = new DoMathService(new FormulaPolicy(documents: ['pricing']), $adapter);
    $service->registerDocument('pricing', mathDocument());
    $result = $service->evaluate(['document' => 'pricing', 'inputs' => ['seats' => 0]]);
    expect($result['error'])->toBe(['ok' => false, 'code' => 'FORMULA_INPUT_CONSTRAINT', 'violations' => [['input' => 'seats', 'rule' => 'min']]])
        ->and($sdk->requests[1]['inputs']['seats']['constraints'])->toBe(['min' => 1, 'max' => 100, 'required' => true])
        ->and($sdk->requests[1]['inputs']['seats']['value'])->toBe(0);
});

it('resolves a host container factory at registration and keeps agent registries separate', function (): void {
    $engines = [];
    app()->instance(DoMathManager::ENGINE_FACTORY, function (FormulaPolicy $policy) use (&$engines): FormulaProbeEngine {
        return $engines[] = new FormulaProbeEngine;
    });
    config()->set('prism-harness.tools.do_math', ['documents' => ['pricing']]);
    config()->set('prism-harness.agents.support.tools.do_math', ['documents' => ['pricing']]);
    app()->forgetInstance(DoMathManager::class);
    $manager = app(DoMathManager::class);
    $manager->forAgent()->registerDocument('pricing', mathDocument());
    expect($manager->forAgent('support')->evaluate(['document' => 'pricing'])['error']['code'])->toBe('DO_MATH_DOCUMENT_UNCHECKED')
        ->and($engines)->toHaveCount(2)->and($engines[1]->checks)->toBe([]);
});
