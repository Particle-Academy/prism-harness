# Prism Harness

Durable agent sessions for Laravel — threads, modes, tool permissions and subagents on top of
[Prism](https://github.com/Particle-Academy/prism).

> **Status: every planned surface now ships.** Threads, sessions, modes, skills, tool
> permissions, approvals, subagents, budgets, the event stream and a config doctor are
> implemented and tested. Tool authorization is **off by default** — see the Concepts table
> for exactly what each row provides, and `harness:doctor` for what your own configuration
> is actually doing.

> **Working on this package?** Read **[`AGENTS.md`](AGENTS.md)** first — the boundary
> this package has to hold, the gates that must be green, and the traps that have
> already caught someone.
> `@link AGENTS.md`

## Sessions

```php
$session = PrismHarness::for($user)->session('support');

$session->usingMode('plan')->usingModel('claude-sonnet-4-5');

$session->lock(function (Session $session) {
    // whatever must not happen twice
});
```

**Resolved, never held.** A Laravel request boots, serves and dies, so a session cannot be an
object kept in memory the way Mastra's is. Every call rebuilds one from a store, which is what
makes a fresh worker see the same mode, model and conversation as the request that set them.

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
    ->using(Provider::Anthropic, 'claude-sonnet-4-5')
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
    foreach ($response->pendingApprovals() as $pending) {
        $session->approve($pending);              // or ->deny($pending, 'not on production')
    }
}
```

**The decision is a row, not a promise.** It is recorded in the thread, so the approval a
person grants this morning is readable by whichever worker resumes tonight — a different
process, possibly after a deploy. That is the whole reason the durable slot is guarded.

Prism **denies by default** when it finds no response for a pending request, so a lost or
unanswered approval fails closed rather than executing. And `awaitingApproval()` is **not a
failure**: a caller that treats it as one will retry, and retrying discards the half-executed
action somebody was asked to authorise.

## Events

`RunStarted`, `RunFinished` and `RunFailed` are ordinary Laravel events — broadcast them over
Reverb, queue them, or ignore them.

They are deliberately **not telemetry**. Telemetry is observability: sampled, droppable, read
by whoever is debugging. These are interface — an application builds UI on them, so they carry
a stability guarantee telemetry never will. `RunFinished` states `awaitingApproval` outright
rather than leaving a listener to infer it from a finish reason, and `RunFailed` carries only
the exception **class**, because a provider message can contain a request URL with a key in it
and an event may end up on a screen.

Every event carries `run_id`, `parent_run_id` and `root_run_id` — the same identifiers on the
stored rows — so an existing live stream can be joined to this record without adopting it.

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

### A cost cap you cannot enforce is refused

`Usage::$cost` is nullable, because not every provider reports one. Folding that into
`+= 0.0` would leave a cost budget that reads as enforced and can never trip. Unmetered runs
are counted separately, and a tree with a `max_cost_usd` that has taken any of them **stops**
rather than spending on under a cap nobody can measure.

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

    $tasks->release($task, TaskOutcome::Done);   // the APPLICATION decides
}
```

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

### An unreadable lock expiry means wait, not take

Everything above is exclusive because the store's lock is, so the rule the lock
follows is worth stating: **a lock is reclaimed only when its expiry can be shown
to have passed.** An expiry that is absent, empty, or not a complete date-time is
not evidence of anything, and the row is left alone — the caller waits and gets a
`SessionLocked`, which is loud and recoverable.

The failure this prevents is not a parse error. **Every prefix of a timestamp is
an earlier timestamp**, so a value truncated by a torn write does not fail to
compare — it compares as long ago, and the sweep deletes a lock somebody is
actively holding. Both ports of this package shipped that bug in their file
stores; here the expiry is a column written by one statement, and the
completeness check is what closes the remaining gap.

Ports keeping a lock in a file must write a **terminator** and treat a value
without one as unreadable. Without it there is nothing to distinguish a complete
expiry from the first half of one.

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
    $session->approve($response->pendingApprovals()->first());
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
