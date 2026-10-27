# Multi-agent coexistence (G154)

**Proposed for maintainer, MOIC and Fancy review; no implementation is shipped.**
This covers H1–H14 and MOIC's requests (a)–(f), as relayed by the maintainer.
Existing valid single-agent applications retain their addresses, configuration,
provider requests and task semantics. PHP is the harness family reference;
TypeScript and Python follow the reviewed contract, with published-artifact tests.

## Source verification

Checked PHP **0.18.0**, tag/source
`32fed824f988caccd70e54fb1a4d31bd4f62d770`, tree
`1188098110b91dda6193d966541be2cefb8fe4b9`. Paths and lines below refer to that
source (`src/` unless otherwise noted), rather than the original report's vendored 0.17.0. This is a source audit,
not evidence that the proposed isolation tests have already run.

| Report | Current observation | Proposed change |
|---|---|---|
| H1 | `Sessions/Session.php:74`, `Support/LiveThreadKey.php:9`: participant and scope only | Optional immutable agent namespace in both addresses |
| H2 | `Tools/ToolRegistry.php:22–42,59–82`: static/factory/provider names overwrite; wildcard unions all registered sources | Agent-owned registries and duplicate refusal across all effective sources |
| H3 | `Tools/ToolRegistry.php:62–65`: every provider runs for every session | Select providers by the session agent before calling them |
| H4 | `Modes/ModeRegistry.php:11–18,59`: one readonly config map; no registration API | Agent-scoped registration, duplicate diagnostics and default mode |
| H5 | `Tools/ToolAuthorizer.php:21,29,83,107`: one ability pair and enable flag | Agent-aware offer/call policy resolution |
| H6 | `PrismHarness.php:36,41` accepts any Eloquent `Model`; `ToolAuthorizer` passes it to Gate | Non-user models already fit; add explicit actor/participant separation and a non-model address form |
| H7 | `Events/HarnessEvent.php:24–45`: session/run lineage, no agent field | Agent correlation on all events |
| H8 | `Subagents/RunContext.php:39–48`, `CallReservation.php:10`; service provider:124–144 | Agent on run/reservation/settlement; per-agent observer selection |
| H9 | `PrismHarness.php:75`: shared app list defaults to `default`; session lists already use session keys | Agent-specific default app lists; retain deliberate shared lists |
| H10 | `Models/Thread.php:217`: retirement retains history; no scoped erasure API | Authorized erasure across live and retired owned threads and descendants |
| H11 | `Sessions/Session.php:463`: forget removes one session's ephemeral keys only | Explicit agent/prefix ephemeral forget, separate from destructive erasure |
| H12 | Service provider:106–144 and `config/prism-harness.php`: one runtime config | Per-agent blocks with defined global fallback |
| H13 | Maintainer answered: ACP is standalone; it does not attach to harness sessions | No ACP attachment change; preserve `particle.academy/<name>` metadata and reserved trace keys |
| H14 | TS0.8.0/Python0.3.0 already model participants as typed IDs | Port the settled semantics and execute the same conformance inputs |

H4 needs a precise qualification: ModeRegistry does not itself expose an
overwriting `register()` method today. Extensions can overwrite before it receives
the merged PHP array. Duplicate PHP literal keys are already lost when PHP
evaluates that array; the new owned registration path can refuse collisions it
actually observes, without claiming to recover those lost declarations.
MOIC(d) maps to the scoped erasure contract below; MOIC(e) maps to duplicate
refusal plus per-agent events and accounting labels.

MOIC(a) is absent: `Thread::forParticipant()` creates when missing
(`Models/Thread.php:136–204`). MOIC(b) is partly present: `Thread::record()` already
wraps the whole message batch in a transaction (388–423), and address insertion
uses a savepoint; not every read/store operation is isolated. MOIC(c) is absent:
`ThreadMessage` records `run_id`, not the speaking agent. MOIC(f) is a deliberate
current behavior: `Tasks/StoreTaskSource.php:328–340` returns every expired lease
to `todo`; there is no attempt ceiling. These existing guarantees stay intact.

## Address and identity

Proposed shapes are reviewable pseudocode:

```text
harness.forAgent('support').for(participant).session('compass:29')
Thread.findLive(participant, scope: 'compass:29', agent: 'support') -> Thread|null
harness.forAgent('support').tasks()               // agent's default list
thread.record(messages, runId, speakerAgent: 'support')
```

The agent is selected by trusted application code, never a model/tool argument.
Use a bounded, canonical lowercase identifier, with null as the legacy/default
namespace. Omitted agent preserves the exact old session key and live-thread
hash. A named agent hashes `[domain, agent, participantType, stringId, scope]`;
domains are `prism-harness.session.v1` and `prism-harness.thread.v1`, respectively.
Use compact UTF-8 JSON with unescaped Unicode/slashes and no whitespace. Do not
concatenate unescaped parts or normalize scope case. Publish exact cross-language
key vectors, including Unicode, delimiter and integer/string-ID cases.

