<?php

declare(strict_types=1);

namespace Prism\Harness\Console;

use Illuminate\Console\Command;
use Prism\Harness\Plans\Plans;

/**
 * Restart stalled agent plans (G-360). Schedule it every few minutes when
 * plans are advanced by a queue:
 *
 *     Schedule::command('harness:plans:reap')->everyFiveMinutes();
 *
 * It hands each stalled plan to the callback the host registered with
 * `app(Plans::class)->reapUsing(fn (string $sessionKey, string $planId) => ...)`,
 * normally "dispatch the job that calls advance() for this session", and
 * writes the heartbeat `harness:doctor` checks.
 */
final class PlansReapCommand extends Command
{
    protected $signature = 'harness:plans:reap';

    protected $description = 'Hand stalled agent plans back to the host to advance.';

    public function handle(Plans $plans): int
    {
        if (! $plans->enabled()) {
            $this->components->info('Plans are not enabled.');

            return self::SUCCESS;
        }
        $dispatch = $plans->reapDispatcher();
        if ($dispatch === null) {
            $this->components->error('No reaper callback is registered. Call Plans::reapUsing() from a service provider.');

            return self::FAILURE;
        }
        $handed = $plans->sweep($dispatch);
        $this->components->info(sprintf('%d stalled plan(s) handed on.', $handed));

        return self::SUCCESS;
    }
}
