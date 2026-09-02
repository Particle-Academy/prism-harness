<?php

declare(strict_types=1);

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
