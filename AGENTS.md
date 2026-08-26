# AGENTS.md — particle-academy/prism-harness

Durable agent sessions for Laravel: threads, modes, tool permissions, subagents.

> **Read [the shared guide](https://github.com/Particle-Academy/prism-parity/blob/main/docs/AGENTS.md)
> first** — the boundary, the gates, the binding decisions, the review skills.
> This file is only what is true of *this* repository.

## What this package owns, and what it must not absorb

It owns **the conversation**: a thread that survives the request, the mode a
session is running in, which tools that session may reach, and subagents.

It does **not** own retrieval — `prism-memory` reads threads and stores derived
representations, and the two compose through Prism's own message value objects
rather than depending on each other. Keep it that way: a hard dependency in
either direction means you cannot have sessions without memory or memory without
sessions, and both are reasonable things to want.

## The fancy-flow bridge is the sharpest edge in the repo

`src/Flow/` bridges this package to `particle-academy/fancy-flow-php`, and the
whole arrangement is unusual enough to be worth understanding before touching.

**It is `suggest`, never `require`.** fancy-flow needs PHP 8.4; this package
supports 8.2. A hard dependency would drop two supported versions to gain an
integration most consumers do not use. Nothing autoloads `Flow/` unless an
application wires it.

**Which means the contracts are MIRRORED, and the mirror is the risk.**
Because the real package cannot be installed across the matrix, the interfaces
are declared locally when absent:

- `tests/Pest.php` — `eval`'d interface declarations, so the tests run everywhere
- `stubs/fancy-flow.php` — the same shapes, for static analysis
- `phpstan.neon.dist` → `scanFiles` must list the stub
- `composer-require-checker.json` → the whitelist takes **exact symbol names**, not prefixes

**Nothing in CI notices if upstream changes.** That is inherent, not an
oversight: there is no version of this that catches a rename in a package we
cannot install. So the mirror is verified by hand against the real package —
installed temporarily — and the verification is re-run whenever fancy-flow
moves. `tests/Pest.php` records which version was checked. Update that note when
you re-check; a stale version number there is worse than none.

There is one test whose entire job is to fail loudly if either side is renamed
locally (`it('implements the contract fancy-flow actually declares')`). It
cannot catch an upstream rename. Do not mistake it for coverage that it is not.

**Currently behind: the mirror was verified against v0.41.0, and v0.42.0 has
shipped.** The change is additive — `tool_calls` moved from undeclared-but-read
to declared on the contract, which is why nothing here broke — but the mirror
and the note in `tests/Pest.php` are owed a re-verification against v0.42.0.
This is exactly the drift the arrangement is known to have; it is written down
here rather than left for the next person to discover.

## Two things about the seam that read as bugs and are not

**`withMaxSteps(1)` when tools are present.** fancy-flow's `AgentExecutor` owns
the loop — it invokes tools itself and calls back. Letting Prism run its own
loop as well would execute every tool twice and hide half the trace from the
workflow's audit.

**`tool_calls` is emitted on the result array.** As of fancy-flow-php v0.42.0
this is declared on their contract; before that it was undeclared and read
anyway, because the agent node's tool loop is dead without it. Both key
spellings their executor accepts are covered.

## Prism's ToolError is why `HarnessToolInvoker` looks defensive

`Prism\Prism\Tool::handle()` **catches** a handler exception and **returns** a
`ToolError` value rather than propagating. An invoker that only caught
exceptions would hand that object to the workflow as a successful result, where
a downstream node reads a failure as data.

Hence the `ToolError` branch, and hence a tool that is not on the allowlist
**throws** rather than returning "unknown tool" as a result: a model handed that
as a result treats it as information and burns the step budget guessing at an
allowlist it cannot see, while the workflow author who typed the wrong name sees
only a mediocre answer.

## The shipped-configuration trap, which this package taught the ecosystem

This package once shipped a Redis default that broke every fresh install on a
machine without Redis, and the suite was green throughout — because every test
set its stores explicitly, so not one of them exercised what an installing
application actually receives.

That became decision
[0012](https://github.com/Particle-Academy/prism-parity/blob/main/docs/decisions/0012-test-the-shipped-configuration.md).
Anything you add to `config/` needs a test that reads the **shipped** file.

## Gates

```sh
composer test && composer types && composer format
```

CI runs `tests`, `phpstan`, `formatting` and `require-checker` separately. Run
all of them.
