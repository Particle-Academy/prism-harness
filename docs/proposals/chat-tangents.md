# Chat tangents: proposed API and merge record

**Design for maintainer review. Nothing in this document is implemented or a
shipped API.** PHP prism-harness is the harness family's reference. Implement
open/merge/abandon, events, `verbatim` and `summary` after review; the local Lab
then compares both presentations and proves its judge before capped RSI trials
of `select`/`relevant`. Ports follow the settled PHP contract.

## API and permission boundary

Proposed call shapes (pseudocode; names are reviewable):

```text
AgentMode(..., tangents: 'none')             // none | user | agent | both
session.usingTangents('user')               // trusted PHP: narrow mode policy
tangent = session.tangent(purpose, seed: 'none', context: run)
tangent.send(prompt, context: run)          // ordinary harness session execution
preview = tangent.preview(strategy: 'summary', context: run)
record = tangent.merge(preview)             // commit a validated frozen preview
tangent.abandon()                          // closes with no parent message
commitment = tangent.session.commit(text, citations: [position])
                                           // authorized user action; no actor argument
```

`merge(strategy, context)` is the convenience form of preview then commit.
`none`, `summary`, and `view` are the seed choices. `view` reads the parent's raw
stored messages through the immutable fork position as a read-only prefix; no
parent rows are copied or written. `summary` uses a metered harness run over that
same bounded prefix and stores its versioned result as child context. `none`
passes only the purpose. Tool-call/result pairs must remain complete in either
prefix; a fork between a call and its result is refused. Compaction changes a
view, never citation positions or the stored source rows.

Mode policy defaults to `none`; an unset session restriction inherits the mode.
When set, the session restriction intersects the mode policy. No request/tool
argument can raise it.
With no configuration change, an upgrade must produce a byte-identical provider
request to the current published release: no extra tool, skill, system prompt,
metadata or framing. Record actual text/structured/stream request bytes from
that release with frozen clock/IDs and compare them against default-`none`
requests; do not normalize away capability fields to make the comparison pass.
The host authenticates and authorizes the participant/scope before resolving a
user handle. Agent handles bind to the current authorized runtime/run; the model
cannot supply an actor, participant, parent thread, run context or budget. A
handle is reauthorized on every operation, including preview, commit and resume.

| Policy | Authorized user API | Agent tools / model instructions |
|---|---|---|
| none | Refused | Absent |
| user | Allowed | Absent |
| agent | Refused | Present |
| both | Allowed | Present |

Agent-enabled modes register `open_tangent`, `merge_tangent`, `abandon_tangent`.
A separately enabled `allow_agent_commitments` policy (default false) may add
`commit_tangent`; it requires `agent`/`both`, existing approval rules and an
authorized bound handle. Its arguments are handle/text/citations, never actor.
Opening returns an opaque handle, not permission to arbitrary thread IDs. For
`none`/`user`, these names, schemas, descriptions, skill text and injected prompts
are absent from the final model request; forged calls fail as unknown tools.
This guarantee covers harness-supplied capability material, not user-authored
chat text that happens to discuss tangents. User authority cannot be asserted
through an agent tool argument. Existing tool approval rules still apply.

## Durable state, lifecycle and concurrency

A proposed `harness_tangents` row references existing parent/child `Thread` rows
and records participant binding, fork position, seed/version, purpose, run
lineage, policy ceiling and `open | merged | abandoned`. These fields are
harness-owned, never mass assignable. Child addressing uses an opaque ID rather
than appending an unbounded purpose to a session scope. Descendant depth is
bounded by `RunContext::MAX_DEPTH`; active children and byte/message counts have
finite operator ceilings. Nested children can merge only into their immediate
parent; evidence from a grandchild must pass through its child's merge record.

Opening locks the parent row and captures its last complete stored position.
Preview snapshots both thread high-water marks, source hashes and policy, then
releases locks before any model call. It is immutable and may be shown without
committing. A commit transaction locks parent, child and tangent rows in a stable
order, rechecks authorization/policy/source marks, and either:

- Appends exactly one framed data message to the parent and stores a canonical
  merge record linked to that message, then changes `open` to `merged`; or