Add a nullable thread `agent_id`; existing rows remain null and retain their
live_key. New named rows use the new hash under the existing uniqueness guard.
Address fields are immutable once resolved. New migrations are additive; never
rewrite historical migration files or implicitly move a legacy conversation into
a named agent. An explicit migration/import is required for such a move.

`findLive` uses the same complete address and uniqueness predicate as creation,
filters retirement, and returns null without inserting, touching stores or
allocating a session. Include the equivalent scope-only shared-thread read.

Keep address ownership separate from the speaker label. MOIC can explicitly
record a handoff speaker on its already-authorized shared transcript; that label
does not change the thread owner, grant tool permissions or resolve a session in
another namespace. Named task-list builders qualify explicit list names too;
the existing app-wide entry point retains deliberately shared legacy lists.
Subagents inherit the owner namespace by default. A different
agent target requires an explicit host-owned delegation binding, never a supplied
agent string. Parent/root lineage and the shared budget ledger remain enforced.

Existing non-user Eloquent participants continue to work. Add a typed
`ParticipantRef(type, id)` for non-model identities, alongside Model support, and
an explicit authorization actor binding for that new form. PHP references resolve
through a registered participant adapter; arbitrary type/class strings never
reach Eloquent morph resolution. Missing actor/policy
bindings fail before an offer or invocation. For legacy Models the participant
remains the actor unless the host opts into separation. Never impersonate the
triggering person or read ambient Auth state to infer an automation's authority.
Context is immutable per operation; jobs restore any host context in `finally`.
Queued work carries identity and generation, never a serialized permission grant;
the effective policy is rechecked when it executes.

## Registries, authorization and configuration

Register tools, factories, providers and modes with `(agent, name, owner)`.
Named registrations require a bounded diagnostic owner label. Duplicate names
in the same effective namespace throw with both owners, including static versus
factory, provider output versus static, and provider versus provider. Reusing a
name in different explicit namespaces is valid. Reject collisions before any
handler runs or provider request is issued, independent of service-provider boot
order. Keep legacy call forms in the null namespace.

`*` expands only that agent's effective tool set. A named agent does not inherit
legacy tools/providers by accident; sharing requires an explicit target-agent
registration. Built-ins are registered inside the effective namespace too.
Resolve provider output for the current session and validate collisions on every
resolution. There is no process-global mutable "current agent" singleton.

`agents.<id>` provides scalar provider/model, step and output ceilings, lock
settings, default mode, observer, default task list and authorization options.
Unset scalar fields fall back to today's global settings; explicit false/null
retain their documented meanings. Authority collections are not silently unioned:
mode templates are resolved in the target namespace, and their tool/skill lists
must resolve there. Registering the same effective mode twice fails; intended
replacement requires an explicit reviewed replacement API naming its predecessor.
The default mode must exist in that agent's registry.

Allow per-agent offer/call ability names or a dispatcher receiving the immutable
agent, participant, actor, mode and tool. Global abilities remain the legacy
fallback. Preserve offer-time filtering **and** per-call rechecks, the current
defined-policy-but-disabled refusal, approval requirements, and explicit call
policy for task completion. A forged agent/mode/scope or foreign approval ID
cannot widen the effective set. MOIC's skill grants and capability policy remain
host-owned inputs to this filtering, rather than new implicit authority.

Add nullable agent attribution to event correlation, messages, RunContext and
reservation/settlement records. Legacy correlation keeps its existing fields;
agent correlation is emitted for a named context. No extra provider payload
fields are introduced by default. A child keeps its root ledger and narrowed budget, even across an
explicitly delegated agent; labels must not create another spend account.
Select observers per agent, with the existing RunContext override taking
precedence, and reject conflicting session/context identity before reservation.

## Storage isolation, forgetting and erasure

Offer `database.isolate_operations` (default false, per-agent override) for every
Harness-owned SQL storage operation, using its actual connection. A caught SQL
failure rolls back the operation/savepoint so an outer PostgreSQL transaction can
continue. Existing atomic message batches remain atomic. No transaction spans a
model call, external tool or generator consumer; paged reads isolate each bounded
page. Arbitrary application Eloquent queries are outside this wrapper. Refuse an
unsupported nested-savepoint configuration instead of claiming isolation.

Keep `Session::forget()` ephemeral-only. Add separate, host-authorized
`forgetEphemeral(addressSelector)` and `eraseHistory(addressSelector)` operations.
Selectors always bind participant/tenant and agent; scope prefixes are literal
strings, not SQL LIKE/Redis glob syntax. A deliberately app-wide maintenance
selector requires an explicit maintenance grant. Produce a bounded dry-run plan
and reauthorize it at execution; no erasure API is automatically a model tool.

