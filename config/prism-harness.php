<?php

declare(strict_types=1);

use Prism\Harness\Contracts\AgentTaskSource;

return [

    /*
    |--------------------------------------------------------------------------
    | Default thread scope
    |--------------------------------------------------------------------------
    |
    | A session and its thread are addressed by participant AND scope, so that
    | one participant can hold several unrelated conversations without them
    | merging. This is the scope used when a caller does not name one.
    |
    */

    'default_scope' => env('HARNESS_DEFAULT_SCOPE', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Ephemeral state lifetime
    |--------------------------------------------------------------------------
    |
    | How long the ephemeral half of a session lives without being touched.
    | Null keeps it until explicitly forgotten. Expiring it is safe by
    | definition: the ephemeral half is the state whose loss degrades to a
    | default rather than losing work.
    |
    */

    'ephemeral_ttl' => env('HARNESS_EPHEMERAL_TTL', 60 * 60 * 24),

    /*
    |--------------------------------------------------------------------------
    | State slots
    |--------------------------------------------------------------------------
    |
    | Session state is split in two, because the halves have genuinely
    | different requirements:
    |
    |   ephemeral — active mode, selected model, run bookkeeping. Losing it
    |               degrades to a default. Redis is the right home.
    |
    |   durable   — threads and pending tool approvals. Losing these is a
    |               correctness failure: a pending approval is a half-executed
    |               action waiting on a human, not a cached value.
    |
    | A driver that reports itself volatile is REFUSED for the durable slot,
    | loudly, at resolve time. That check exists because the opposite mistake —
    | quietly accepting a cache for state that must survive a deploy — is how
    | you lose work with nothing in the logs to show for it.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Context window
    |--------------------------------------------------------------------------
    |
    | How a conversation is shortened before it is replayed to the model.
    |
    | OFF by default: the harness replays the whole thread, which is what it has
    | always done and the only behaviour that cannot lose something the agent
    | needed. Setting `keep_recent` turns on the shipped strategy, which keeps
    | that many of the most recent MESSAGES and evicts the rest.
    |
    | Messages rather than turns, because a turn is not a countable unit once
    | tools are involved -- one exchange can be an assistant message plus six
    | tool results.
    |
    | COMPACTION SHORTENS THE VIEW, NOT THE STORAGE. Every row stays; what
    | changes is which of them the model sees. Change the setting and the next
    | turn sees a different window over the same unaltered history.
    |
    | WHATEVER YOU SET HERE, CHOOSE A SINK IN THE SAME BREATH. Evicted messages
    | go to the `EvictionSink` binding, which defaults to discarding them -- and
    | compaction without recovery is the configuration with the worst properties
    | available. The window gets cheap, the agent can no longer see what it did,
    | and nothing reports an error when it answers from the gap. Bind an
    | EvictionSink that writes into `prism-memory` (or a log, or a table) so the
    | detail leaves the window and stays reachable.
    |
    | Your own rule goes in a class implementing `CompactionStrategy` and bound
    | in a service provider. The harness enforces one invariant on every
    | strategy including yours: a tool call and its result are kept or dropped
    | together, because splitting them makes the provider reject the request.
    |
    */

    'context' => [
        'keep_recent' => env('HARNESS_KEEP_RECENT'),

        // The ceiling on what `recall_context` hands back, in estimated tokens.
        // Bounded because compaction is the reason recall exists: returning
        // everything relevant would re-expand the window that was just
        // compacted. The tool is only offered at all when a `ContextRecall` is
        // bound -- see the contract for why an unanswerable recall tool is
        // worse than none.
        'recall_budget' => (int) env('HARNESS_RECALL_BUDGET', 1000),

        /*
        | A SUMMARISING strategy, off unless you name a model.
        |
        | `SummarisingCompaction` replaces the older half of the conversation
        | with a summary a model writes, and REWRITES that summary each time
        | rather than appending -- an append-only precis grows without bound
        | while looking like it compacts.
        |
        | IT IS THE STRATEGY MOST LIKELY TO LOSE SOMETHING THAT MATTERS, and
        | that is measured: "Governance Decay" (arXiv 2606.22528) puts
        | summarisation-based compaction above 40% safety violations, against
        | 25-30% for truncation and 15-20% for semantic compression, because
        | constraints stated early are progressively lost with nothing
        | reporting it.
        |
        | So `keep_recent` above is the cheaper thing to try first: it costs
        | nothing, cannot rewrite anything, and is entirely predictable. Reach
        | for this when a bounded window genuinely is not enough, and BIND AN
        | EvictionSink alongside it -- a summary is a lossy view, and this only
        | becomes safe when something else still holds the original.
        |
        | It bills a model call on every turn that fires, and a SECOND one on
        | turns where the summary comes back longer than `summary_words`. Every
        | other strategy here is free.
        |
        | `summary_words` is enforced, not merely asked for. It used to reach
        | the model only as "in at most N words" inside the prompt with nothing
        | checking the answer, and measured live a stated 15 came back at 92 and
        | at 346, while the default 60 came back at 205. An over-budget summary
        | is now sent back once to be cut down; the retry does not loop and is
        | allowed to miss, and a failed or longer second answer leaves the first
        | standing.
        |
        */
        'summarise_with' => env('HARNESS_SUMMARISE_WITH'),
        'summary_words' => (int) env('HARNESS_SUMMARY_WORDS', 200),
    ],

    'stores' => [
        // Defaults to the database because that is what every Laravel app
        // already has. Redis is the better home for ephemeral state and is
        // fully supported — but defaulting to it means a fresh install throws
        // a connection error the first time a session writes anything, on a
        // machine that never claimed to have Redis. Opt in when you have one.
        'ephemeral' => env('HARNESS_EPHEMERAL_STORE', 'database'),
        'durable' => env('HARNESS_DURABLE_STORE', 'database'),
    ],

    'drivers' => [

        'redis' => [
            'driver' => 'redis',
            'connection' => env('HARNESS_REDIS_CONNECTION', 'default'),
            'prefix' => env('HARNESS_REDIS_PREFIX', 'harness:'),

            /*
             * Whether THIS Redis survives a deploy.
             *
             * Redis can absolutely be durable — with AOF or RDB it is — but the
             * `redis` connection in a typical Laravel app is a cache that
             * something is entitled to flush. The package cannot tell from the
             * inside, so this is an assertion about your infrastructure and it
             * is off by default. Turn it on only if you are certain, because
             * what it unlocks is storing pending tool approvals here.
             */
            'durable' => env('HARNESS_REDIS_DURABLE', false),
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('HARNESS_DB_CONNECTION'),
            'table' => 'harness_session_state',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Agent task lists
    |--------------------------------------------------------------------------
    |
    | A task list is DURABLE STATE and always lives in the durable slot above,
    | which is why there is no store setting here: a half-finished list that
    | vanishes on a deploy is indistinguishable from a finished one, and the
    | run that resolves the same session afterwards reports success having
    | dropped its remaining work.
    |
    | The lease is how a dead worker is recovered. Five minutes is long enough
    | for a model call plus tool work and short enough that a crashed worker
    | does not wedge the list for an hour; the exact number matters far less
    | than it being the same one in every language, so it is defined once on
    | the contract and read from there rather than written out again.
    |
    | There is deliberately NO timeout here for how long a worker may keep
    | renewing its lease. That bound is the run's own `RunBudget` — see
    | `StoreTaskSource::extendLease()`. A second limit for one idea is how a
    | limit ends up configured in the place that is not enforced.
    |
    */

    'tasks' => [
        /*
         * NOT cast to an int here, deliberately.
         *
         * `(int) '90.4'` is 90, and the cast would do that silently before
         * anything had a chance to object — so the guard against a fractional
         * lease would be defeated by the config file that declares it. The raw
         * value is passed through and refused where it is read, which is the
         * only place that can tell 300 from '300' from '90.4'.
         */
        'lease_seconds' => env('HARNESS_TASK_LEASE_SECONDS', AgentTaskSource::DEFAULT_LEASE_SECONDS),

        // Cast, because this one is tolerant by decision — see
        // PrismHarness::taskLockWait().
        'lock_wait' => (int) env('HARNESS_TASK_LOCK_WAIT', 5),
    ],

    'agent' => [
        'provider' => env('HARNESS_PROVIDER', 'anthropic'),
        'model' => env('HARNESS_MODEL', 'claude-sonnet-4-5'),
        'lock_ttl' => (int) env('HARNESS_RUN_LOCK_TTL', 300),
        'lock_wait' => (int) env('HARNESS_RUN_LOCK_WAIT', 0),
        'authorize_tools' => env('HARNESS_AUTHORIZE_TOOLS', false),

        /*
         * The hard ceiling on a per-session step override.
         *
         * A mode's `max_steps` is a default, and a caller that holds a frozen,
         * approved budget may ask for a different one via
         * `Session::usingMaxSteps()`. This is the operator's word about how far
         * that may go, and it outranks the caller — without it a caller could
         * lift its own limit, which is a limit in name only. Null removes the
         * ceiling entirely and should be a deliberate choice.
         */
        'max_steps_ceiling' => (int) env('HARNESS_MAX_STEPS_CEILING', 40),

        'default' => 'chat',
        'modes' => [
            'chat' => [
                'system_prompt' => 'You are a durable application agent. Use only the capabilities offered for this session.',
                'tools' => [],
                'skills' => [],
                'max_steps' => 8,
            ],
        ],
    ],

];