- Refuses a stale/invalid preview without appending or closing the tangent.

Parent changes after the preview require a fresh preview/conflict check, even
for `verbatim`; retries do not silently spend another model call. A unique merge
key and conditional state transition make an identical committed-preview retry
return the original record without a second parent message. Different-preview
retries and merge/abandon races refuse. Abandon is idempotent and appends nothing.
Retirement or changed participant/scope authority invalidates existing handles.
An event outbox entry is written in the same transaction; delivery after commit
is retryable with a stable event ID for consumer deduplication. A process crash
between commit and delivery must not lose the event. Source messages remain
intact after close.

The single parent row uses the existing stored **`type = user`**, with a normal
UserMessage payload whose `content` is a fixed `[tangent-merge:v1]` notice plus
canonical JSON. The notice identifies it as untrusted tangent evidence, not a
user instruction or new authorization. The provider sees role **`user`** with
that exact content, not `system`/`assistant`/`tool_result`; a user-opened tangent
has no pending tool call and must not synthesize one. The canonical sidecar
retains the typed merge and provenance. Role/framing do not confer commitment
authority; the validator still uses the trusted commitment records.

There is **no new stored row type**. `harness-thread-rows` explicitly excludes
user rows (G-58), so its existing scope/13 fixtures remain unchanged. The
implementation needs a separate narrowly scoped PHP-generated tangent-merge
record/replay corpus, including hostile quoted text, that checks the canonical
record and exact user content survives PHP/TS/Py generic replay. Do not widen
the existing suite or bless G-58's empty-list/omitted-field disagreement.
Existing TS/Py generic user-row readers can carry the marker as ordinary user
content before tangent APIs are ported; the new corpus must execute and verify
that path rather than infer success from its type name. It must neither skip
the content nor interpret the marker as a command. Tangent operations, sidecars
and events remain an explicit port gap and refuse until implemented; generic
replay alone is not feature parity. A sibling parity change accompanies the PHP
implementation before its release, rather than declaring an untested new type.

## Merge record v1

The linked [merge-record-v1.schema.json](merge-record-v1.schema.json) specifies
the canonical stored shape.
The harness supplies envelope IDs, source marks and strategy identity; model
identity and metering stay in the linked actual run/event records. The merger
proposes only content lists. Unknown fields are refused. Positions address raw
stored rows, starting at 1; an empty parent has fork position 0. Schema validation
is necessary but does not replace source/authority validation below.

| Field | Authority and validation |
|---|---|
| findings / rejected / open_questions | Text plus nonempty citations to this tangent's stored positions through `tangent_end_position`. A valid citation proves provenance, not factual correctness. |
| decisions | Only references to explicit user/agent commitments already recorded through an authorized, trusted harness commitment action. Canonical text/actor/citations are copied from that record; the merger cannot write or rewrite a commitment. |
| conflicts | Both tangent citations and parent positions strictly after the fork through `parent_observed_position`; never silently overwrite a parent commitment. |
| omissions | Harness-generated counts/reasons for dropped uncited content; never drop an invalid decision into another authoritative category. |

Commitment capture is an explicit proposed host/runtime action, not an extractor
over transcript prose. User commitments originate from an authenticated user
action via `tangent.session.commit(text, citations)`; agent commitments require
the explicit `commit_tangent` action under
the developer's commitment policy. Neither a webpage/tool response nor text
inside a quoted message can create one. Without such provenance `decisions` is
empty. The API must not offer a model-controlled `actor: user` or a default tool
that promotes arbitrary text into commitments. Commitment references remain
bound to the same participant, tangent and exact source positions.
The commitment record distinguishes accepted, rejected and withdrawn items;
only an active accepted commitment can enter `decisions`. Rejected exploration
stays in `rejected`, even when its transcript uses the word "decision".
Both Lab presentations MUST expose a functional **Commit this** control bound
to an authenticated user action and cited source positions. Reference/positive
controls must include an accepted explicit commitment that actually appears in
`decisions`; rejected-option/hostile controls must refuse promotion. Empty
decision arrays alone cannot satisfy the decision-integrity instrument.

