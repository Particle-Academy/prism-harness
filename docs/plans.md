# Agent plans

A plan is an optional, ordered list of steps that an agent writes for a goal.
A person approves it, and then the host moves it forward one step per turn. A
plan says what to do next and when to stop for a person. It grants nothing:
every tool call a step makes still goes through the mode's tools, approvals,
the tool policy and the run budget.

## Turning plans on

Plans are off unless both switches are on:

```php
// config/prism-harness.php
'plans' => [
    'enabled' => true,
    // ...
],
'agent' => [
    'modes' => [
        'study' => [/* ... */ 'plans' => true],
    ],
],
```

When either is off, no plan tool is offered, nothing is added to the system
prompt, no plan step is streamed, and `$session->plan()` throws
`PlansDisabled`.

## A person approves every plan

A drafted plan waits for a person before any step runs. The draft is an
ordinary tool approval, answered with `approve()`, `deny()`, `decide()` or
`streamDecision()`. Turning this off is a host decision made in config
(`plans.require_approval => false`). There is no per-call option, and nothing
the model sends can change it. Plan changes and approval pauses always go to a
person. A plan started without approval stays marked that way: the plan context
tells the model it was "not reviewed by a person", even if approval is turned
back on later, and says "approved by the person" only when one did.

## The agent's tools

| tool | asks a person | what it does |
|---|---|---|
| `plan_draft(goal, steps)` | yes | Proposes the plan. Each step is the agent's own (`owner: agent`, with an `instruction`) or a question for the person (`owner: human`, `interaction: input`, with a `question`). |
| `plan_complete_step(summary)` | no | Reports the current step done. The next `advance()` settles it. |
| `plan_pause_for_person(kind, question, options?)` | `approve`: yes | Stops before the current step. `approve` is a tool approval; `input` waits for `answerStep()`. |
| `plan_propose_change(reason, base_revision, steps)` | yes | Proposes a new list for the steps that can still be replaced. Every other step is kept by id. |

No tool takes an approval id, and only `plan_propose_change` takes step ids
(to keep steps unchanged). A refusal is a fixed sentence and a code, such as
`[plan.text]`.

No tool policy can name a plan tool. `ToolPolicy::fromArray()` refuses one in
any spelling.

## Bounds

A plan has at most 40 steps (`max_steps`, 20 by default). A title is at most
160 characters, an instruction at most 600, and a note at most 200. The first
update must stay under 8 KB. Text with line breaks, control, bidi or
zero-width characters, guillemets, or the plan frame's markers is refused. A
plan over a bound is refused when it is drafted, and is never truncated.

## Driving a plan

```php
$plan = $session->plan();

$plan->advance();             // settle the last step, run one turn on the next
$plan->streamAdvance();       // the same, as run steps
$plan->current()?->toArray(); // the whole plan, for a fetch-on-change endpoint

// Host calls: authorize first, and name the person as the actor.
$plan->answerStep($stepId, 'Paris', null, RunContext::metadata(actor: 'user:42'));
$plan->resume(RunContext::metadata(actor: 'user:42'));
$plan->cancel(RunContext::metadata(actor: 'user:42'));
```

`advance()` runs at most one step. A step is done only when the agent called
`plan_complete_step` and the turn ended cleanly with nothing pending. A turn
that ends without the tool sends the step back to `todo`, and it counts as an
attempt. Completion is never read from the model's text.

When a step's turn asks for a tool approval, the step is held on that
approval. Once a person answers it through `decide()` or `streamDecision()`,
call `advance()` again. A refused approval fails the step. It is never shown
as done.

Limits: `max_attempts` per step (3), `max_pauses_per_step` (1),
`max_changes` per plan (5), and `max_advances` per plan (60). Hitting one
blocks the plan. `resume()` adds a retry step where that makes sense. It never
replays a step that paused too often, and it never resumes a plan that ran out
of advances.

Only one `advance()` runs at a time per session. A second one gets
`SessionLocked`.

## Plan budgets

`plans.budget.mode` is `per_step` (the default: each step's turn gets the
per-turn budget), `shared` (one ledger of `max_steps` model steps, and
optionally `max_cost_usd`, across the plan's own turns, on top of each turn's
own limits) or `none` (no plan-level budget). Host code can choose
it for one plan with `useBudget()`; the configured limits are its ceiling.
The first choice or first shared turn fixes it, and after that it can only be
lowered. When a shared budget is spent, `advance()` returns `blocked` with
reason `plan_budget_exhausted`, the plan is blocked with that reason,
`PlanBudgetExhausted` is dispatched once, and `resume()` refuses it.

The plan's own turns are the turn `advance()` runs for the step it claimed, the
resume of an approval that step asked for, and a subagent child those turns
started, on any turn the runner did not start (an answer, a new message, or a
host run context, which is narrowed to what is left). Other turns on the session spend nothing
from the plan's budget and are never refused for it. A decision on the step's
approval is refused with `plan_budget_exhausted`, recording nothing, once the
shared budget is spent; a step held on an approval when the budget is spent
blocks the plan on the next `advance()`.

## Hosts with queues

If a queued job calls `advance()`, register a reaper callback and schedule the
command:

```php
app(Plans::class)->reapUsing(fn (string $sessionKey, string $planId) => AdvancePlan::dispatch($sessionKey));
Schedule::command('harness:plans:reap')->everyFiveMinutes();
```

With plans on and a queue that is not `sync`, `harness:doctor` fails when the
reaper has not run recently.

## What a client sees

- `PlanUpdated` is dispatched on every write. It carries ids, the revision,
  the status and counts only. Fetch `current()` for the rest.
- With `plans.events.run_steps => true`, the run-step stream carries
  `plan_updated` steps (contract `g282.3`). The first is a full snapshot, and
  later ones carry only the steps that changed. Every other step stays
  `g282.1`. Leave this off until your consumer knows `g282.3`.
- The plan context the model reads each turn is built per call and never
  stored in the thread. Instructions, notes, questions and answers do not
  appear in events, plan steps or logs.