Hashed keys cannot support prefix discovery by themselves. Introduce a durable
address index on first write, mapping structured addresses to owned store/task
keys and all thread generations. Legacy backfill is explicit and reports any
unindexed external/custom-store state; never claim complete erasure without it.
Stores lacking the required indexed erasure capability refuse, rather than
silently deleting only known rows. Do not use Redis KEYS or unbounded SQL scans.

Erasure fences the selected address generations, revokes pending approval/task
handles, checks generation fences before dispatch/reservation and persistence,
and deletes all matching live **and retired** threads/messages and owned
state, following validated descendant linkage. Old queued workers/handles cannot
recreate erased state; a new conversation needs an explicit fresh generation.
Cross-owner descendants/shared transcripts require their own authorization,
otherwise the plan refuses. Speaker labels alone never select another owner's
data. Cross-store cleanup is resumable and reports completion only after every
owned target succeeds; it cannot promise a database/Redis distributed transaction.

## Task claims

Add opt-in `max_claim_attempts`, null by default. Null preserves unlimited expired
claim recovery and the existing five-key canonical TaskRecord. With a positive N,
count each granted claim atomically under the existing list lock: N includes the
first grant. At the final lease's expiry the task becomes terminal `exhausted`,
with a typed `claim_attempts_exhausted` event; it is never silently requeued.
This is distinct from `failed`: a worker may have died before doing any work.
The opt-in state extension needs an approved canonical state/schema amendment
before implementation, following
[decision0020](https://github.com/Particle-Academy/prism-parity/blob/main/docs/decisions/0020-collapsed-states.md)
rather than collapsing those outcomes. Ordinary
lease renewal is not another claim. A stale worker cannot complete or reset the
counter, and one blocked task must not wedge unrelated claimable work.

Keep counters in a versioned durable list envelope, not extra legacy TaskRecord
keys. Existing rows start at zero observed attempts when opted in; do not invent
past attempt counts. Opt-in migration requires stopped old writers and an explicit
version check. Publish expiry-boundary, concurrent-claim, restart and exhaustion
vectors. MOIC's dependency/person-step scheduling stays in its AgentTaskSource.

## Port, conformance and delivery plan

After design review, implement in this order: address/actor/index primitives;
owned registries/config/authorization; event/message/accounting propagation;
isolated storage and bounded forget/erase; task ceilings. Carry each settled
caller-visible behavior into TS and Python in the same work tranche. Adapt to
their store contracts, rather than pretending their existing memory/file stores
are Eloquent databases. SQL savepoint guarantees require a real SQL adapter and
native database proof; unsupported adapters must report refusal explicitly.

Publish a reusable coexistence fixture application plus identical semantic inputs
and an independent safety oracle in prism-parity. It runs Charlotte, support and
an ACP stand-in under every provider boot permutation with no live credentials.
It must exercise the following, with native results retained:

| Contract | Required positive and hostile controls |
|---|---|
| Defaults/migration | Published0.18/0.8/0.3 legacy keys, stored bytes and frozen provider requests unchanged; old rows readable; new named rows isolated |
| Names/providers | Every boot order; exact tool sets per mode; equal names in different agents work; injected same-agent collisions name both owners; unselected providers never run |
| Authority | Wildcards scoped; forged agent/mode/scope/unlisted tool refused; offer/call gates and approval policy enforced; cross-session approval IDs invisible |
| Participant/handoff | Human, non-user Model and typed identity; explicit actor binding; shared transcript speaker attribution without authority transfer; context restored after success/failure |
| Budget/events | Correct agent on every message/event/reservation/settlement; observer isolation; parent and child share hard spend cap; forged context cannot allocate a fresh ledger |
| Persistence/queue | Separate session/task/lock keys, worker restart and serialized agent identity; noncreating reads leave DB/stores untouched; real owned PostgreSQL outer transaction survives a caught injected statement failure |
| Forget/erase | Exact/prefix/agent scopes; retired rows and validated descendants; literal wildcard characters; blocked stale writers; cross-owner refusal; interrupted multi-store cleanup resumes |
| Retry ceiling | Default recovery unchanged; N counts grants; exact expiry; two workers; renewal; persistent restart; exhausted task terminal and unrelated work claimable |

Run sabotage variants that create a same-agent name collision, remove an agent/
scope guard, and add an unlisted tool. Each must turn the fixture red, including
when all languages agree on the unsafe behavior. Database tests assert an owned
database name and nonce marker **before** migrations or concurrent workers; never
use a consumer application or shared workspace database.

Source candidates first prove the red/green seam. After maintainer-owned releases,
fresh registry installs execute the same fixture in PHP/TS/Python with exact
versions, no source/path overrides and no skipped claimed coverage. Generate
canonical outputs from the PHP execution; preserve native differences explicitly.
The catalog and conformance status change only when their respective proofs exist.
Tangents resumes after the reviewed G154 work, rebased onto its address contract.
