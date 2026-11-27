<?php

declare(strict_types=1);

namespace Prism\Harness\Support;

use Closure;
use Prism\Harness\Exceptions\RunNotPermitted;
use Prism\Harness\Subagents\RunContext;
use WeakMap;

/**
 * What the runtime asks of a run's model loop and of its ungated tools
 * (G-387 round 16): the two points core gives no hook for.
 *
 *  - Before each model step: a run takes no further model step once a newer
 *    step on its session, written by anyone else, waits on approval (a tool's
 *    own nested send, or the host, parked the session after the run read
 *    it). Its answer would replace the step a caller was just told is
 *    waiting. It ends `parked_elsewhere` ({@see RunNotPermitted::parkedElsewhere()}).
 *  - Before each UNGATED call core runs inside a model step: the write-ahead
 *    journal row ({@see StepRows::JOURNAL}), because that step is not stored
 *    yet. An approved call has its own started row, written by the resolver.
 *
 * Attached by the runtime to the RunContext it hands its loop, detached when
 * the run ends; a loop or a tool of a run nothing attached to does nothing
 * here.
 *
 * @internal
 */
final class RunHooks
{
    /** @var WeakMap<RunContext, string>|null */
    private static ?WeakMap $runs = null;

    /** @var array<string, array{journal: Closure(string, array<array-key, mixed>): void, halted: Closure(): bool, generating: int}> */
    private static array $hooks = [];

    /**
     * @param  Closure(string, array<array-key, mixed>): void  $journal
     * @param  Closure(): bool  $halted
     * @return Closure(): void detaches
     */
    public static function attach(RunContext $run, string $runId, Closure $journal, Closure $halted): Closure
    {
        self::$runs ??= new WeakMap;
        self::$runs[$run] = $runId;
        self::$hooks[$runId] = ['journal' => $journal, 'halted' => $halted, 'generating' => 0];

        return static function () use ($runId): void {
            unset(self::$hooks[$runId]);
        };
    }

    /**
     * Before a model step of `$run`: refused when a newer step on its
     * session waits on approval.
     *
     * @throws RunNotPermitted parked_elsewhere
     */
    public static function beforeModelStep(RunContext $run): void
    {
        $runId = self::$runs[$run] ?? null;
        $hooks = $runId === null ? null : (self::$hooks[$runId] ?? null);
        if ($hooks !== null && ($hooks['halted'])()) {
            throw RunNotPermitted::parkedElsewhere();
        }
    }

    /**
     * Mark `$run`'s model call as in progress: core runs its ungated calls
     * inside it. The closure ends it.
     *
     * @return Closure(): void
     */
    public static function generating(RunContext $run): Closure
    {
        $runId = self::$runs[$run] ?? null;
        if ($runId === null || ! isset(self::$hooks[$runId])) {
            return static function (): void {};
        }
        self::$hooks[$runId]['generating']++;
        $done = false;

        return static function () use ($runId, &$done): void {
            if (! $done && isset(self::$hooks[$runId])) {
                self::$hooks[$runId]['generating']--;
            }
            $done = true;
        };
    }

    /**
     * Before an ungated call of run `$runId` runs inside a model step: its
     * journal row.
     *
     * @param  array<array-key, mixed>  $arguments
     */
    public static function beforeUngatedCall(?string $runId, string $toolName, array $arguments): void
    {
        $hooks = $runId === null ? null : (self::$hooks[$runId] ?? null);
        if ($hooks !== null && $hooks['generating'] > 0) {
            ($hooks['journal'])($toolName, $arguments);
        }
    }
}
