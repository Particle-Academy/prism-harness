# doMath preview

**stub engine: no formula results until fancy-formula P1**

The optional `DoMathService`, engine port and tool belong to Harness. Installing
Harness enables no tool and adds no engine dependency; PHP 8.2 remains supported.
On PHP 8.4+, install the engine from Packagist:

```sh
composer require particle-academy/fancy-formula
```

**0.0.1 = stub, real results from 0.1.0.** This preview is verified against
0.0.1. Its `check()` returns no diagnostics; every result is a `#N/A` error value
with a stub explanation. CHECKED means the installed engine accepted the document.
The stub does not prove formula validity, constraints or numerical behavior.
Every evaluation reports `fancy-formula 0.0.0-stub` and its native fingerprint.

## Host configuration

Configure named agents independently. The legacy equivalent is
`prism-harness.tools.do_math`; named agents inherit no legacy document authority.
Keep your existing model and other mode settings.

```php
// Inside config/prism-harness.php's existing 'agents' collection:
'support' => [
    'owner' => 'my-app.support',
    'default' => 'reply',
    'tools' => ['do_math' => [
        'documents' => ['pricing'],
        'allow_ad_hoc' => false,
        'function_policy' => ['SUM', 'IF'],
        'limits' => ['maxSteps' => 100000, 'wallClockMs' => 500],
        'max_output_bytes' => 65536,
        'max_trace_bytes' => 32768,
        'trace' => 'lookups',
        'seed' => 'host-selected-seed',
    ]],
    'modes' => ['reply' => ['tools' => ['doMath']]],
],
```

At host boot or save time, register documents before serving calls:

```php
use Prism\Harness\Math\DoMathManager;

app(DoMathManager::class)->forAgent('support')->registerDocument('pricing', [
    'version' => 1,
    'formulas' => ['total' => '=seats*10'],
    'inputs' => ['seats' => ['type' => 'number', 'constraints' => ['min' => 1, 'max' => 100]]],
]);
```

Each registration is checked. `FormulaDocumentRejected::result->report` carries
the original failed engine check report. Host policy or document-shape refusals
from `documentPolicy()` throw `FormulaRequestRefused` instead; both exceptions
are host registration failures. Failed replacement retains the previous checked
document. Registration snapshots JSON data against later mutable host aliases.
Give tenant-specific data separate service instances rather than sharing a
worker registry across tenants.

The model calls `{document: "pricing", inputs: {seats: 12}}`. Inputs provide raw
values, never replacement types or constraints. Fancy owns syntax, functions,
constraints, native error caps and violation order. Prism performs no formula
parsing, evaluation, fallback arithmetic, network or file access.

Without an engine, tools still register and documents remain UNCHECKED. Every
invocation, including ad-hoc or invalid arguments, returns a normal result:

```json
{"ok":false,"engine":null,"error":{"code":"FORMULA_ENGINE_UNAVAILABLE","package":"particle-academy/fancy-formula"}}
```

Availability is fixed at service construction. After installing the SDK,
**restart workers and re-register documents**. An existing UNCHECKED document
never starts evaluating because a package appeared later.

## Host engine factory

The primary construction path is a host factory. In an application provider's
`register()` method, bind a Closure returning Harness's `FormulaEngine` port:

```php
use Prism\Harness\Contracts\FormulaEngine;
use Prism\Harness\Math\DoMathManager;
use Prism\Harness\Math\FormulaPolicy;

$this->app->singleton(DoMathManager::ENGINE_FACTORY, fn () =>
    fn (FormulaPolicy $policy): FormulaEngine => new MyFormulaEngine($policy)
);
```

The factory receives host `functionPolicy`, `limits`, `clock` and `seed`.
The port's check/evaluate methods also receive effective narrowed policy. Models
never construct engines or providers. An agent can alternatively set a host
Closure in `engine_factory`; container bindings support cached configuration.

`FancyFormulaEngine::fromEngine($sdk, $version)` wraps a host-constructed SDK and
normalizes arrays or reports with `toArray()`. Hosts must construct SDK Options
from host policy and an explicit provider allowlist. No external provider is
registered automatically. Without a factory, the preview discovers the verified
no-argument StubEngine. A real Engine requires a factory; otherwise the result
is `FORMULA_ENGINE_UNAVAILABLE` with `detail: "engine_factory_required"`.
Automatic Options construction awaits verification of the concrete 0.1.0 API.
The adapter never constructs a bare real Engine or falls back to the stub.

## Limits, output and permissions

`allow_ad_hoc: true` explicitly enables model-supplied documents for that agent.
Every ad-hoc call is checked. Clock, seed, functions and providers stay host-owned;
requests may only supply `document`, `inputs` and tighter `limits`.

Engine limits use Fancy's v1 keys: `maxFormulaBytes`, `maxDepth`, `maxFormulas`,
`maxRangeCells`, `maxInputCells`, `maxArrayCells`, `maxLambdaDepth`, `maxSteps`,
`maxTextLength`, `wallClockMs`, `maxTraceSteps`. Per-call `maxOutputBytes` and
`maxTraceBytes` can reduce the host's serialized output bounds. The minimum
output envelope is 512 bytes. Document traces may narrow full to lookups or none.

Oversized successes, traces and native capped errors fail closed with
`FORMULA_OUTPUT_LIMIT`, original `engine_code` and violation counts. Prism never
truncates violations itself. Otherwise errors, warnings, error values and traces
are preserved. Native failures may have null fingerprints because the engine
contract supplies none.

Output is JSON data. Strings beginning `=`, `+`, `-`, `@`, tab or CR keep their
bytes and are marked by JSON Pointer in `text_safety.formula_like_paths`.
`text_safety.complete: true` means every such string is marked (at most 32).
If more exist in results, traces, warnings or errors, the entire report fails
closed with `FORMULA_OUTPUT_LIMIT` and `text_safety.complete: false`; the original
payload and partial path list are omitted. Render strings as text; explicitly
write text cells when exporting a spreadsheet. These markers do not escape CSV
or HTML.

`doMath` uses ordinary offer/call abilities and mode approval settings. Named
modes must list it explicitly. Effective-tool inspection writes no session,
thread or address rows.

## Integration checks

Normal PHP 8.2/8.3/8.4 lanes run without an SDK. A separate PHP 8.4 fixture in
`tools/engine-fixture/fancy-formula` installs exact Packagist 0.0.1 with scripts,
plugins and autoload generation disabled. Before loading SDK code, `run.php`
verifies the dist reference and every blob against commit
`acd37f3ecefb6dffdb26e5f650f2c9f516f32d89`, tree
`728380ebe956f9deaebf28c3fbe5f89238f5b76e`. This proves stub integration, not
real formula results.
