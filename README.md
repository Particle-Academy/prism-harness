# Prism Harness

Durable agent sessions for Laravel — threads, modes, tool permissions and subagents on top of
[Prism](https://github.com/Particle-Academy/prism).

> **Working on this package?** Read **[`AGENTS.md`](AGENTS.md)** first — the boundary
> this package has to hold, the gates that must be green, and the traps that have
> already caught someone.
> `@link AGENTS.md`

## Optional doMath

The optional [doMath tool and plain service](docs/do-math.md) evaluate host-owned
formula documents through `particle-academy/fancy-formula`. Installing Harness
enables no tool and installs no engine. On PHP 8.4+, use
`composer require particle-academy/fancy-formula` from Packagist:
**Real results from 0.1.0; 0.0.1 is a stub.** Harness automatically constructs
a compatible SPEC 1 Engine (verified with 0.1.0) from host policy, with no external
providers. A host
factory overrides autodetection. Stub results identify themselves and
contain `#N/A`, never calculated numbers. Without an engine, the configured tool
returns `FORMULA_ENGINE_UNAVAILABLE` and registered documents remain UNCHECKED.

## Sessions

```php
$session = PrismHarness::for($user)->session('support');

$session->usingMode('plan')->usingModel('claude-sonnet-5-5');

$session->lock(function (Session $session) {
    // whatever must not happen twice
});
```

**Resolved, never held.** A Laravel request boots, serves and dies, so a session cannot be an
object kept in memory the way Mastra's is. Every call rebuilds one from a store, which is what
makes a fresh worker see the same mode, model and conversation as the request that set them.

### Named agents

Before using a named agent or ParticipantRef, run the package's additive
0001_01_01_000006_add_agent_addresses migration. It adds address-index tables and
nullable thread-address columns; existing history is not migrated into a named
agent. Applications using only legacy model participants continue to work on
the 0.18.1 schema without this migration. New address features refuse with the
migration's name when it is missing.

Choose a stable agent id in trusted application code. An id uses
[a-z][a-z0-9_-]{0,63}; a display/persona name is independent of that id. Omitting
it keeps the existing addresses. Named agents have separate session state,
threads, capabilities and default task lists.

Register tools and modes in the same namespace, with an owner:

```php
$tools = app(\Prism\Harness\Tools\ToolRegistry::class)->forAgent('support');
$tools->register((new \Prism\Prism\Tool)->as('lookup')->for('Look up an item')
    ->using(fn (): string => 'found'), 'app.support');
app(\Prism\Harness\Modes\ModeRegistry::class)->forAgent('support')
    ->register('reply', ['tools' => ['lookup']], 'app.support')->setDefault('reply');
$support = app(\Prism\Harness\PrismHarness::class)->forAgent('support');
$descriptors = $support->effectiveTools('reply', $user, 'case:42');
$session = $support->for($user)->session('case:42');
```

effectiveTools returns name/owner/source descriptors from the model offer path.
Inspection creates no session, thread or index entry, executes no handler and
makes no model/provider request or logical state change. Reads may physically
purge expired entries, exactly as an ordinary store read does. Factories and
tool-discovery providers run during resolution; explicit state writes refuse.
A mode's tool list is its authority ceiling; a caller can narrow it. A named
agent inherits no legacy tools/providers or modes. Builtins must be explicit.
Even a same-owner duplicate in a named namespace is refused. Intentional tool replacement uses
$tools->replace($replacement, 'app.support', 'app.support.v2'); mode replacement
also names its predecessor owner.

Legacy registries retain their published overwrite and resolution precedence
by default: providers override static tools, and factories override both.
Set prism-harness.registry.refuse_duplicates to true to opt into duplicate
refusal in the legacy namespace. Directly constructed tool/mode registries
accept the refuseDuplicates constructor option. Prefer this stricter option
when providers from several components share a registry: silent name shadowing
can change which implementation is offered. Named registries always refuse.

Named and ParticipantRef thread addresses are immutable. Legacy thread addresses
remain editable by default; set prism-harness.addresses.immutable_legacy_threads
to true to opt into the same immutability guard for those rows.

Configure scalar overrides under agents.support (using your agent's id):
provider, model, spend_ceiling, max_steps_ceiling,
max_output_tokens_per_call, lock_ttl, lock_wait, default_scope, default_task_list
and ephemeral_ttl. Unset values fall back globally; a nullable field's explicit
null clears its default. Step/output caps narrow the existing parent ledger and
budget. Authorization uses authorize_tools, tool_ability and tool_call_ability;
offer filtering and invocation checks both apply. A defined offer or call policy
with authorization disabled is refused.

Eloquent participants keep their existing ownership behavior; asActor binds a
separate host-selected actor. Non-model ParticipantRef(type, id) values require
a registered ParticipantAdapters actor resolver and never resolve class names
through morph relations. A child retains its parent's bound actor. Named writes
are indexed; legacy history is not implicitly migrated or backfilled.

### The two halves

State is split into named slots, because the halves have genuinely different requirements:

| Slot | Holds | Losing it means |
|---|---|---|
| `ephemeral` | active mode, selected model, run bookkeeping | falls back to a default |
| `durable` | threads, pending tool approvals | work is gone |

Configure them independently — Redis for the first, database for the second is the intended
shape:

Both default to `database`, so the package works on install with nothing to set up. Point the
ephemeral half at Redis when you have one — it is the better home for live session state, and
opting in beats a default that throws a connection error on a machine that never claimed to
run Redis:

```php
'stores' => [
    'ephemeral' => 'redis',      // recommended in production
    'durable'   => 'database',
],
```

### Why the durable slot is guarded

**A store that reports itself volatile is refused for durable state, loudly, at resolve time.**

Redis is the natural home for live session state, but the `redis` connection in a typical
Laravel app is a *cache* — something is entitled to flush it. The package cannot tell from the
inside whether yours is persistent, so `redis` reports Volatile by default and pointing the
durable slot at it throws `UnsafeStateConfiguration` with both ways out named.

This is not defensive theatre. A sibling project in this workspace kept XP de-duplication in a
cache a deploy could clear; a single `cache:clear` between two backfills would have silently
re-awarded every contribution, with nothing in the logs. The same mistake here loses a pending
tool approval — a half-executed action a human was asked to authorise — which does not degrade
to a default.

If your Redis really is durable (AOF or RDB), say so and it is allowed:

```php
'drivers' => [
    'redis' => [
        // An assertion about your infrastructure, not a preference.
        'durable' => true,
    ],
],
```

### Concurrency

Two workers can hold the same session at once — a queued job finishing a run while the user
sends another message is ordinary. `lock()` takes an exclusive lock and **throws
`SessionLocked` rather than running anyway** on timeout, since running anyway would defeat the
only thing it is for. Locks carry an expiry, so a worker that dies mid-run does not hold the
session shut forever.

## Threads

The first piece, and the one everything else needs. Prism 0.113 added a `Thread` contract —
a stored conversation it can read history from — and this package provides the Eloquent
implementation.

```php
use Prism\Harness\Models\Thread;

$thread = Thread::forParticipant($user, 'support');

$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-sonnet-5-5')
    ->withThread($thread)              // everything said so far
    ->withPrompt('And after that?')    // the turn being taken now
    ->asText();

$thread->record($response->messages);  // the full exchange, tool steps included
```

`$response->messages` carries every step of a tool loop, so recording a turn is one call and
a run interrupted mid-tool resumes exactly where it stopped.

**Addressed by participant *and* scope.** One user holds several unrelated conversations at
once — a support chat and a coding session are not the same thread — so the scope is part of
the address, not a label hung off it. `Thread::forParticipant($user, 'coding')` resolves a
different conversation from `'support'`, and a fresh worker asking for the same address lands
on the same thread rather than starting a new one.

**`::sub::` in a scope is reserved for subagent children.** The subagent runner addresses a
child at `{parent scope}::sub::{name}`, and a session whose scope has a `::sub::` segment is
treated as a child: with no step ceiling saved by the runner it gets no model steps (it acts
on decisions, and a new message to it is refused). Do not build host scopes that can contain
`::sub::`, from user input or otherwise.

**One live conversation per address is enforced by the database.** The nullable
`live_key` key is derived by the model and uniquely indexed. A competing first-use
insert is rejected, and resolution returns the winning row. `retire()` clears the key
in the same write that stamps retirement, keeping the old conversation readable by id
and freeing the address for a new one. Use the model to write thread rows; raw inserts
and bulk updates bypass its derived-key handling.

When upgrading, pause thread writers while running the new migration and deploying the
updated model. The migration backfills live rows and leaves retired history alone. If
duplicate live addresses already exist, it refuses before changing the schema: review
their histories and explicitly retire the unwanted rows, then rerun. Address keys use
exact strings, including scope case; applications that relied on case-insensitive
database aliases should canonicalize scopes consistently before upgrading.

`Thread::shared('admin-review')` resolves a participant-less conversation by scope
alone, with the same uniqueness and retirement behavior. It makes no claim about who
may read it: an admin-only conversation still needs the host's authorization check.
An agency-wide conversation already has a participant—the agency—so use
`Thread::forParticipant($agency, 'teachers-aid')` for that address. Separate shared
audiences can use separate scopes.

**The storage format is ours, not Prism's.** Prism's `toArray()` exists to feed telemetry and
debug output and is free to change for presentational reasons; persistence cannot be, so it
does not ride on it. Two consequences worth knowing:

- Content parts are stored with their concrete class. Prism's `Media::toArray()` records
  where a file lives but not what it *is* — an Image and a Document serialise identically —
  so without that, every attachment would come back as whatever type we guessed.
- Anything that cannot be stored or rebuilt faithfully throws `UnmappableContent` rather than
  being dropped. A thread is replayed to the model as context, so a silent omission does not
  surface as an error; it surfaces much later as a model that has forgotten something.

### Who can write your thread rows

Prism's contract warns that stored history is replayed to the model, so it is only as
trustworthy as the store it came from. Threads make that concrete: rebuilding an attachment
resolves whatever locator was recorded, so a row carrying a `local_path` or `url` becomes a
file read or an outbound fetch at replay time. Rehydration is restricted to Prism `Media`
subclasses, but the locator itself is data.

None of this is reachable without write access to your database — at which point the thread
table is not your first problem. It matters because it sets where the boundary is: treat
`harness_thread_messages` as trusted storage, and never let request input write directly to
it.

## Context window

A long conversation eventually costs more to replay than it is worth. The harness replays the
whole thread by default — that has not changed and will not change on an upgrade — and gives you
the structure to shorten it when you decide to.

**The rule is yours.** There is no correct answer to hand you: a support chat can drop old turns
freely, an audit cannot drop the tool results its final count depends on, a coding agent wants
neither. So the harness owns *when* compaction runs, *what happens to what it removes*, and the
invariants no strategy may break. You own the rule.

```php
// config/prism-harness.php — the shipped strategy, deterministic and no model call
'context' => ['keep_recent' => 200],
```

```php
// or your own
class KeepWhatMatters implements Prism\Harness\Contracts\CompactionStrategy
{
    public function compact(array $messages): CompactionOutcome
    {
        return new CompactionOutcome(kept: /* ... */, evicted: /* ... */);
    }
}

$this->app->singleton(CompactionStrategy::class, KeepWhatMatters::class);
```

### A summary, when a bounded window is not enough

`SummarisingCompaction` replaces the older half with a summary a model writes,
and **rewrites that summary** each time rather than appending — an append-only
précis grows without bound while looking like it compacts.

```php
'context' => ['summarise_with' => 'claude-haiku-5-5', 'keep_recent' => 20],
```

**Try `keep_recent` alone first.** It costs nothing, cannot rewrite anything, and
is entirely predictable. This one bills a model call on every turn that fires —
and a second one on turns where the summary comes back over its word budget.

**The budget is a request, and what to do about that is yours.** `summary_words`
reaches the model as "in at most N words" inside the prompt, and a model does not
have to grant a request. Measured live with nothing checking: a stated 15 words
came back at 92 and at 346, and the default 60 came back at 205 — the 346 having
re-stated every exchange one by one, which is the unbounded growth rewriting is
supposed to prevent.

So there are **two dials, deliberately separate**: how big (`summary_words`) and
how that is enforced (`SummaryBudget`).

| budget | what it does | costs |
|---|---|---|
| `RetryOnce` *(default)* | counts, and asks once more when over — took a 60-word budget from 205 words to 61 | a second call on turns that overshoot |
| `AskOnly` | nothing; the behaviour before v0.6.0 | one call, and the summary may be 4x what you asked |
| `TruncateTo` | guarantees the bound by cutting | free, and **can hand the model a fragment that reads whole** |

```php
$this->app->bind(SummaryBudget::class, fn () => new TruncateTo);
```

`RetryOnce` is allowed to miss, and does — in the same measured runs, arms
finished at 118 and 84 words against a budget of 60, meaning both calls came back
over. It cannot make things worse: a failed or longer second answer leaves the
first standing, because a summary over budget is a cost problem and no summary at
all is a lost conversation. Write your own if you would rather count tokens, or
try harder, or give up sooner — the one rule is **never return an empty string**,
and the strategy treats a budget that does exactly like a failed model call
rather than trusting it.

**And it is the strategy most likely to lose something that matters.**
["Governance Decay"](https://arxiv.org/pdf/2606.22528) puts summarisation-based
compaction above 40% safety violations, against 25–30% for truncation and 15–20%
for semantic compression, because constraints stated early are progressively lost
with nothing reporting it. **Bind an `EvictionSink` alongside it** — a summary is
a lossy view, and this only becomes safe when something else still holds the
original.

If the summary call fails, or comes back empty, the whole conversation is kept
rather than the older half being dropped with nothing standing in for it.

**Compaction shortens the view, never the storage.** Every row stays; what changes is which of
them the model sees. Change the strategy and the next turn sees a different window over the same
unaltered history.

### Choose a sink in the same breath

A strategy returns what it evicted, and the harness hands it to an `EvictionSink`. **This is what
separates compaction from loss**, and the default sink discards — so compaction with no sink is
context clearing without recovery, which is the configuration with the worst properties available:
the window is cheap, the agent cannot see what it did, and nothing reports an error when it
answers from the gap.

That is measured, not cautionary. On a real audit workload with results cleared and nothing able to
hand them back, the agent asserted a total from evidence it no longer held, and was right only by
coincidence.

**One thing that workload is NOT evidence for, because we published the wrong cause once already.**
The same agent later abandoned the task, and its own explanation was that its counts would be
"recollection" — a story about lost data. That story was wrong. Re-run with the agent's *rules*
exempted from clearing, it cleared **six times more data** (77,328 tokens across six firings) and
completed the whole sweep. The data was survivable; the rules were not. **An agent's account of why
it failed is evidence about the behaviour, not about the cause.**

Bind a sink that writes into `prism-memory`, a table, or a log. Then bind a `ContextRecall` and the
agent gets a `recall_context` tool, so the detail is *reachable* rather than either resident or
gone. **The tool is only offered when a `ContextRecall` is bound**: one that always returns nothing
is worse than none, because the agent reads "nothing found" as *the detail does not exist* rather
than *I cannot check*.

### What the harness guarantees whatever you write

**A tool call and its result are kept or dropped together.** Splitting them makes the provider
reject the whole request, intermittently, several messages after the compaction that caused it —
so it is enforced centrally on every strategy's output rather than documented as a rule to
remember.

**It does not protect your safety constraints, and you should know why.** Compaction erases them:
["Governance Decay"](https://arxiv.org/pdf/2606.22528) measures summarisation-based compaction
producing safety violations above 40%, token truncation 25–30%, semantic compression 15–20%,
because constraints stated early are progressively lost with no failure signal. In this harness the
system prompt is applied per run and is *not* part of the thread, so it cannot be compacted away —
which removes the largest instance of that.

**Whatever your agent must not forget cannot live in a clearable turn**, and the footgun is
sharper than it sounds: if your agent loads its operating rules through a TOOL, those rules arrive
as a tool *result* and compaction eats them like any other. That is not hypothetical — it is what
made one consumer's agent abandon a task it completed fine once the rules were exempted. "Behind a
tool" is only safe if that tool's results are excluded from clearing. Put the rules in the system
prompt, or exempt the tool explicitly.

## Tool permissions

Two abilities, because they answer different questions:

```php
// May this tool be OFFERED to this run at all?
Gate::define('harness.tool', fn ($user, Session $s, Tool $t) => $user->can('use', $t->name()));

// May THIS call proceed, with THESE arguments?
Gate::define('harness.tool.call', fn ($user, Session $s, Tool $t, array $args) =>
    str_starts_with($args['path'] ?? '', '/tmp/'));
```

Offer-time filtering alone cannot bound how a tool is used, only whether it is present: when
the toolset is assembled the arguments do not exist yet, so `harness.tool` can express *"may
use `delete_file`"* and never *"only under `/tmp`"*. Once offered, a tool may otherwise be
called any number of times with anything the model chooses.

Both are **off by default** (`harness.agent.authorize_tools`). But a `harness.tool` ability
defined while the flag is off is **refused at resolve time** rather than ignored — a policy
that is never consulted still reads as a control to the next person who finds it, and nothing
at runtime would have said otherwise.

## Streaming

```php
foreach ($session->stream('Refactor the billing job') as $event) {
    // Prism's stream events, untouched — render them as you already do
}
// the turn is recorded and the run closed out when the stream ends
```

**A streamed turn and a sent turn record the same transcript**, because the same code writes
both. Prism's `StreamCollector` yields every event through untouched and hands back the
message objects the non-streaming path builds, so there is no choice to make between
incremental delivery and a faithful record.

That was not free, and it is worth saying why it mattered. An earlier draft accumulated the
deltas here and rebuilt the messages, which meant a streamed transcript could differ from a
sent one in ways nothing would report: a thread is replayed to a model as context, so a
message assembled slightly wrong never surfaces as an error — only, much later, as a model
that remembers the conversation differently than it happened.

The lock and the run are held for the whole iteration, which is the awkward part of streaming
through a durable session. A consumer that walks away — a disconnected browser, an exception
upstream — would otherwise leave the run open until the lock TTL expired. PHP runs a
generator's `finally` when it is destroyed, so the run is closed on that path too and the
partial turn is **recorded rather than discarded**: a conversation missing the half the user
already watched stream past is the worse outcome.

### Run steps for a UI

`streamSteps()` takes the same arguments as `stream()` and yields `Prism\Harness\Steps\RunStep`
values instead: the run's thinking, text, tool calls and results, approval requests, errors and
step boundaries, each tagged for a client to render.

```php
foreach ($session->streamSteps('Refactor the billing job', context: RunContext::metadata(turnId: $turn, actor: $userId)) as $step) {
    $step->toArray(); // contractVersion "g282.1", runId, parentRunId, rootRunId, turnId, seq, at, type, ...
}
```

- `seq` counts from 1 per run with no gaps; render in that order.
- A tool call carries `startedAt`. Its result carries `ok`, `result` or `error {code, message}`,
  `refused`, `durationMs` and `durationExact`.
  - `refused` is true for an expected refusal: a denied approval, the mode's allowlist, tool
    authorization, or a gate. A tool that threw is `ok: false, refused: false`.
  - `durationExact` is false when the step made several tool calls (core runs a step's tools
    before yielding any result) or the tool ran while resuming an approval. The duration is
    then an upper bound.
- An `error` step's message is a fixed generic text. The provider's own message can contain URLs
  or keys and is not passed on.
- `RunContext::metadata()` sets `turnId` and `actor` without changing how the run executes.

`stream()` is unchanged. To also receive every step as a `RunStepped` Laravel event, set
`prism-harness.step_events` (`HARNESS_STEP_EVENTS`) to true. It is off by default because steps
carry tool arguments, results and model text, and any app that records or broadcasts all events
would otherwise capture them.

### Streaming an approval

`streamDecision($decisions, $context)` records every decision in the thread when the stream
starts, inside the session lock, exactly as `decide()` does. It then resumes the run and
streams it. A stream nobody iterates records nothing, and a session another request holds
throws `SessionLocked` without recording anything. The first step for each decision is
`decision_recorded` (`approvalId`, `verdict`, `reason`, `actor`); then the run's steps follow,
continuing its `seq`. Because decisions are saved before any tool runs, a stream dropped after
it started neither loses nor repeats one: streaming the same decision again is refused with
`ApprovalNotPending`, because that approval has already been answered. The resumed run
dispatches `RunResumed`, not a second `RunStarted`.
`actor` comes from your `RunContext`, never from the model.

## Structured turns

When the answer is a document rather than prose, hand the turn a schema:

```php
use Prism\Prism\Schema\{ArraySchema, ObjectSchema, StringSchema};

$schema = new ObjectSchema('plan', 'A proposed plan', [
    new StringSchema('title', 'What the plan is called'),
    new ArraySchema('steps', 'What to do', new StringSchema('step', 'One step')),
], requiredFields: ['title', 'steps']);

$response = $session->sendStructured('Plan the release', $schema);

$response->structured();   // ['title' => 'Ship it', 'steps' => ['write', 'test']]
$response->text();         // the document as the model wrote it
```

It is the same run as `send()` — the mode's system prompt, its tools, the step
budget, the lock, the events — asking the provider for structured output.

**A structured turn can stop for a person.** When a call needs an approval, the
turn returns with no document: `$response->awaitingApproval()` is true,
`$response->pendingApprovals()` lists what it waits on, `structured()` is empty,
`$response->response->structured` is null and so is every raw step's document
(`$response->response->steps[...]->structured` is empty), and the parked step and its approval
requests are in the thread exactly as for `send()`. A provider may write a document on
the step that stopped; it was never checked against the schema, so it is never returned
(read `text()` as the transcript, not as a document). It is not a schema failure, so do
not retry it: answer the approvals with `decide()` / `approve()`, and the run continues.
Only a turn that answers returns a document, and only once it passed the schema.

**The thread keeps the text, with the parsed object beside it.** The assistant
message is the raw document, and `structured` rides along in the message's
metadata. A later turn replays the conversation as messages and reads the text,
so a thread that contains a structured answer reads like any other — the same
argument `stream()` makes about a transcript that differs by request shape.

**A document that misses the schema is refused, not repaired.**

```php
use Prism\Harness\Exceptions\StructuredSchemaViolation;

try {
    $plan = $session->sendStructured($brief, $schema)->structured();
} catch (StructuredSchemaViolation $violation) {
    $violation->code();       // structured_schema_violation, or structured_unreadable
    $violation->problems();   // every way it missed, not the first
    $violation->document();   // what the model actually said
}
```

Nothing is coerced, nothing is trimmed to the fields that fit, and the result is
never an empty document. An empty plan settles a batch as `done`, which reads
exactly like a considered answer of "nothing to propose" — the failure this
refusal exists to prevent. The exchange is still recorded, and the run is marked
failed: a thread that omits the answer it did not like cannot explain the retry
sitting next to it.

The check reads the schema's own JSON Schema, so a `RawSchema` is held to the
same terms. It checks declared types, required keys, enum members, array items,
and — where a schema closes itself — keys nobody declared. It does not read
`$ref`, `allOf`, `oneOf` or the numeric and string facets; what it cannot read,
it passes, rather than reporting a constraint it did not actually check.

## Failed model steps

An `Error` or `Unknown` finish marks the run failed. In a cost-capped stream,
an error event or missing final usage also ends the run: no further model call
is made, and unanswered tools are recorded as refused. Pending approvals from
that failed step cannot execute on a later `approve()`.

A run whose provider step failed now ends with `FinishReason::Error` or
`FinishReason::Unknown` instead of `Stop`; pending approvals from that step are
refused. Raw provider stream events and existing cost settlement remain available.

## Attachments

A turn can carry media alongside its prompt, on `send()` and `stream()` alike:

```php
use Prism\Prism\ValueObjects\Media\Image;

$session->send('What is wrong with this layout?', null, [
    Image::fromBase64($request->string('screenshot'), 'image/png'),
]);
```

Attachments are stored in the thread with the turn and replayed on later turns. Each one
must be an `Image`, `Document`, `Audio` or `Video` that carries something to send: its bytes
(`fromBase64()`, `fromRawContent()`, `Document::fromText()`), a provider file id, or document
chunks.

**Refused, with an `UnacceptableAttachment` that names the problem:**

| Code | When |
|---|---|
| `attachment_by_reference` | built from a URL, or from a local or storage path |
| `attachment_not_media` | anything other than those four media types |
| `attachment_empty` | no bytes, no file id and no chunks |
| `attachment_without_prompt` | attachments with an empty prompt |

**A URL or a path is refused even when you trust it.** A URL taken from request input is
somebody else's choice of address, and a path's contents would go to a third-party model.
`fromLocalPath()` and `fromStoragePath()` read the file when they're called, so refusing here
cannot undo that read. What the refusal stops is those contents being sent onwards. For a
file your application stored itself, read it and attach the bytes.

The refusal happens before a run starts, so a refused attachment leaves no run, no events and
nothing in the thread.

Whether a *provider* accepts a given attachment is still the provider's rule. OpenAI, for
example, does not take document chunks.

## Provider options per mode

A mode can pass options to the provider on every run, through Prism's `withProviderOptions()`:

```php
'modes' => [
    'overseer' => [
        'system_prompt' => '...',
        'provider_options' => [
            'thinking' => ['type' => 'adaptive'],
            'effort' => 'medium',
        ],
    ],
],
```

That is Anthropic's adaptive thinking, which current Claude models require. They refuse
`['thinking' => ['enabled' => true, 'budgetTokens' => 4000]]` with a 400; that shape is for
older models only.

The keys mean whatever the provider says they mean. The harness passes them through
unchanged. A value that is not a map of option names is refused when the mode is resolved,
rather than running without the option you believe is on.

Extended thinking survives a stored thread: the signature Anthropic needs on a later tool-use
turn is recorded with the assistant message and replayed with it.

## Provider and model per mode, subagent and agent

Several agents can share one app, each on its own provider and model. A mode and a subagent
declaration each take an optional `provider` and `model`, next to the agent's own defaults:

```php
'agents' => [
    'planner' => [
        'owner' => 'app',
        'model' => 'claude-sonnet-5-5',
        'modes' => [
            'plan' => [
                'model' => 'claude-opus-5-5',        // this mode only
                'tools' => ['research', 'audit'],     // named agents offer listed subagents
                'agents' => ['auditor'],              // agents this mode may delegate to
                'subagents' => [
                    'research' => ['mode' => 'research', 'model' => 'claude-haiku-5-5'],
                    'audit' => ['mode' => 'audit', 'agent' => 'auditor'],
                ],
            ],
            'research' => ['system_prompt' => 'You research and report.'],
        ],
    ],
    'auditor' => [
        'owner' => 'app',
        'provider' => 'my-vllm',                      // any name Prism resolves, custom included
        'model' => 'local-auditor',
        'spend_ceiling' => 0.50,                      // USD, per root run tree
        'max_output_tokens_per_call' => 2048,
        'modes' => ['audit' => ['system_prompt' => 'You audit.']],
    ],
],
```

Provider and model resolve **separately**, so a mode naming only a model keeps the agent's
provider. For a run:

1. the session override (`usingProvider()` / `usingModel()`);
2. the mode;
3. the agent's default;
4. the global `agent.provider` / `agent.model`.

For a subagent's child run:

1. the parent session's override, **only** if the subagent declares
   `'inherit_session_model' => true`;
2. the subagent declaration's `provider` / `model`;
3. the mode the child runs in;
4. the parent's mode, only when the child runs in the parent's agent;
5. the child agent's default, then the global default.

A parent session's override is not inherited by default. It was chosen for one session, and a
child running on it would spend against a choice nobody made for the child. A parent mode's
model does not cross into another agent, because that agent usually exists for its provider.
The child session's own override slot is not read: the runner owns that address.

**A child keeps its binding when it is resumed.** The runner saves the child's binding, and its
nesting depth, in the durable half of the child's session, beside its thread and pending
approvals, so it outlives the ephemeral TTL. A run on that session the runner did not start, such
as the host answering an approval the child stopped on with `approve()`, `decide()` or
`streamDecision()`, uses the same provider and model, and may not delegate past the maximum
nesting depth from where the child sat in its tree. A session whose thread is linked beneath a
parent but has no saved binding (a child started by a release before this one, say) is refused
with `RunNotPermitted` (`child_binding_missing`) rather than run on the root rules. The resumed
run continues the run tree the child was started in rather than getting a fresh root budget. The
first time a root run starts a child, the tree's step and cost allowance is kept in the durable
store, and the root run settles what the whole tree spent when it ends. The resumed run holds
what the tree has left before it calls a provider and settles what it spent when it ends. The
runner also saves the child's step ceiling: its grant, the smaller of the child's declared
`max_steps`, what the parent had left and `max_steps_ceiling`. That ceiling is the
delegation's lifetime budget. Every turn on the child the runner did not start, whether it
answers an approval, sends a new message or sends an empty one, spends from one durable count
of the steps the child has used since the runner last started it, claimed in one locked write
when the turn begins and settled with what it took. A child declared at 2 steps that took 1
has 1 left, however it is resumed and however many turns are sent to it. The next time the
runner starts the child, the count starts again at 0 under the new grant. A count this
runtime could not have written (a field that is not a whole, non-negative number, or a record
that is gone) gives the child no model steps, and is never rewritten into a permissive one.
A session is a subagent child when its scope has a `::sub::` segment, its thread is linked
beneath a parent, or it saved a child record. Such a session with no saved ceiling (one
parked by an earlier release, say), or with a ceiling that is not a whole number, gets no
model steps: it still acts on the decisions it is answered with, and a new message to it is
refused before anything is recorded. Any other session runs on its own mode.

A spent budget, of steps or of cost, in the child, its tree or a plan, never refuses the
person's decision: acting on an approved call makes no model call, so the call runs, its result
is recorded, and the run then stops on its budget with no further model step. A decision the
turn could not act on at all is refused before anything is recorded: a cancelled run tree, a
linked child that lost its binding (`child_binding_missing`), or a run context whose run was
cancelled or whose time or tool-call limit is spent. A denial (approved: false) under a context
whose time or tool-call limit is spent is still recorded, because recording it runs nothing:
the approval is closed as denied. The continuation after it is then refused the way a spent run
ends (`RunNotPermitted`, the run marked failed), and the denial's result is written by the next
resume that can act. A cancelled run refuses the denial too. A resume under a context whose
time is spent acts on no recorded approval at all: the decisions stay recorded for a later
resume that can act. A new message to a child with
nothing left is refused before anything is recorded. A hold whose run never settled is charged
in full once the run lock's TTL has passed, and stays charged in full on a run tree or a plan
budget. On a child's delegation count, a run that was still live and settles later has that
charge replaced with what it took, so it is counted once there. When the child was started by a turn that spent from a shared plan
budget, the resumed run spends from that plan budget too (`plan_budget_exhausted`). The same holds
for every turn on the child session the runner did not start: a new message sent with `send()`
instead of an answer continues the tree and the plan budget exactly as an answer does, and a run
context the host passes in can only narrow them. The host context's limits are lowered to what
the tree and the plan budget have left; it can lower the child's saved step ceiling and never
raise it. A turn with nothing left and no decision to act on is refused. A host
context never replaces what the runner saved: if it carries a child binding of its own, the saved
binding, depth, run tree and plan budget still win, and the turn runs no shallower than the saved
depth. Only the runner's own context rewrites them. A host context cannot stand in for a binding
a linked child has lost either: that child is refused (`child_binding_missing`) before any request
under a host context too. On a session that was never a child, a binding on a host context is used
for that turn only and is never saved, so the next turn without a context runs on the root rules.
A child
started under a run context the host built itself, rather than a run of this runtime, has no
tree record, and is resumed on its own root budget.

**Delegating to another agent is declared, never inherited.** A subagent with `'agent' => 'auditor'`
is accepted only when the parent mode lists `auditor` in `agents`. Otherwise the mode is refused
when it loads, before any provider request. Agent ids are checked exactly as written. A padded
or mixed-case id (`' auditor'`, `'Auditor'`) is refused, never trimmed or case-folded, because
folding would let a lookalike name select another agent's authority. The subagent's `mode` is
looked up in the agent it runs in.

**`spend_ceiling` caps one agent inside the shared ledger.** It is not a second account. Each
model call by that agent must fit the tree's `max_cost_usd`, when one is set, **and** the agent's
charged and reserved spend in the same root ledger. A call that does not fit is refused with
`agent_spend_ceiling_exceeded` before its request. A subagent refused this way returns to its
parent as an `exhausted` result, and the parent carries on. A ceiling puts that agent's calls on
the priced path, so the provider/model needs a `pricing` entry and the agent needs
`max_output_tokens_per_call`. An unpriced custom provider is refused with `budget_unpriced`.
The ceiling applies per root run tree. Per-day windows and per-agent cost observers are not
implemented.

`SummarisingCompaction` and `VoiceExchange` take a provider name as well as the `Provider`
enum, so a custom provider works there too.

## Voice

Press-to-talk: one utterance in, one answer out, against an ordinary session.

```php
$reply = (new VoiceExchange)->exchange($session, Audio::fromBase64($base64, 'audio/webm'));

$reply->heard;   // the transcript — what the model was actually asked
$reply->text;    // the answer
$reply->audio;   // the answer spoken, or null when the turn produced no prose
$reply->empty;   // true when nothing was heard, and no turn was spent
```

**The thread stores the TRANSCRIPT, not the audio.** A transcript is what
replays to a model, what a human reads back, and what compaction operates on;
minutes of PCM in the message table would be unreplayable by anything but the
original provider. Keep the recording yourself if you need it — the harness
cannot decide for you whether it is evidence or a liability.

**An empty transcript is not a turn.** Silence, a mis-fired button and a dead
microphone all produce `""`, and sending that would bill a turn answering
nothing and leave an empty user message in the thread for ever. You get
`empty: true` instead, so you can say "I didn't catch that" rather than leaving
the user to wonder why the agent replied strangely.

### Audio the harness would have to fetch is refused

`Audio` can be built from inline bytes, from a path on disk, or from a URL, and
those are one method name apart:

```php
$voice->transcribe(Audio::fromBase64($request->string('audio'), 'audio/webm'));  // fine
$voice->transcribe(Audio::fromLocalPath($request->string('path')));              // file read
$voice->transcribe(Audio::fromUrl($request->string('url')));                     // SSRF
```

A browser microphone produces the first. **So the other two are refused**, with
`UnsafeAudioSource` (code `unsafe_audio_source`).

What that buys is not the same in both cases, and the difference is worth
knowing before you rely on it:

- **A URL is stopped outright.** `fromUrl()` is lazy — nothing is fetched until
  the request is built — so the refusal means the request is never made.
- **A path is stopped one step late.** `fromLocalPath()` and
  `fromStoragePath()` read the file *inside the constructor*, in your code,
  before the harness is called. That read cannot be prevented from here. What
  the refusal stops is what turns a read into a breach: the bytes being
  uploaded to a transcription provider, and the file coming back to the caller
  as text.

Transcribing a recording your own application wrote is legitimate, so it stays
available behind a flag that says so:

```php
new VoiceExchange(allowReferencedAudio: true);
```

That flag is an assertion about where the audio came from. It is off by default
because only the caller can make it.

### One thing this package cannot fix for you

When a provider call fails, the exception's trace holds `VoiceExchange`'s own
frames, whose argument is the `Audio` — by then holding the decoded bytes. With
`zend.exception_ignore_args=0`, PHP records frame arguments, and an error
reporter that walks them (Flare and Sentry both do, by reflection) can put **a
voice recording in an application log**.

It cannot be closed here: a method taking an `Audio` has the `Audio` in its
arguments, and rethrowing something tidier only builds the replacement inside
the same frame. That was measured, not assumed — Prism's own frames are clean,
and a test pins both halves so a future version that starts carrying the
payload deeper fails the suite. Two things do fix it, and both are yours:

- **`zend.exception_ignore_args=1`** strips frame arguments entirely. This is
  what `php.ini-production` ships — but a PHP with no ini file at all has it
  OFF, so "we never changed it" is not the safe answer.
- **Scrub `Prism\Prism\ValueObjects\Media\Audio` in your error reporter**,
  which also reaches the protected `rawContent`, not just the public `base64`.

`SummarisingCompaction` has the same shape with a conversation transcript in
scope rather than a recording, and the same two remedies apply.

Not a live duplex stream, and deliberately not pretending to be one. Continuous
bidirectional audio with barge-in is built on a provider's realtime socket and
is a different product; a caller would otherwise discover the difference from
latency rather than from the type.

## Approvals

A tool that must stop and wait for a human is declared **per mode**, because the same tool is
not equally consequential everywhere — `execute_op` against a scratch project is routine and
against production is not, and the tool cannot tell which it is in:

```php
'benchmark' => [
    'tools' => ['workspace_read', 'workspace_write', 'workspace_delete'],
    'requires_approval' => ['workspace_delete'],   // '*' gates everything
],
```

```php
$response = $session->send('Clean up the failed run');

if ($response->awaitingApproval()) {
    $session->decide(array_map(
        fn (ToolApprovalRequest $pending) => new ToolApprovalResponse($pending->approvalId, approved: true),
        $response->pendingApprovals(),
    ));
}
```

A single pending call can be answered with `$session->approve($pending)` or
`$session->deny($pending, 'not on production')`. Answer several with `decide()`: each
`approve()` or `deny()` continues the run, and Prism denies by default any call still without an
answer, so answering them one at a time refuses the rest.

**A decision must answer an approval the session is waiting on.** `approve()`, `deny()`,
`decide()` and `streamDecision()` throw `Prism\Harness\Exceptions\ApprovalNotPending` for an
approval id the run never issued, or one already answered, and refuse the whole batch when any
one decision is like that. The refusal comes before any provider request, any write to the
thread and any change to a pending approval, so a made-up id cannot cancel a real one, and a
decision with nothing pending starts no model turn.

**A decision is checked and written under the session lock.** `approve()`, `deny()` and
`decide()` take the session's lock first, then check that each id is pending, write the
decisions and continue the run, all under that one hold (`streamDecision()` does the same when
its stream starts). While another request holds the session they throw
`Prism\Harness\Exceptions\SessionLocked` having written nothing, so the same decision, or a
different one, can be sent again. Two decisions racing for one approval cannot both be
recorded.

**The first answer is the answer.** If a thread holds two answers for one approval id (for
example, written by an older release before the check moved under the lock), the check a
mode-gated tool makes before it runs, the run steps and the plan all use the first one recorded.
A later approval never runs a call the mode gates whose first answer was a denial.

**The decision is a row, not a promise.** It is recorded in the thread, so the approval a
person grants this morning is readable by whichever worker resumes tonight — a different
process, possibly after a deploy. That is the whole reason the durable slot is guarded.

Prism **denies by default** when it finds no response for a pending request, so a lost or
unanswered approval fails closed rather than executing. And `awaitingApproval()` is **not a
failure**: a caller that treats it as one will retry, and retrying discards the half-executed
action somebody was asked to authorise.

### Tools that run without asking: the tool policy

A host can let some approval-gated tools run without asking a person. Nothing is on the list
unless you put it there, and the agent has no way to change it.

The deployment's policy is the `prism-harness.agent.tool_policy` config key (unset by default:
everything gated asks):

```php
'agent' => [
    'tool_policy' => [
        'revision' => 'r7',                         // any string; carried on every decision event
        'allow' => [
            ['tool' => 'search'],                   // a native tool
            ['server' => 'files', 'tool' => 'read'], // a tool an MCP server provides
        ],
        'honor' => true,                            // false turns the list off
    ],
],
```

- **Matching is exact.** A name must already be in normal form: a padded, zero-width or
  look-alike name never matches. An entry without `server` matches only a native tool, never
  one an MCP server provides. There are no wildcards, patterns or prefixes.
- **Refused when built, never dropped.** A malformed entry, a confirmation or approval tool
  (`confirm`, `reject`, `accept`, `approve`, `deny`), the plan tools, and a tool that declares
  `tier: destructive` throw `ToolPolicyRefused`.
- **It lifts the mode's gate only.** A tool whose own approval setting asks for these
  arguments still asks.
- Every decision emits `ToolAutoAllowed`, `ToolApprovalAsked`, `ToolApproved` or `ToolDenied`,
  with a digest of the arguments, never the arguments.

**A policy set in code lasts for the current request or queued job.**
`app(AgentRuntime::class)->setToolPolicy($policy)` replaces the policy live, from the next tool
call; a revoke refuses a call the old policy allowed that has not started yet. The runtime is a
singleton, so the policy is not kept on it: it is kept in a scoped container slot, which
Laravel empties when Octane starts the next request and when a queue worker starts the next
job. A policy you set from one tenant's or user's settings therefore never applies to a session
a later request or job serves; that one gets the config policy above until your code sets
another. Inside one request or job it applies to every session that request runs, so code that
serves several tenants in one job sets the policy before each tenant's runs. A long-running
process of your own that does not go through Octane or the queue worker (a custom loop in an
Artisan command) has no such boundary: call `app()->forgetScopedInstances()` between units of
work, as the queue worker does.

## Cancelling a run

A run executing in one process can be stopped from another: a web request stopping a run in a
queue worker.

```php
use Prism\Harness\Enums\CancelOutcome;

// In the web request, after YOUR authorization check:
$outcome = $session->cancel($runId);   // CancelOutcome::Ok, NotRunning, NoTurn, Unsupported or Refused
```

- **The flag is durable.** `cancel()` writes it to the session's durable store, so the process
  running the run reads it at its next check. Nothing in memory is shared.
- **Where it is checked.** Before every model step and before every tool call. A tool call
  that is already running is **not interrupted**: it finishes, its result is recorded, and the
  next tool or step is refused with `Not run: the run was cancelled.` The run then ends
  `cancelled`, and `send()` and `sendStructured()` throw `RunNotPermitted` with code
  `run_cancelled`. A cancel that lands during the run's last model call still ends the run
  `cancelled`; the answer that call produced is kept in the thread. Three kinds of tool are
  not checked one by one inside a step, and are stopped by the check before the next model
  step instead: tools marked `concurrent()` (they start together), Laravel MCP tools, and a
  `Tool` subclass that overrides any of `Tool`'s methods other than `handle()` or declares
  properties of its own.
- **Events.** `RunCancelRequested` fires the moment the flag is written, in the process that
  wrote it. `RunCancelled` fires when the run stops, instead of `RunFinished` or `RunFailed`.
  Both carry the run's `run_id`, `parent_run_id`, `root_run_id` and `harness_agent`, and
  `turnId` when the run serves an ACP turn.
- **A run parked on an approval** is cancelled at once. Its approvals read
  `$session->approvalStatus($id) === 'cancelled'`, never `pending`, and `approve()`, `deny()`,
  `decide()` and `streamDecision()` throw `ApprovalNotPending` for them. A cancelled run never
  resumes: an empty-prompt turn that would continue it throws `run_cancelled`.
- **The whole tree stops.** Cancelling a run stops the run tree it belongs to: its
  subagents' child runs, and the workflow it is a node of (every later node and worker is refused). A
  subagent child parked on an approval, in its own session, is cancelled with the tree: its
  approvals read `cancelled` and cannot be decided, and no later turn on the child continues
  that tree. A plan whose step turn was cancelled, or whose step is held on an approval of a
  cancelled run, is blocked with `run_cancelled`, and `resume()` continues it with a new run.
- **The outcome.** `ok`: the flag is set. `not-running`: one of this session's runs that has
  already ended; nothing changed, and cancelling twice is `ok` then `not-running`. `no-turn`:
  the session has no run. `unsupported`: the ephemeral store cannot hold a lock outside a
  callback (both built-in stores can). `refused`: the id is not one of this session's runs,
  whether malformed, made up or another session's.

**Who may cancel is your decision.** The session is scoped to a participant, so nobody can
cancel another participant's run through it, but whether this user may stop THIS run is a
question only your application can answer. Authorize before calling, as for `approve()`.

**One model step per provider request.** So that the flag can be read between steps, every
run takes one model step per provider request. The provider receives the same requests as
before; telemetry reports one generation per model step.

## Events

`RunStarted`, `RunFinished`, `RunFailed`, `RunCancelRequested` and `RunCancelled` are ordinary Laravel events — broadcast them over
Reverb, queue them, or ignore them.

They are deliberately **not telemetry**. Telemetry is observability: sampled, droppable, read
by whoever is debugging. These are interface — an application builds UI on them, so they carry
a stability guarantee telemetry never will. `RunFinished` states `awaitingApproval` outright
rather than leaving a listener to infer it from a finish reason, and `RunFailed` carries only
the exception **class**, because a provider message can contain a request URL with a key in it
and an event may end up on a screen.

A listener that throws does not leave a run behind it. A `RunStarted` (or `RunResumed`)
listener runs once the run has begun, on every path (send, structured, stream): whatever it
throws, an `Error` as much as an `Exception`, fails the run by its class name, fires
`RunFailed`, and then propagates to the caller.

Every event carries `run_id`, `parent_run_id` and `root_run_id` — the same identifiers on the
stored rows — so an existing live stream can be joined to this record without adopting it.

A tool call refused inside a workflow node emits `ToolDenied` with `decidedBy` `policy` and a
`reason`: `arguments`, `approval`, `authorization` or `check_failed`. The `reason` is null on
every other denial, and is not part of `toArray()`, which keeps the shape shared with the
TypeScript and Python harnesses. `FlowToolRefused`, which 0.26 emitted for the same refusal,
is still emitted beside it, with the same reason, and is deprecated: move listeners to
`ToolDenied`.

A run in a named agent adds `harness_agent` to `correlation()`, and a delegated child carries its
own agent. Legacy runs keep exactly the fields above. The name is deliberately not `agentId`,
so it cannot be confused with agent-integrations' presence id. The two are unrelated.

## Checking your configuration

```
php artisan harness:doctor
```

Resolves **every** mode, not the default one. Each check here mirrors a refusal that already
happens at runtime, and the refusals are correct but late: a mode nobody has entered yet keeps
its broken subagent reference until someone switches to it, and the first person to find out
is a user mid-conversation. It also reports what a mode can actually reach — a `['*']` mode is
printed as *all registered tools*, because "1 tool" is a true number and a false report.

## Subagents

A nested run, reached through a tool, with authority it was **given** rather than authority
it inherited. Declared per mode — a mode that names no subagents cannot spawn one:

```php
'modes' => [
    'designer' => [
        'system_prompt' => 'You author and revise Compass Ops.',
        'tools' => ['read_op', 'write_op'],
        'max_steps' => 12,
        'subagents' => [
            'run_op' => [
                'description' => 'Test-run an Op and report what happened.',
                'mode' => 'op_runner',   // the child's authority, not the parent's
                'max_steps' => 4,
                'max_cost_usd' => 0.25,
                'max_output_tokens_per_call' => 2048,
            ],
        ],
    ],
    'op_runner' => [
        'system_prompt' => 'You run one Op and report the outcome.',
        'tools' => ['execute_op'],       // deliberately NOT the designer's tools
        'max_steps' => 4,
    ],
],
```

The parent then calls `run_op` like any other tool. Four things are true of that call, and
each is there because the obvious implementation gets it wrong:

**The child gets its own session and thread.** A run holds its session lock for its whole
duration, and neither store's lock is reentrant — a child resolving the *parent's* address
would be refused instantly, since `lock_wait` defaults to `0`. So the child resolves
`{parent scope}::sub::{name}` instead. The lock is not made reentrant on purpose: a
reentrant lock would let a child mutate parent state mid-run, which is the one thing the
lock exists to prevent. The child's thread is linked back by `parent_thread_id`, and every
message carries the `run_id` that wrote it.

**Budgets nest; they do not reset.** This was the open question, and it has one defensible
answer. A parent bounded at 8 steps that may spawn children each entitled to a fresh 8 has
no bound at all — it has a bound per node in a tree whose width it also controls. A child
receives the *smaller* of what it declares and what the tree has left, and its spend lands
in the parent's account.

**How many steps a run may take is decided once, when it begins.** The run's ceiling is
its mode's or session's `max_steps` for a root with no context; a host's run context's
remainder for a root under a host context (it runs on the host's budget, not the mode's);
and for a child, the ceiling the runner granted and saved with it. `max_steps_ceiling` caps
it on the run's whole count. From the ceiling the run's count is taken away: for a resume,
what the parked run had used, its subagents' steps included; for a child, what its
delegation has used. Then the run tree's record and a shared plan budget each grant at most
what is left, in one locked write each, and the least of the three is the run's allowance.
The step gate, the subagent runner's check, the holds and the row the run parks on all
read that one allowance. A run that parks writes `harness_steps_used` and
`harness_steps_left` on the row it parks on, so a root that spent 5 of its 8 steps through a
child before parking has 3 left however many times it is approved. A stored value this
runtime could not have written fails closed with no steps left. So does a count that is
missing beside a saved child ceiling, because the two are always written together.

A run budget is a whole number of steps: `RunBudget` refuses a negative `max_steps`, and
`RunBudget::fromArray()` refuses one that is not a whole number (`INF`, `NAN`, `2.5`).
A `max_steps_ceiling` setting that cannot be read as a whole number allows no steps rather
than lifting the limit.

A spent allowance, of steps or of cost, never refuses a decision, on any route. The approved
calls run, their results are recorded, and the run then stops on its budget
(`RunNotPermitted`) with no model step, identically on `send()`, `sendStructured()`,
`stream()`, `streamSteps()` and the decision routes, capped or not. When the approved call
is itself a subagent call on a spent run, it is acted on: the subagent runner returns its
framed "did not run" result (`exhausted`) as that call's result. A new message, which
resumes no decision, with nothing left is refused before anything is recorded
(`plan_budget_exhausted` when the plan is what is spent). The rule is pinned across the
three languages by the parity op `step_allowance`.

**The runner does not delegate over a person.** A subagent call whose `task` is not a string,
or is blank, is refused (`denied`, "the task is empty") before the child is built. Blank is one
explicit set of codepoints, the same in every language: U+0009 to U+000D, U+0020, U+0085,
U+00A0, U+1680, U+2000 to U+200D, U+2028, U+2029, U+202F, U+205F, U+2060, U+3000 and U+FEFF.
A delegation into a child that is waiting on a person's approval is refused too: a model must
not be able to dismiss that approval by delegating again. An approval blocks the child only
while a person could still answer it; one in a cancelled run or tree, or on a child that lost
its binding, does not hold the child. Neither does the runner start a child again while a
turn on it is running (a hold a crashed turn left stops blocking once the run lock's TTL has
passed). Nothing is written to the child in any of these cases. Any exception
in the runner's own checks comes back as the framed `failed` result naming only the
exception's class; its message goes to the application log, never to the model.

**A runner start is decided once, before anything is written.** The runner reads every fact
the start depends on, then asks one gate (`RunStarts::gate`), in this order: the task, the
nesting depth, a cancel, the time limit, the steps the child would have, a spent cost limit
(the context's cap or the agent's `spend_ceiling`), a cost cap that cannot be metered, a spent
tool-call limit, a turn running on the child, an approval a person can still answer (or one a
person already decided that a resume of the child could still act on: a delegation never
voids a person's decision), then what the run tree and the shared plan budget would grant. The first that holds is the refusal, and
the parent model gets a fixed, harness-written reason. A refused start writes nothing: no
session mode, no thread link or row, no count, binding or hold, no run state. A start the gate
allows commits in one order: the run tree hold, then the child's records (its binding, count
and mode) in one locked write that checks again that no turn holds the child and that no
approval a person can answer was parked on it since the read, then the run. A hold, a claim or
an approval that lands between the read and the write refuses the start with the child's
records unchanged, and the tree hold is returned. The start is held to the step count the gate
read: steps the context spends after the read count against it. What the start writes on the
child is provisional until the child's task row (the start's user row in the child's thread)
is recorded: its binding record, its session mode and its run state name the start's run, and
every other reader (a decision, a host turn or resume on the child, the next start's checks, a
cancel, `mode()`) treats them as absent until that row exists. The start's own run is the
exception: from the commit until its run ends, the session it runs on (and every read-only
view of it a tool provider is handed) reads the mode, binding, run tree, depth and ceiling the
start committed, so a host provider or tool that decides from `mode()` sees the mode the child
is actually running in. Only the runtime's own view counts: a view host code sets itself with
`enterStart()` confirms nothing, even for a run the session's run slot still says is running. A start that fails before its
task row therefore needs no second write to be undone: the child reads as it did before, a
linked child with no binding is refused, an approval nobody could answer stays that way, its
mode reads as the mode before the start, and the run the child was parked on can still be
cancelled. Absent never widens anything: the run tree and shared plan budget the child reads as
belonging to are the ones it was last confirmed in, kept beside the provisional record, so a
failed start cannot detach the child from the tree a person can cancel or the plan budget it
spends from. These reads are reads: a host tool provider may make them from the read-only
tool-resolution view while a start is running. The child's binding, count, mode and thread link are also put back
(each only if no later writer changed it) and the tree hold is returned, BEFORE the run is
failed and `RunFailed` fires, so a `RunFailed` listener never sees a hold about to be released;
but that is housekeeping, and its failure changes nothing. Only a start that failed before its
task row is ever put back: a start that completed, or failed after this runtime wrote its task
row, stands whatever a store read says while its run is cleaned up, and a read that cannot say
whether the row was written puts nothing back. A start acts on no decision recorded on the
child: the calls the child was parked on are ended with its task row, with a fixed result, and
never run. Each one is announced as `ToolDenied` (decided by `policy`) only once that row is
recorded, and `approvalStatus()` reads it as `superseded`, never `approved`. That reading is
decided only by the harness's own mark on that row, which names the approvals it ended, and
only on a result row after the approval's own request and before the next model step (any such
row, not only the first carrying the call's id): a tool's result text cannot end a call, and a
tool-call id a provider reuses (some name ids by function) cannot end or un-end one. Results
and approval requests are matched to the calls of a step by position, never by a map keyed by
call id: when two calls in one step share an id, the result of the one that ran is never read as
the other's, so a gated call sharing its id with an ungated one still waits for its approval
(every decision on such a step is recorded as a denial, and nothing gated in it runs) and a
start still ends it. When such a step is resumed, the harness resolves it itself rather than
core's resolver, which keys by id: every gated call in it gets exactly one result, the
repeated-id denial, and an ungated call that ran is never given an approval or a denial.
A decision applies only to the step that asked for it: the paused step is the newest assistant
step with tool calls when only result rows (or system rows) follow it, chosen by one rule for every reader (the
decision checks, the repeated-id guard, the resolver and the plan's readers). Any other row
written after it (an answer, a user turn) ends that step's approvals: a decision is
refused, nothing resolves the step, and no gated tool there counts as approved. A step is
SUPERSEDED by any later assistant row with tool calls, or by an assistant text row or a user row
recorded outside the runs that own it (a host's row, or a new message; the harness's own rows in
that run do not count, and naming the run when recording does not make a row the harness's): each
of its calls that has no result then gets exactly one, `Tool call was not run because a later
step replaced it`, marked by the harness, and `approvalStatus()` reads its approval as
`superseded`. The runs that own a step are the one that recorded it and every run that recorded
one of its results. A decision on a superseded step, or on an approval already decided, is refused
with `ApprovalNotPending` (code `approval_not_pending`), and a batch with one such decision records
none of them. A new message that supersedes a step is recorded together with those denials, in
one write, before its turn is sent: so a new message denies approved calls that have not run
yet even when its own turn then fails (its message stays recorded), and no decision can land
between the two. A host row that would supersede a step (`$session->thread()->record()` of a
user or assistant row) is never refused and never waits on a lock, from anywhere, a tool body
recording on its own session included: before the harness runs an approved call it records a
`started` row for that call (a harness-only `harness_started` and `harness_answers_step`
mark), so a call that is running is answered in progress and gets no replaced denial, and its
real result, which names its step the same way, stays that call's one outcome wherever it lands.
A call that was started and never got a result (the worker died, the tool threw past the
runtime) reads `Tool call was interrupted, it may have run`, never "not run": it is never run
again, `approvalStatus()` reads `interrupted`, and running it again needs a new call and a new
approval. A decision counts only while its call has no outcome: one recorded after the call
started, ran or was answered (by any writer) never stands, on any reader or in what the model is
sent, which always shows the decision that stood when the call was resolved (a denial over an
approval). An approval whose call already has an outcome is never pending, and a decision on it
is refused with `approval_not_pending`. Every call of a step that is no longer the paused one and
has no stored outcome is given one when the thread is replayed (replaced, when it was waiting on
an approval; otherwise interrupted, since an ungated call ran, or may have, when the step was
taken), so the request a provider is sent always answers every tool call. Before core runs an
ungated call inside a model step the harness records a write-ahead journal row for it, removed
by the write that stores its result: if that write never happens, the call reads, and is
replayed, as interrupted. A run takes no further model step once a newer step on its session,
written by anyone else, is waiting on approval (it ends with `RunNotPermitted`, code
`parked_elsewhere`): it never replaces the step a caller was just told is waiting. Every harness
mark is read only on a row the runtime itself wrote, recorded in the `harness_written` column
(run the package migrations; no public write sets it, and a run id is never proof): on any other
row a mark is read as absent. A thread is read once per locked operation and then only the rows
appended since. Only the runtime's own record call is inside a run: a
row a host records while that write is in progress (from a model event, say) is outside,
whatever run id it names. A result counts only for the calls of the step whose rows it sits in (the rows
after that step and before the next assistant row with tool calls), matched there by exact
arguments, then by id and tool name, then by id: a result recorded after a later step is never
an earlier step's, even when its arguments are an earlier call's, so every call has exactly one
outcome and nothing runs twice. Replaying the stored rows to the model keeps each call's own
result the same way, never keying results by call id alone, and replays each result once, in
its own step's rows, even when they are not consecutive (a resolution recorded after a system
row carries the step's earlier results again; the replay leaves the repeats out). A system row, like a result row, leaves the step parked. A step whose requests repeat
an approval id is treated as one whose call ids repeat. The mark is one shape in all three languages, pinned by the
`harness-thread-rows` corpus (thr-0014): a top-level `harness_ended_by_start` holding
`{approval_ids: [...]}`, after `tool_approval_responses`, in call order.

**An approval runs exactly the call it was asked for.** Every stored approval request records the
call it asks about (its tool name and a digest of its arguments, `harness_call`), and a call runs
only when its own request matches it; any other call is denied, with exactly one outcome. An
approval belongs only to the step that first asked for it: a later step that repeats its request
asks for nothing. An assistant row is a tool-call step only when its `tool_calls` hold at least
one object, and its calls are those objects, for every reader; any other row is read as text.
A recorded denial is final: an approval recorded after it is refused, and a denial recorded
after an approval that no run has acted on yet stands (the call is denied and never runs).
`Thread::record()`'s `parked` and `endedByStart` arguments belong to the runtime: given by
anyone else they throw `InvalidArgumentException` and nothing is written. A thread written by an
earlier version whose step was replaced without an outcome reads that step as replaced: its
calls get the replaced result when the thread is replayed, its approvals read `superseded`, and
it never runs. The rows are pinned across the three languages by the `harness-thread-rows` and
`harness-approval-pairing` corpora.

Once a start has committed, a stop that lands later (a cancel, say) stops its model
steps but not the start: the task is recorded on the child and the run ends with that stop. The gate is pinned across the three languages by the
parity op `start_gate`; the tool-call limit exists only here.

**What the parent model reads about a child is harness-written.** The runner chooses the
outcome (`denied`, `cancelled`, `exhausted`, else `failed`) and the reason from the refusal's
code, from a fixed set; a failure names only the exception's class, and only a named class: an
anonymous class is named by the class it extends (or the interface it implements), never by
the file path and line PHP gives it. An exception's message is
never passed on, and a host's cancel reason (`RunLedger::cancel($reason)`) never becomes an
exception message or a stored total: the host reads it back from `cancelReason()` and
`exhaustion()`, and refusals say "run stopped by the host". The same holds for `RunFailed`
and every run record (the run state's `failure`, the run history): they carry the exception's
named class, a fixed harness description or a harness code, never exception text, whatever
`prism.telemetry.capture_content` is set to.

**A budget that cannot bound anything is refused.** `RunBudget` refuses a negative, fractional
or non-finite `max_steps`, a `max_cost_usd` that is negative, NaN or infinite, and a negative
or non-whole `max_seconds` (from a host's constructor call and from config alike), rather than
reading any of them as no limit. In config (`prism-harness.agent`, `prism-harness.workflow` and
`RunBudget::fromArray`), a cost, time or output limit that is absent or null is no limit; one
that is present must be a number, or text that spells one (`'0.50'`, `'60'`). Anything else
present, such as `'NAN'`, `'INF'`, `'0,50'` or an empty string, is a configuration error.

**A host run context belongs to one session's turn.** Its ledger is the account every step
under it is counted on. Share one context across sessions and each child resumed under it is
charged the steps the others took on that ledger too, which can refuse a follow-up the child
itself never spent. Build a fresh context for each turn.

`send()`, `sendStructured()` and `stream()` charge a shared tree's step before
the provider call, so a tool that starts a child sees the parent's current step
already spent. A child admitted after four of six tree steps can make at most
two calls. Cancellation or expiry prevents the next call; a failed or abandoned
request still consumes its admitted step.

For explicit `RunContext` calls and modes declaring subagents, uncapped runs use
one core generation per provider call to enforce that boundary. Core telemetry
therefore has one generation span per call in these trees. Ordinary uncapped
sessions without a context or subagents retain their existing wire sequence and
generation grouping. Cost-capped calls retain their existing reservation and
observer accounting.

**A child's output is data, never instructions.** An ordinary tool returns a value its
author chose; a subagent returns free text a model wrote, possibly after reading untrusted
input, and it arrives where the parent has been reading its own instructions. So it comes
back as a JSON envelope: the model-authored text confined to `content`, attributed to the
child run, behind an explicit note that it is material and not a directive. That is not a
guarantee — it removes the free win of splicing model output into an instruction stream
unmarked.

**Every ending is its own outcome.** `completed` / `exhausted` / `cancelled` / `denied` /
`failed` / `awaiting_approval`, with `retryable` stated. A parent that could only see
"worked or didn't" would retry what was refused on purpose and abandon what merely broke.

Cancellation is cooperative: PHP cannot interrupt a tool already executing, so the in-flight
call finishes and the next step is refused. Pretending otherwise would discard a
half-executed action — the exact loss the durable slot exists to prevent.

### Hard per-call cost budgets

An active `maxCostUsd` makes the harness drive one model call at a time on `send`,
`sendStructured` and `stream`. Before each call it reserves the worst-case model cost:
the input token upper bound at the highest applicable input/cache-write/cache-read price,
plus `maxOutputTokensPerCall` at the output price. Settled cost and all open reservations
count against admission, so a subagent cannot spend its parent's in-flight allowance.
The next call is refused if it would cross the cap; completed calls remain in the ledger
and transcript, and the run fails with reason `budget`.

Approved pending tools are resolved through core before estimating the resumed input.
Their results are stored before admission, so a result that makes the next call too
expensive remains durable and is not executed again on retry. Streaming preserves the
resolution events in order, yielding them after that write and before model events.
Pass the same owner-held context to approval resumes: `approve($approval, context: $page)`,
`deny($approval, context: $page)` or `decide($decisions, context: $page)`. As with `send`,
omitting a per-run context uses the configured root budget.

**Existing cost-capped configurations now require prices and an output-token ceiling.**
Uncapped runs retain the existing core tool loop and need no price. An explicit output
ceiling is also forwarded on uncapped runs; leaving it unset preserves default requests.

Configure trusted prices under `prism-harness.pricing`, keyed by provider and exact model
identifier. The package supplies no default model prices. A `RunContext` can override a
model's whole price entry; child contexts inherit those overrides.

```php
use Prism\Harness\Subagents\RunBudget;
use Prism\Harness\Subagents\RunContext;

// Your configured provider/model, not values selected by the model or browser.
$prices = [$provider => [$model => [
    'inputPerMTok' => 2.0,        // USD per million ordinary input tokens
    'outputPerMTok' => 10.0,
    'cacheWritePerMTok' => 2.5,   // optional; otherwise ordinary input price
    'cacheReadPerMTok' => 0.1,    // optional; otherwise ordinary input price
    'toolOverheadTokens' => 286, // configured provider-added tool system prompt
    'imageTokenBound' => 8000,   // your conservative bound per image, if images are allowed
]]];

$build = RunContext::root('build-'.$buildId, new RunBudget(
    maxSteps: 100,
    maxCostUsd: 10.0,
    maxOutputTokensPerCall: 4096,
), pricing: $prices);

// Create one child allowance per page, sharing the build's cumulative ledger.
$page = new RunContext(
    ledger: $build->ledger,
    budget: (new RunBudget(12, maxCostUsd: 1.0, maxOutputTokensPerCall: 2048))
        ->nestedWithin($build->budget, $build->ledger),
    pricing: $build->pricing,
    observer: $build->observer,
);
$session->send('Build this page', context: $page);
$session->sendStructured('Describe this page', $schema, context: $page);
foreach ($session->stream('Revise this page', context: $page) as $event) {
    // Consume the entire generator: each model call yields its original events.
}
```

Use a `$0.25` child allowance for a cheaper page lane by setting `maxCostUsd: 0.25`.
Keep the same root ledger across pages: creating another root starts a new account.
The ledger is the existing in-memory run-tree account, not a durable billing database;
the host owns account persistence and coordination across workers. Child output-token
ceilings can narrow, never raise, the parent's ceiling. Config-based budgets use
`max_output_tokens_per_call` (under `prism-harness.agent` or a subagent declaration).

Harness requires Prism core `^0.155`; the provider request projection API it uses here arrived in 0.152.
The input bound counts UTF-8 **bytes** of the configured provider's projected JSON
request, plus 64 tokens per message/tool for framing. Anthropic and OpenAI projections
include the actual message mapping, tools, provider options and structured instructions.
Stored replay shadows and tool-result arguments that are absent from the payload do
not count again. Thinking, signatures and other content on the wire remain counted.
This is a conservative byte bound, not a tokenizer estimate; no token-counting network
request is made.

When a provider has no projection, or core reports that approval resolution makes
projection unavailable, the original stored-message bound applies. Capped harness
calls resolve and persist approvals before computing the bound. Unexpected projection
or JSON encoding failures refuse the call before transport.

Images add the configured `imageTokenBound`. On Anthropic and OpenAI projections,
inline image base64 matched once, in order, to an image part is excluded from the
text byte count. JSON framing and data-URI prefixes remain counted, as does identical
base64 appearing as text. URL/file-id images, unmatched inline data and unknown
projectors retain their complete byte count plus the image charge.
Documents, audio and video are refused
under a cap. Tools require `toolOverheadTokens` because their hidden provider system
prompt is absent from the request text. Provider toolsets require
`serverToolOverheadTokens` keyed by their exact type; hosted MCP input is refused.
These are trusted upper-bound assertions; prices and bounds must cover the selected
provider/model configuration.

A price entry may also have `multiplier` (default `1.0`) and an ascending `tiers` list.
Each tier contains `aboveInputTokens`, `inputPerMTok`, `outputPerMTok`, and optional
cache prices. Admission selects rates using the input upper bound; actual accounting
uses reported ordinary input plus cache reads and writes. A tier begins strictly above
its threshold. The multiplier applies to every token-priced category. For example:

```php
'multiplier' => 1.1,
'tiers' => [[
    'aboveInputTokens' => 100000,
    'inputPerMTok' => 0.50, 'outputPerMTok' => 2.50,
    'cacheWritePerMTok' => 0.625, 'cacheReadPerMTok' => 0.05,
]],
```

OpenRouter pricing uses `'billing' => 'reported-complete'` by default; set that flag
explicitly when configuring its models. A finite nonnegative reported cost is trusted
only when `additionalContent['openrouter_billing']['complete'] === true`. Incomplete
evidence or a missing total charges the reserved maximum, with no token-price fallback.
An explicitly complete zero total remains free. This requires Prism core 0.135.0 or later,
which includes the router charge and reported BYOK upstream estimate in the call total.
Configure conservative rates, bounds and any multiplier to cover router fees as well as
inference; the harness does not discover or guess those prices. Other providers may opt
into the same evidence policy with the flag. Unknown modes and an attempt to disable it
for OpenRouter are refused as `budget_unpriced`.

For streaming calls this policy requires exactly one complete `StepFinishEvent` PHP
object and a final `StreamEndEvent` with valid total usage, because each capped request
uses `maxSteps(1)`. The facts are read before serialization and are absent from SSE.
Missing or duplicate finishes retain the full reservation. A normally completed call
with incomplete accounting still has `outcome: completed` and `usageKnown: false`.

For other pricing policies, the provider-reported `usage->cost` is authoritative when supplied;
otherwise the ledger charges ordinary input, cache reads/writes and output at that
call's configured tier and multiplier. Reasoning tokens are already included in
completion tokens. On failure or stream abandonment, the reservation is released and
known usage charged. If usage is unavailable, the ledger charges the reserved worst
case and subsequent calls may use the remaining allowance. Usage counts as unavailable
when a provider reports no input tokens and no cost (a placeholder, not a free call), and
for a stream that emitted an error, never ended, or ended with `Error` or `Unknown`:
output generated before such a stream stopped may not be in its reported usage. Legacy whole-run accounting
without a reservation still records unknown spend separately and prevents further
capped spending. Consume a stream in full to persist its completed messages; abandoning it
keeps completed earlier calls and the user's prompt, as with the existing collector.

`RunNotPermitted::code()` distinguishes these refusals:

| code | cause |
|---|---|
| `call_budget_exceeded` | the next call's worst case cannot fit |
| `budget_unpriced` | missing or invalid provider/model pricing |
| `budget_unbounded_output` | no positive per-call output ceiling |
| `budget_input_unbounded` | unsupported media or missing image/tool overhead bound |
| `budget_compaction_unmetered` | model-based summarising compaction is configured |
| `budget_observer_uncapped` | a cost observer was supplied without a hard cap |
| `call_refused_by_host` | an observer's admission callback threw without a valid host refusal code |

`SummarisingCompaction` can bill before admission, so a capped run refuses it before
reading the history. Free compaction remains supported; uncapped compaction is unchanged.
Application-supplied compaction and tool callbacks are trusted extensions. Their own
model calls or external billing outside the harness are not metered by this ledger.

### Projected image formats and diagnostics

With Prism core 0.144, decorators can forward
`Prism\Prism\Contracts\DescribesProjectedPayload::projectedPayloadFormat()` alongside
`projectPayload()`. Recognised formats are `anthropic-messages`, `openai-responses`
and `openai-chat-completions`. The format only identifies where image blocks can
appear: every discounted value must still exactly match a charged inline image,
including its MIME type, semantic user position and ordering. Other bytes remain
in the conservative bound.

A declared `null` or unknown format keeps the complete projected byte bound.
Providers without the interface use the existing native Anthropic/OpenAI class
checks, including on core 0.143. A declaration without a projection has no effect.

`CallBudget::estimate($request, $provider, $mode)` returns an immutable
`CallBudgetEstimate` containing `inputTokenBound`, `worstCaseUsd` and `diagnostics`
from one projection. Existing `inputTokenBound()` and `worstCase()` signatures and
return types are unchanged. A projected request carrying inline images with no
recognised format receives
`CallBudgetDiagnostic::ProjectedImageFormatUnknown`
(`projected_image_format_unknown`). This diagnostic retains the full bound; it
does not itself refuse a call. Normal pricing and budget limits still apply.
URL/file-id images and requests without a projection do not produce this reason.
Diagnostics contain fixed enum cases, never provider-supplied format strings or
request content.

### Host cost reservations across workers

For a durable account shared across workers, implement `Prism\Harness\Contracts\CallCostObserver`:

```php
use Prism\Harness\Contracts\CallCostObserver;
use Prism\Harness\Exceptions\CallRefused;
use Prism\Harness\Subagents\{CallReservation, CallSettlement, RunContext};

final class AccountCosts implements CallCostObserver
{
    public function reserving(CallReservation $call, RunContext $run): void
    {
        // Atomically reserve $call->worstCaseUsd in your account, keyed by $call->callId.
        // Throw new CallRefused('account_exhausted') if your account cannot admit it.
    }

    public function settled(CallSettlement $call, RunContext $run): void
    {
        // Replace that reservation with $call->chargedUsd, using the same callId.
    }
}

$run = RunContext::root('build-'.$buildId, $budget, pricing: $prices,
    observer: app(AccountCosts::class));
$session->send('Build this page', context: $run);
```

The harness first admits and reserves in memory, then calls `reserving`, then makes the
provider request. This applies to every capped text, structured and streaming call, including
`approve`, `deny` and `decide` continuations. Approved tool results are resolved and stored
before admission. A reservation carries a fresh 128-bit random hex `callId`, `provider`, `model`,
`worstCaseUsd`, `kind` (`text`, `structured`, `stream`) and `diagnostics` (a list of
`CallBudgetDiagnostic` cases, empty by default); it contains no prompt or tool content.

Any throwable from `reserving` refuses the call with zero provider requests, releases the
in-memory reservation without charging it, and does not invoke `settled`. `CallRefused` accepts
only lowercase codes matching `[a-z][a-z0-9_]{0,63}`; the resulting `RunNotPermitted::code()`
returns that host code. Other throwables become `call_refused_by_host`, preserving the original
exception as the previous cause. If the host partially reserved funds before throwing, it must
roll back its own transaction.

After each admitted call the ledger settles first and `settled` fires exactly once, with
`outcome` (`completed`, `failed`, `abandoned`), optional original `usage`, and `usageKnown`.
`completed` is a call that ended normally. `failed` is a provider exception, or a stream that
was read to its end but did not end normally: an error event, a truncated stream (reported by
Prism core 0.134.0 and later as `incomplete_stream`), or an `Error`/`Unknown` finish.
`abandoned` is a stream the consumer stopped reading before its end. Either way the charge is
the reserved maximum, but only `failed` says the provider failed: if your code throws as soon
as it sees an `ErrorEvent`, keep reading the stream to its end first, then throw.
`chargedUsd` is exactly this call's ledger charge: valid reported cost, token-derived cost,
or the reserved maximum when usage cannot establish cost. `usageKnown` indicates that a usable
cost was derived from usage. A throwing `settled` callback propagates; the ledger charge and
completed transcript remain. Hosts should make durable settlement idempotent by `callId`.

`RunContext::forChild` inherits the observer. It is also retained by the shared ledger, so a
directly constructed child cannot drop or replace the tree's selected observer. The optional
`prism-harness.cost_observer` config names a container-resolved observer class for contexts
without one; an explicit per-run observer takes precedence. The shipped default is `null`.
An observer on an uncapped run is refused as `budget_observer_uncapped` before any provider
request. Use a hard cap even if its limit is large.

Observers are trusted host code; their own model calls and billing are not metered. A killed
worker cannot run `settled`, so hosts must expire or reconcile stale durable reservations.
The observer does not turn the in-memory ledger into a distributed account by itself.

## Workflow budgets

A fancy-flow workflow run has one budget, on by default: 100 model calls and 100 tool
calls per run. Every `llm_call`, every step of an `agent` node (and of its subagents),
and every tool the workflow runs draws from it, across `for_each` items, subflow levels,
retries, resumes and parallel workers. A refused call sends nothing and runs nothing, and
fails its node with the code `workflow_budget_exhausted`.

```php
'workflow' => [
    'max_steps' => 100,        // model calls per run
    'max_tool_calls' => 100,   // tool handlers per run
    'max_cost_usd' => null,    // null uses agent.max_cost_usd
    'max_seconds' => null,
    'store' => 'database',     // shared by every worker; 'memory' for one process
],
```

Bind the Harness to the run so the budget is keyed by the engine's run key:

```php
use FancyFlow\Engine\FlowRunner;
use FancyFlow\Registry\Builtin;
use FancyFlow\Runtime\RunOptions;
use Prism\Harness\Flow\HarnessFlowExecutors;

$registry = Builtin::executors($deps);
HarnessFlowExecutors::register($registry,
    tools: $session->flowTools(),
    limits: ['max_steps' => 20],                    // optional, this run only
    workflow: $metadata['harness_budget'] ?? [],    // optional, from the saved workflow
);

(new FlowRunner)->run($graph, $registry, options: new RunOptions(run: $runKey));
```

The config is the ceiling. Host limits and a workflow's `harness_budget` can only lower
it; a workflow that asks for more gets the configured value and a warning in its run log.
The limits are fixed when a run first opens its budget, so a resume after a deploy cannot
raise them.

Workflow model calls are priced and capped like turns: `pricing`, the cost cap,
`agent.max_output_tokens_per_call`, the `cost_observer` and a named agent's
`spend_ceiling` all apply.

A run with no run key is given one, saved for its session, so a retry or resume through
that session continues the same budget. Two workers making the first call at once get the
same key. To start a fresh budget for a new keyless run on
the same session, call `app(WorkflowBudgets::class)->startSessionRun($session)`.

Money reserved by a worker that died mid-call stays reserved until you reconcile the run's
row in `harness_workflow_budgets`.

## Task lists

An agent given a goal has to keep working across many requests until the goal is met. It
needs a list of what remains, and that list has to survive the request, the worker, a crash
and a deploy.

```php
$tasks = $session->tasks();

$tasks->add('Draft the summary', 't-1');
$tasks->add('Check the figures', 't-2');

while ($tasks->pending() > 0) {
    $task = $tasks->claim($workerId);   // one atomic call

    if ($task === null) {
        break;                          // someone else holds what is left
    }

    $result = $session->send($task->instruction());

    $tasks->release($task, $workerId, TaskOutcome::Done);   // the APPLICATION decides
}
```

`release()` takes the **worker**, not just the task. Without it, any caller
holding a source can close any task in the list — including one another worker
is halfway through, which happens with no adversary involved: a worker whose
lease lapsed mid-task finishes, releases, and overwrites the claim of whoever
legitimately reclaimed it. The check lives on the source rather than in any one
caller, so a queued job and an HTTP route get the same guarantee the completion
tool does.

**No task model, no schema, no migration.** What a task *is* differs for every consumer and
is not this package's to decide. Two contracts — `AgentTask` and `AgentTaskSource` — and two
adapters: the store-backed list above, and `Concerns\IsAgentTask`, which makes a consumer's
own Eloquent model an `AgentTask` with conventional column names and a one-method override
per column.

**Four states, and no others:** `todo` → `claimed` → `done` / `failed`, plus `claimed` →
`todo` when a lease expires. Each edge is a pinned decision:

| | |
|---|---|
| `claim()` is **one** call | Read-then-mark as two calls is the race the design exists to prevent — both workers see the row free, both write their name, both succeed. |
| A claim carries an owner **and** an expiry | Five minutes by default. This is what makes a dead worker recoverable. |
| `claimed` is written **before** the work begins | So "started and died" is distinguishable from "never started". |
| An expired claim returns to `todo`, **never** `failed` | A worker dying is not the task failing; conflating them burns a retry that never ran. |
| `done` and `failed` are terminal | Re-releasing one is an error, not a silent no-op. |
| Order is **insertion order** | Nothing errors when ordering changes — the agent just does the work in a different sequence. |

### The agent cannot mark its own task complete

If the model can set its own task to `done`, "run until the goal is met" quietly becomes
"run until it decides it is met" — and a run that has stalled ends by declaring victory.

So `release()` is called by the **application**, from evidence. A consumer that wants the
agent to close its own tasks registers `Tools\TaskCompletionTool`, which refuses unless the
tool authorizer is enabled **and** a `harness.tool.call` policy allows that specific call.
An offer-time policy alone is not enough on purpose: a host that trusts an agent with tools
in general has not been asked about self-completion, and silence must not read as consent
for the authority that decides whether a run is finished. The tool is bound to one worker
and refuses any task that worker is not holding.

### Extending a lease, without inventing a second limit

A worker may push its own lease out while it still holds it, bounded by the run's remaining
`RunBudget` — cost, steps, wall-clock and cancellation, read through
`RunLedger::exhaustion()`. Unbounded self-extension is how a wedged worker holds a task
forever, and a fresh timeout here would be a second spelling of a limit the package already
has. Extension therefore stops exactly when the run does.

### An unreadable SQL lock expiry means wait, not take

This SQL store's expiry-completeness rule is separate from the ports' file-lock
protocol: **a SQL lease is reclaimed only when its expiry can be shown
to have passed.** An expiry that is absent, empty, or not a complete date-time is
not evidence of anything, and the row is left alone — the caller waits and gets a
`SessionLocked`, which is loud and recoverable.

The failure this prevents is not a parse error. **Every prefix of a timestamp is
an earlier timestamp**, so a value truncated by a torn write does not fail to
compare — it compares as long ago, and the sweep deletes a lock somebody is
actively holding. Both ports of this package shipped that bug in their file
stores; here the expiry is a column written by one statement, and the
completeness check is what closes the remaining gap.

The TS/Python file stores now follow [decision0022](https://github.com/Particle-Academy/prism-parity/blob/main/docs/decisions/0022-local-file-lock-ownership.md).
They publish a complete nonce-bound owner directory, compare established native
identity before probing a PID, and never treat TTL expiry as death proof.
Legacy timestamp files require explicit maintenance and all old workers must
stop before that protocol upgrade. A terminator alone is not a recovery grant.

### A lease is refused, never quietly adjusted

`lease_seconds` is refused — with the code `task_lease_invalid` — when it is
zero or negative, **and when it is fractional**. Both are one rule: a
configuration that silently becomes a *different* configuration is one nobody
gets to notice. `0` becoming `1` and `90.4` becoming `90` are the same shape at
two scales, and truncation landing in the safe direction (a shorter lease, so
more reclaims rather than lost work) is just the clamping argument restated.

A fractional lease could not have been honoured as written in any case:
`claimed_until` is an integer Unix timestamp, pinned across all three languages.

Note where the check lives. `(int) '90.4'` is `90`, so a cast in the config file
would have truncated the value before anything could object — the guard would
have been defeated by the file that declares the setting. The raw value is
passed through and refused where it is read.

`lock_wait` is deliberately *not* held to this rule: it is a local wait bound,
never reaches a stored record, and is not part of the contract the three
languages share.

### The list is durable state

A task source backed by a volatile store **refuses to start**, the same way the durable
session slot does. A half-finished task list that vanishes on a deploy is indistinguishable
from a finished one: the next run resolves the same session, finds nothing to do, and
reports success having dropped the rest of its work.

## Agent plans

Optional, and off unless the host and the mode turn them on. An agent drafts a
plan, a person approves it, and `$session->plan()->advance()` runs one step per
turn. A plan step is a separate thing from a task in the list above, and a done
plan step never closes a task. See [docs/plans.md](docs/plans.md).

### Plan budgets

How a plan's steps are budgeted is the app's choice, set in
`prism-harness.plans.budget` or per plan with
`$session->plan()->useBudget([...])`. The model and per-call options cannot
choose it or widen it.

| mode | what it means |
|---|---|
| `per_step` (default) | Each step's turn gets the per-turn budget, as before. |
| `shared` | The plan's own turns also spend from one ledger: `max_steps` model steps in total (required) and optionally `max_cost_usd`. Per-turn limits still apply to each turn. When the ledger is spent, the plan is blocked with `plan_budget_exhausted` and `PlanBudgetExhausted` is dispatched once. |
| `none` | No plan-level budget. Per-turn limits, the plan's own limits, workflow budgets and agent spend ceilings still apply. |

```php
'plans' => [
    'budget' => ['mode' => 'shared', 'max_steps' => 40, 'max_cost_usd' => 2.00],
],

// or for one plan, in host code:
$session->plan()->useBudget(['mode' => 'shared', 'max_steps' => 12]);
$session->plan()->budget(); // mode, limits, and what a shared budget has spent
```

A shared ledger lives in the plan store, so it carries across approval pauses,
restarts and workers. Each turn holds what it may spend before it starts and
settles what it spent when it ends, in one locked write each, so two turns are
never granted the same remainder. A hold whose turn never settled is charged in
full once the run lock's TTL has passed. A plan's budget is fixed when it is
first chosen or first used: after that its mode cannot change
(`plan.budget_fixed`) and its limits can only be lowered. A step whose turn is
handed a run tree of its own (a subagent or workflow context) is refused
(`plan.budget_context`) rather than run outside the ledger. An invalid mode or
limit throws when plans are used, before anything runs.

The plan's own turns are the turn `advance()` runs for the step it claimed, the
resume of an approval that step asked for, and a subagent child those turns
started, on any turn the runner did not start: an answer, a new message, or a
run context the host passes in, which is narrowed to what the plan has left
rather than refused. Any other turn on the session,
such as the person chatting while a step waits on their approval, spends
nothing from the plan's budget and is never refused for it. A decision on an
approval the step asked for is accepted and acted on even when the shared
budget is spent, its steps or its cost, live holds counted: the approved call
runs with no further model step, and the next `advance()` blocks the plan.

## What it is for

Applications where **the agent is the product** and the session is long-lived — an interactive
coding assistant, a support console, an operations agent.

That is a different thing from an AI *feature* inside an app, which is what Prism and
[`laravel/ai`](https://github.com/laravel/ai) already serve well: prompt, respond, maybe stream.
A harness is what you need when the conversation outlives the request, the agent switches
between ways of working, and a tool call has to stop and wait for a human.

Nothing in PHP serves that today.

## The constraint that shapes everything

The prior art is [Mastra's Harness](https://mastra.ai/docs/harness/overview) (their class is now
`AgentController`). It cannot be ported.

Mastra keeps a Session in memory because Node holds one process across many requests. Their docs
are explicit that session state, permission grants and pending approvals *"don't automatically
survive process recreation"*.

A Laravel request boots, serves and dies. So the architecture inverts:

| | |
|---|---|
| Mastra | a **live object** with optional persistence |
| Prism Harness | **durable state** with a reconstructed runtime |

Two properties follow, and neither is negotiable:

1. **Nothing is held across requests.** A fresh worker resolves the same session and sees the
   same mode, model and pending approvals.
2. **A pending approval outlives everything** — the request that created it, the worker that ran
   it, and a deploy in between. Mastra can treat an approval as an in-memory promise. Here it is
   a row.

## Intended shape

```php
$session = PrismHarness::for($user)->session();   // rehydrated, not constructed

$session->mode('plan');                          // persisted on the thread
$response = $session->send('Refactor the billing job');

if ($response->awaitingApproval()) {
    $session->approve($response->pendingApprovals()[0]);
}
```

## Concepts, and what each maps to

Every Mastra concept has a native Laravel counterpart. Where the mapping is exact, the plan is to
use the Laravel thing rather than reimplement it.

| Concept | Status | Laravel counterpart |
|---|---|---|
| Controller | **shipped** | `PrismHarness` singleton + `ModeRegistry`, config-driven. `harness:doctor` validates every mode up front |
| Session | **shipped** | Resolved per request from a store, keyed on participant + scope |
| Thread | **shipped** | Eloquent models here; contract defined in Prism (0.113) |
| Modes | **partial** | `Modes/AgentMode.php` + `ModeRegistry`, resolved in `AgentRuntime::send()`. Config-driven, not one class per mode |
| Skills | **partial** | `Skills/SkillRegistry.php` — augments the system prompt from a mode's declared skills |
| Workspace | *elsewhere* | A scoped Filesystem disk — built as [`prism-workspace`](https://github.com/Particle-Academy/prism-workspace) |
| Permissions | **shipped** | `harness.tool` gates the *offered* toolset; `harness.tool.call` gates each invocation with its arguments. **Off by default**, and a policy defined while off is refused |
| Subagents | **shipped** | A Prism Tool wrapping a nested run. Declared per mode, own session + thread, budget drawn from the tree |
| Event bus | **shipped** | `RunStarted` / `RunFinished` / `RunFailed` as Laravel events, each carrying run lineage. Broadcast over Reverb if you want it; separate from Prism telemetry |
| Task lists | **shipped** | `Contracts\AgentTask` + `Contracts\AgentTaskSource`, four states, atomic claim-and-lease. Store-backed by default; `Concerns\IsAgentTask` adapts a consumer's own model. No task model, schema or migration ships |

Read **partial** as "some of this exists, and the cell says which part" — it is
the status that misleads when compressed to a binary.

Every row states its status, because the previous version of this table did not
and it misled someone. Bold was doing two jobs: marking *Session* and *Thread*
as built, and emphasising *Gates and Policies* as a design choice. Identical
weight, identical position, different meaning — so the planned row read exactly
like the shipped ones, and a reader concluded this package gates tools on
Laravel Gates.

That reader then told two other agents, one of which built on it. A status line
four lines above a table does not travel with the row someone quotes.

**And then it happened again, inverted.** The fix above added per-row status but
<!-- factcheck-ignore-next: quotes the retracted claim in order to explain it; the live status now lives in the table above -->
also asserted, right here, that there was *no `Gate` reference anywhere in `src/`*. `ToolAuthorizer` later shipped and gates on exactly that — so the
sentence written to correct the misreading became a false claim in the opposite
direction, in the passage warning about it. It survived because our fact-checker
verifies that things named in prose **exist**; nobody was checking claims that
something **does not**. A negative claim is the more dangerous kind: it is what
a reader uses to decide something still needs building.

So this section no longer states what the code lacks. Absence is asserted in one
place — the Status column — where a checker can reach it.

## Decisions already taken

| Question | Decision | Why it matters |
|---|---|---|
| Where threads live | Contract in Prism, Eloquent implementation here — **shipped** | Prism keeps no storage opinion; anything can satisfy the interface |
| Event bus | A separate harness stream | Telemetry is observability, harness events are interface — different audiences and stability guarantees |
| State store | Redis-first behind a configurable driver — **shipped** | Redis and database behind one contract, with the durable slot guarded |
| Package | `particle-academy/prism-harness` | Its own repo under the Particle Academy brand |

### On Redis

Redis is the natural fit for live session state, but in most deployments it is a **cache**, and a
cache is disposable by definition. The driver contract must therefore distinguish:

- **Ephemeral** — active mode, current model, run bookkeeping. Losing it degrades to a default.
- **Durable** — threads and pending approvals. Losing these means a half-executed agent action
  disappears.

A configuration pointing durable state at a volatile store should fail loudly rather than accept
it. This is not hypothetical caution: a sibling project in this workspace lost de-duplication
state to exactly that pattern, where a single `cache:clear` between two runs would have silently
double-awarded everything.

## Still open

- ~~Whether **mode** is owned by the session or the thread.~~ **Decided: the session** — and
  the case that worried us cannot arise here. Mastra's problem is that a Session and a Thread
  are separate objects, so one participant can hold several sessions over one thread and the
  mode has to belong to exactly one of them. In this package both are addressed by
  **participant + scope**: `Session::key()` and `Thread::forParticipant()` take the same two
  values, so the mapping is 1:1 and there is nothing to come apart. Mode lives in the
  ephemeral half, where losing it falls back to a default rather than losing work.
- ~~Whether subagent **step budgets** nest or reset.~~ **Decided: they nest.** A resetting
  budget is not a budget — see Subagents above.

- **What happens when the store is unreachable mid-claim.** The claim either happened or it
  did not, and the worker cannot tell which. Retrying risks a double claim; not retrying
  risks a lost task. Today a failed claim propagates the store's own exception and nothing
  retries — which is the honest behaviour while the question is open, not an answer to it.
  A task lease bounds the damage of a claim that landed unseen: it expires and the task
  returns to `todo`. This needs deciding before a task list is trusted with anything
  expensive.
- **Whether a task carries a payload beyond `instruction`.** Consumers will want structured
  input. Adding it invites the task to become a job, and this is not a queue. A consumer
  that needs one today adapts its own model, where the payload is already a column.

Items are added back here when a decision is genuinely undecided, not to record work that
is merely unfinished — that lives in the issue tracker.

## Adopting this as your transcript layer

Moving an existing `Chat`/`ChatMessage` table into threads is supported, and two things are
worth knowing before you write the migration, because neither is visible from the API.

**The thread table is trusted storage.** Rebuilding an attachment resolves whatever locator
was recorded, so a row carrying a `local_path` or `url` becomes a **file read or an outbound
fetch at replay time**. Your chat rows are, by construction, populated from request input —
so a bulk migration moves user-supplied content across that boundary, and any historical
message carrying a media locator becomes a fetch the first time that thread is replayed to a
model. Sanitise locators on the way in rather than discovering this at replay.

**The harness records at turn end, not mid-stream.** `Thread::record()` takes
`$response->messages` once a turn completes, and `messages()` is a lazy read. This is the
durable record, not the live wire — keep your own streaming for the live view. Both can be
joined afterwards: every message carries its `run_id`, and a subagent's rows carry
`parent_thread_id` and `root_run_id`, so a nested run's activity can be correlated into an
existing stream without routing it through this package.

## Background

Full analysis — including a gap comparison against `laravel/ai` — lives in the envelope at
`.ai/discovery/laravel-ai-sdk-and-prism-harness.md`.

## Fenced runtime stores

Agent turns now require the optional `FencedSessionStore` capability on their
ephemeral store. Built-in database and Redis stores, including the address-index
wrapper, implement it. A custom store without it is refused before a run starts;
ordinary `SessionStore::withLock()` remains available for other synchronous work.

Stop and drain all old workers before upgrading, then restart them together.
An old worker still uses unfenced state writes and can overwrite a new worker;
mixing the two implementations in a rolling upgrade is unsafe. Database driver
subclasses that override the old protected expiry-only acquire/release helpers
must move to the UUID-based fenced capability; those old helpers are removed.

Set the lease duration above the longest supported synchronous callback. A
rejected terminal write discards the stale result; it cannot cancel external
side effects that the callback already performed. Fencing does not renew leases.

Custom stores must supply `withFencedLock()` and atomic `putIfOwned()`: the latter
writes this session's ephemeral state only while its UUID owns an unexpired lease.
Returning false writes nothing and produces `SessionLockLost`, with no terminal
event. Use the backend's clock and an atomic compare/write (or a transaction
locking the lease), and release only the matching UUID. A separate ownership
read before a normal write is insufficient. Preserve existing pre-run
configuration-failure events, which do not persist a run.

For the database, the lease row is locked for the state transaction and the final
UPDATE or initial INSERT also carries the token/expiry predicate. Redis uses a
single Lua compare/write and native key expiration.

### Streaming holds the session lock (breaking for custom stores)

`stream()`, `streamSteps()` and `streamDecision()` hold the session's fenced lock for the
whole iteration, as `send()` holds it for its call. Earlier releases took no lock while
streaming, so two requests could run one session at once. The lock is taken on the first
iteration, before any run state or provider call, using `lock_ttl` and `lock_wait`. A session
that another request holds throws `SessionLocked` and makes no provider call. The lock is
released when the stream ends, fails or is abandoned. If the lease lapses mid-stream, the next
state write throws `SessionLockLost`; nothing is written without the lock. Set `lock_ttl` above
your longest streamed turn.

Holding a lock across yields needs `Prism\Harness\Contracts\HeldSessionLocks` on the ephemeral
store: `acquireFencedLock($key, $ttlSeconds, $waitSeconds)` returns the fence token or throws
`SessionLocked`, and `releaseFencedLock($key, $token)` releases only if that token still owns
the lock. Use the same token semantics as `withFencedLock()` and `putIfOwned()`. The built-in
database and Redis stores and the address-index wrapper implement it.

**This is a breaking change for custom stores.** A store that implements `FencedSessionStore`
but not `HeldSessionLocks` still works for `send()`, but streaming on it throws a
`LogicException` before anything runs. `FencedSessionStore` itself is unchanged.
