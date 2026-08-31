<?php

declare(strict_types=1);

namespace Prism\Harness\Console;

use Illuminate\Console\Command;
use Prism\Harness\Modes\ModeRegistry;
use Prism\Harness\Sessions\SessionStoreManager;
use Prism\Harness\Tools\ToolAuthorizer;
use Prism\Harness\Tools\ToolRegistry;
use Throwable;

/**
 * Check the harness configuration before a run does it for you.
 *
 * Every check here corresponds to a failure this package already refuses at
 * runtime. The refusals are correct and they are also LATE: a mode nobody has
 * entered yet keeps its broken subagent reference until someone switches to
 * it, and the first person to find out is a user mid-conversation.
 *
 * So this resolves EVERY mode rather than the default one, and reports what a
 * run would have thrown.
 */
final class HarnessDoctorCommand extends Command
{
    protected $signature = 'harness:doctor';

    protected $description = 'Check the Prism Harness configuration for problems a run would only find later.';

    public function handle(ModeRegistry $modes, ToolRegistry $tools, SessionStoreManager $stores): int
    {
        $problems = 0;

        $problems += $this->checkStores($stores);
        $problems += $this->checkAuthorizer();
        $problems += $this->checkModes($modes, $tools);

        $this->newLine();

        if ($problems > 0) {
            $this->components->error(sprintf('%d problem(s) found.', $problems));

            return self::FAILURE;
        }

        $this->components->info('Harness configuration is consistent.');

        return self::SUCCESS;
    }

    private function checkStores(SessionStoreManager $stores): int
    {
        try {
            $durable = $stores->durable();
            $stores->ephemeral();
        } catch (Throwable $e) {
            $this->components->twoColumnDetail('<fg=red>stores</>', $e->getMessage());

            return 1;
        }

        $this->components->twoColumnDetail('stores', sprintf('durable is %s', $durable->durability()->value));

        return 0;
    }

    private function checkAuthorizer(): int
    {
        try {
            // Resolving it is the check: the constructor refuses a policy that
            // is defined while the authorizer is off.
            $enabled = app(ToolAuthorizer::class)->enabled();
        } catch (Throwable $e) {
            $this->components->twoColumnDetail('<fg=red>authorization</>', $e->getMessage());

            return 1;
        }

        $this->components->twoColumnDetail(
            'authorization',
            $enabled ? 'enabled' : '<fg=yellow>off — every registered tool is offered to every run</>',
        );

        return 0;
    }

    private function checkModes(ModeRegistry $modes, ToolRegistry $tools): int
    {
        $problems = 0;

        foreach ($modes->names() as $name) {
            try {
                $mode = $modes->resolve($name);
            } catch (Throwable $e) {
                $this->components->twoColumnDetail("<fg=red>mode {$name}</>", $e->getMessage());
                $problems++;

                continue;
            }

            // `['*']` counted as "1 tool" is a true number and a false report:
            // it means every tool in the registry, and the whole point of
            // reading this output is to see how much authority a mode has.
            $detail = sprintf(
                '%s, %d subagent(s), %d step(s)%s',
                in_array('*', $mode->tools, true)
                    ? '<fg=yellow>all registered tools</>'
                    : sprintf('%d tool(s)', count($mode->tools)),
                count($mode->subagents),
                $mode->maxSteps,
                $mode->requiresApproval === [] ? '' : sprintf(', approval on [%s]', implode(', ', $mode->requiresApproval)),
            );

            // An approval gate naming a tool the mode never offers is a gate on
            // nothing — and it reads, to anyone auditing the config, as though
            // that tool were guarded.
            $unoffered = array_values(array_filter(
                $mode->requiresApproval,
                fn (string $tool): bool => $tool !== '*'
                    && ! in_array('*', $mode->tools, true)
                    && ! in_array($tool, $mode->tools, true),
            ));

            if ($unoffered !== []) {
                $this->components->twoColumnDetail(
                    "<fg=red>mode {$name}</>",
                    sprintf('requires_approval names [%s], which this mode does not offer', implode(', ', $unoffered)),
                );
                $problems++;

                continue;
            }

            $this->components->twoColumnDetail("mode {$name}", $detail);
        }

        return $problems;
    }
}
