# doMath

The optional `DoMathService`, engine port and tool belong to Harness. Installing
Harness enables no tool and adds no engine dependency; PHP 8.2 remains supported.
On PHP 8.4+, install the engine from Packagist:

```sh
composer require particle-academy/fancy-formula
```

**Real results from 0.1.0; 0.0.1 is a stub.** The verified 0.1.0 engine evaluates
formulas, checks constraints and returns native values and traces. Its first
slice supports the pricing set; unsupported functions return native diagnostics.
The legacy 0.0.1 `check()` returns no diagnostics; every result is a `#N/A` error value
with a stub explanation. CHECKED means the installed engine accepted the document.
The stub does not prove formula validity, constraints or numerical behavior.
Stub evaluations report `fancy-formula 0.0.0-stub`; real evaluations report
`fancy-formula 0.1.0`. Successful evaluations carry the native fingerprint.

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
        'clock' => ['localDateTime' => '2026-10-27T18:00:00', 'zone' => 'Europe/London'],
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

## Engine discovery and host factory

Without a factory, Harness prefers a compatible real `FancyFormula\Engine`
over `StubEngine`. Autodetection requires `SPEC === '1'`, an ENGINE label matching
`^fancy-formula 0\.\d+\.\d+(-[A-Za-z0-9.]+)?$`, and all five policy classes
(`Options`, `Limits`, `FixedClock`, `Seed`, `FunctionPolicy`). The contract is
verified against published 0.1.0; compatible releases keep working and every
result reports the actual ENGINE string. Harness constructs `Engine(new Options(...))` from the
host's `FormulaPolicy`: `Limits::defaults()->with(...$policy->limits)`,
`Seed::fromString($policy->seed)` when present, `FunctionPolicy::only(...)`
for an explicit list (including an empty list), otherwise `FunctionPolicy::all()`.
External providers are always `[]` in autodetection. Host limits above the SDK's
hard ceiling throw at application boot; they are never clamped. Requests may
only tighten these limits.

The host `clock` array must have exactly `localDateTime` and `zone`, both strings,
and becomes a `FixedClock`. A host Closure may return that same array; it runs
once per check/evaluate operation and Harness rebuilds the engine for each
operation. Wrong shapes and Closure exceptions surface as host configuration
exceptions at registration or call time. A null clock leaves Fancy's default:
it has no ambient clock, so `NOW` and `TODAY` are refused with
`FORMULA_NEEDS_CLOCK`. The default does not read nondeterministic wall time;
explicit fixed clocks give reproducible time formulas. A changing host Closure
can deliberately change results and fingerprints between operations.

For arbitrary Clock or ExternalProvider implementations, use a host factory.
It overrides autodetection. In an application provider's
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
registered automatically. Without a real engine, Harness discovers the verified
no-argument StubEngine. An unknown SPEC, malformed ENGINE label or partial SDK
requires a factory; otherwise the result
is `FORMULA_ENGINE_UNAVAILABLE` with `detail: "engine_factory_required"`.
Harness never constructs a bare real Engine or falls back to a stub when an
incompatible or partial Engine SDK is present.

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
`728380ebe956f9deaebf28c3fbe5f89238f5b76e`. This proves stub integration.

Alongside it, `tools/engine-fixture/fancy-formula-real` installs exact normal
Packagist 0.1.0 at published commit `9dcef871f0703167923f21a8720c44243d78e2d8`,
tree `27b3c56911e4eaa84b7e707b1aa6aa60d22745e9`. All 78 blobs and the full tree
are checked before autoload; a changed Engine file must be refused. Its runtime
is byte-identical to the reviewed API commit `9c30ae25c9386301edbfa1012a3810561a0f5c57`.
The fixture exercises real pricing arithmetic (120 units at 9.75 = 1170), native
constraint reports, host function exclusions, tighter-only limits, clock sampling,
factory-only external providers, output bounds and 32/33 marker boundaries.
