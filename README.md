# Prism Harness

Durable agent sessions for Laravel — threads, modes, tool permissions and subagents on top of
[Prism](https://github.com/Particle-Academy/prism).

> **Status: threads work; the rest is still design.** Conversation persistence is
> implemented and tested. Sessions, modes, permissions and subagents are recorded below as
> decisions, not code yet.

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

| Concept | Laravel counterpart |
|---|---|
| Controller | Singleton in the container; config file plus mode classes |
| Session | Resolved per request from a durable store, keyed on participant + scope |
| Thread | **Built.** Eloquent models here; contract defined in Prism (0.113) |
| Modes | One class per mode, container-resolved so they are testable |
| Workspace | A scoped Filesystem disk — Laravel already sandboxes; don't rebuild it |
| Permissions | **Gates and Policies** — "may this tool run" is an authorization question |
| Subagents | A Prism Tool wrapping a nested run, with a narrowed toolset |
| Event bus | Laravel events over Reverb — a harness stream, separate from Prism telemetry |

## Decisions already taken

| Question | Decision | Why it matters |
|---|---|---|
| Where threads live | Contract in Prism, Eloquent implementation here — **shipped** | Prism keeps no storage opinion; anything can satisfy the interface |
| Event bus | A separate harness stream | Telemetry is observability, harness events are interface — different audiences and stability guarantees |
| State store | Redis-first behind a configurable driver | Redis and database behind one contract |
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

- Whether **mode** is owned by the session or the thread. Mastra treats it as session state but
  persists it on the thread; those come apart when one participant holds several sessions over
  one thread.
- Whether subagent **step budgets** nest or reset.

## Background

Full analysis — including a gap comparison against `laravel/ai` — lives in the envelope at
`.ai/discovery/laravel-ai-sdk-and-prism-harness.md`.