Uncited ordinary claims are dropped with recorded reasons. Unknown/out-of-range
citations, forged decisions, unlisted fields or malformed schemas reject the
preview; no parent write occurs. Hostile text may remain quoted evidence in
`findings`/`verbatim`, but cannot change permissions, budgets, strategy identity
or decisions. No summary repairs an invalid record into apparent success.

`verbatim` imports bounded exact source excerpts with citations, not inferred
decisions; it refuses an oversized transcript rather than silently truncating.
`summary` produces schema-checked content through a dedicated harness session
with no action tools. `select` and `relevant` are reserved for later calibrated
trials and initially refuse as unsupported. They never quietly map to summary.
The parent sees the record as explicitly framed untrusted tangent data, never a
system message or an executable tool call. Framing alone is not an injection
defense: authorization and commitment provenance enforce the privileged boundary.

## Spend and events

Seed summarization, child turns, merge generation, builder and judge calls all
go through the harness. Model work requires a finite cost/output/time/step cap;
admission and worst-case cost reservation happen before each provider call,
using the shared root `RunContext`/ledger and inherited narrowed budgets. No
direct Prism call or uncapped compaction path is allowed. Failures and refusals
retain actual spend/reservations; retrying a preview never resets the ledger.
Across workers the budget/reservation state must be recovered durably under the
same root identity, or execution refuses before a call; creating a fresh ledger
for a resumed child is prohibited. Preview spend counts even if never committed.

`TangentOpened`, `TangentMerged`, `TangentAbandoned` extend the existing
`HarnessEvent` lineage contract: session/run/parent-run/root-run, tangent and
thread IDs, source positions and stable event ID. Merge adds strategy/version,
counts, actual token usage/cost when known, and outcome. Unknown usage stays
unknown with its reservation retained. Prompts, excerpts and tool results are
not event fields. The canonical merge and both presentations use the same data.

## Implementation and acceptance order

1. PHP persistence/API, shipped default `none`, authorization, view seeding,
   exact-once merge/abandon, schema/provenance validation, events and metered
   `verbatim`/`summary`. Extend existing thread/message/budget seams; no memory
   dependency or Prism-core change. Replay/stream/structured paths agree.
2. Local Lab only: functional collapsible card **and** plain merge message,
   versioned six-scenario corpus (research, rejected exploration, stale,
   duplicate, hostile including padded/homoglyph variants, nested), reference
   merges and single-defect specimens. No presentation winner chosen early.
3. Judge proof: each reference clears the owner-set bar three times within a
   frozen spread, every defect meets its oracle, and each defect changes actual
   judge-visible input. Freeze model, corpus hash, budgets and strategy version;
   retain failures additively. Deterministic recall/noise/integrity/conflict
   checks gate readability scores. No RSI until calibration passes.
4. Capped, reversible strategy-version trials of `select`/`relevant`; no package
   edits from the loop. Reuse the shared calibrated-review package when it lands.
   Ports and cross-language merge-record corpus follow the settled reference.

Required adversarial checks include unknown/forged tools under `none`/`user`,
policy changes after open/preview, foreign/tampered handles and citations,
quoted hostile instructions/forged commitments, parent advancement during a
model call, concurrent merge/abandon and duplicate retry, retired parent,
tool-pair fork boundaries, nested provenance/depth, exact input/output bounds,
zero-call budget refusal and crash/resume without budget reset. Assert the final
model request contains no harness tangent capability material when disabled.
Run database concurrency checks on an engine with row locking; SQLite alone
cannot establish that guarantee. NEVER use the shared workspace Postgres:
prism-labs and prism-sandbox share it. Use a dedicated disposable database owned
by the test (a dedicated test service in Genie). Before any migration/refresh,
assert APP_ENV=testing, the explicit dedicated connection/server identity, and an
exact generated database name such as `prism_tangents_test_<fixture_nonce>`.
Refuse inherited service URLs, a missing nonce or a name outside that owned
allowlist before connecting/mutating. Cleanup targets only that verified owned
database. The Lab was wiped by an unforced test run on 2026-10-25; default
workspace service variables are never a safe test connection.
Lab calibration remains a separate acceptance gate from PHP unit or schema tests.
