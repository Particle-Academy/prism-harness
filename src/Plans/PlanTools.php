<?php

declare(strict_types=1);

namespace Prism\Harness\Plans;

use Prism\Harness\Exceptions\PlanRefused;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Tools\ToolAuthorizer;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\EnumSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Tool;

/**
 * The agent's plan tools, built per run for one session (G-360).
 *
 * NO TOOL TAKES A STEP ID OR AN APPROVAL ID. Each acts on the plan's head
 * step, which the driver chose, so a model that cannot name a step cannot act
 * on someone else's, nor probe which ids exist. plan_propose_change is the one
 * exception, and only to keep existing steps unchanged by id.
 *
 * WHAT THE PERSON APPROVED IS WHAT IS APPLIED. plan_draft, plan_propose_change
 * and plan_pause_for_person (kind approve) are core approvals. The proposal is
 * recorded when core asks whether the call needs approval; it is applied only
 * when core runs the call a person approved, and only when that call's
 * arguments are the ones proposed. A call that reaches its handler without a
 * recorded approval for exactly these arguments applies nothing.
 *
 * Every refusal handed back to the model is a constant sentence and a code.
 */
final class PlanTools
{
    /** @var array<string, string> refusal codes found while deciding approval, by tool and digest */
    private array $refusals = [];

    public function __construct(private readonly Plans $plans, private readonly Session $session) {}

    /** @return array<string, Tool> */
    public function all(): array
    {
        return [
            PlanMachine::DRAFT => $this->draft(),
            PlanMachine::COMPLETE => $this->complete(),
            PlanMachine::PAUSE => $this->pause(),
            PlanMachine::CHANGE => $this->change(),
        ];
    }

    private function draft(): Tool
    {
        $tool = null;

        return $tool = (new Tool)
            ->as(PlanMachine::DRAFT)
            ->for('Propose a plan: a goal and an ordered list of steps. A person approves it before any step starts. Steps are your own work (owner agent, with an instruction) or a question for the person (owner human, interaction input, with a question).')
            ->withStringParameter('goal', 'What the plan is for, at most 200 characters, one line.')
            ->withParameter(new ArraySchema('steps', 'The steps, in order.', Plans::stepSchema(false)))
            ->requiresApproval(function (array $arguments) use (&$tool): bool {
                $limits = $this->plans->limits();
                if (! $limits->requireApproval) {
                    return false;
                }
                $digest = Plans::digest($arguments);
                try {
                    $plan = $this->proposal($arguments, $digest, $this->allowed($tool, $arguments));
                    $this->plans->write($this->session, $plan, $this->plans->load($this->session)['revision'] ?? null);
                } catch (PlanRefused $refused) {
                    $this->refusals[PlanMachine::DRAFT.$digest] = $refused->code();

                    return false;
                }

                return true;
            })
            ->using(function (...$arguments): string {
                $digest = Plans::digest($arguments);
                try {
                    if ($this->approved(PlanMachine::DRAFT, $digest)) {
                        $plan = $this->plans->mutate($this->session, fn (array $plan): array => PlanMachine::activate($plan, $digest, $this->plans->now(), $this->plans->runId($this->session)));
                        if ($plan === null) {
                            return PlanRefusal::forModel('plan.not_waiting');
                        }

                        return 'The person approved the plan. It is active; the host will run its steps one at a time.';
                    }
                    if (isset($this->refusals[PlanMachine::DRAFT.$digest])) {
                        return PlanRefusal::forModel($this->refusals[PlanMachine::DRAFT.$digest]);
                    }
                    if ($this->plans->limits()->requireApproval) {
                        return PlanRefusal::forModel('plan.not_waiting');
                    }
                    // Approval switched off by the host's config: the plan starts as written.
                    $plan = $this->proposal($arguments, $digest);
                    $plan = PlanMachine::activate($plan, $digest, $this->plans->now(), $this->plans->runId($this->session));
                    $this->plans->write($this->session, $plan, $this->plans->load($this->session)['revision'] ?? null);

                    return 'The plan is active; the host will run its steps one at a time.';
                } catch (PlanRefused $refused) {
                    return PlanRefusal::forModel($refused->code());
                }
            });
    }

    /**
     * @param  array<array-key, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function proposal(array $arguments, string $digest, bool $authorized = true): array
    {
        $limits = $this->plans->limits();
        $draft = PlanMachine::checkDraft($arguments, $limits, $authorized);
        if (PlanMachine::isLive($this->plans->load($this->session))) {
            throw PlanRefused::because('plan.exists');
        }

        return PlanMachine::propose($draft, $digest, $this->plans->ids(), $this->plans->now(), $this->plans->runId($this->session), $limits);
    }

    private function complete(): Tool
    {
        return (new Tool)
            ->as(PlanMachine::COMPLETE)
            ->for('Report that the CURRENT plan step is finished. It does not start the next step; stop work after calling it.')
            ->withStringParameter('summary', 'What you did, at most 200 characters, one line.')
            ->using(function (...$arguments): string {
                if (array_keys($arguments) !== ['summary']) {
                    return PlanRefusal::forModel('plan.shape');
                }
                try {
                    $plan = $this->plans->mutate($this->session, fn (array $plan): array => PlanMachine::report($plan, $arguments['summary'], $this->plans->now(), $this->plans->runId($this->session)));
                    if ($plan === null) {
                        return PlanRefusal::forModel('plan.no_running_step');
                    }
                } catch (PlanRefused $refused) {
                    return PlanRefusal::forModel($refused->code());
                }

                return 'Noted. The step will be settled after this turn. Stop work on it now.';
            });
    }

    private function pause(): Tool
    {
        $tool = null;

        return $tool = (new Tool)
            ->as(PlanMachine::PAUSE)
            ->for('Stop for the person before continuing the CURRENT step. kind approve asks the person to approve going on; kind input asks a question. End your turn after calling it.')
            ->withParameter(new EnumSchema('kind', 'approve or input', ['approve', 'input']))
            ->withStringParameter('question', 'What to ask the person, at most 160 characters, one line.')
            ->withParameter(new ArraySchema('options', 'Suggested answers for kind input; free text is always allowed.', new StringSchema('option', 'An option'), nullable: true), false)
            ->requiresApproval(function (array $arguments) use (&$tool): bool {
                if (($arguments['kind'] ?? null) !== 'approve') {
                    return false;
                }
                $digest = Plans::digest($arguments);
                $code = $this->applyPause($arguments, $digest, $this->allowed($tool, $arguments));
                if ($code !== null) {
                    $this->refusals[PlanMachine::PAUSE.$digest] = $code;

                    return false;
                }

                return true;
            })
            ->using(function (...$arguments): string {
                $digest = Plans::digest($arguments);
                if (($arguments['kind'] ?? null) === 'approve') {
                    if ($this->approved(PlanMachine::PAUSE, $digest)) {
                        return 'The person approved. Continue the current step.';
                    }

                    return PlanRefusal::forModel($this->refusals[PlanMachine::PAUSE.$digest] ?? 'plan.no_running_step');
                }
                $code = $this->applyPause($arguments, null);

                return $code !== null
                    ? PlanRefusal::forModel($code)
                    : 'The question is with the person. End your turn now; the plan continues after they answer.';
            });
    }

    /** @param array<array-key, mixed> $arguments */
    private function applyPause(array $arguments, ?string $digest, bool $authorized = true): ?string
    {
        $code = null;
        try {
            $plan = $this->plans->mutate($this->session, function (array $plan) use ($arguments, $digest, $authorized, &$code): array {
                [$plan, $code] = PlanMachine::pause($plan, $arguments, $digest, $this->plans->ids(), $this->plans->limits(),
                    $this->plans->now(), $this->plans->runId($this->session), $authorized);

                return $plan;
            });
        } catch (PlanRefused $refused) {
            return $refused->code();
        }

        return $plan === null ? 'plan.no_running_step' : $code;
    }

    private function change(): Tool
    {
        $tool = null;

        return $tool = (new Tool)
            ->as(PlanMachine::CHANGE)
            ->for('Propose a new list for the plan\'s remaining steps. Keep every finished, running, held or person step unchanged by giving only its id. A person approves the change before it applies.')
            ->withStringParameter('reason', 'Why the plan should change, at most 200 characters, one line.')
            ->withNumberParameter('base_revision', 'The plan revision you are changing, from the plan context.')
            ->withParameter(new ArraySchema('steps', 'The new list of steps, in order.', Plans::stepSchema(true)))
            ->requiresApproval(function (array $arguments) use (&$tool): bool {
                $digest = Plans::digest($arguments);
                try {
                    $authorized = $this->allowed($tool, $arguments);
                    $plan = $this->plans->mutate($this->session, fn (array $plan): array => PlanMachine::proposeChange($plan, $arguments, $digest,
                        $this->plans->ids(), $this->plans->limits(), $this->plans->now(), $this->plans->runId($this->session), $authorized));
                    if ($plan === null) {
                        throw PlanRefused::because('plan.no_plan');
                    }
                } catch (PlanRefused $refused) {
                    $this->refusals[PlanMachine::CHANGE.$digest] = $refused->code();

                    return false;
                }

                return true;
            })
            ->using(function (...$arguments): string {
                $digest = Plans::digest($arguments);
                if (! $this->approved(PlanMachine::CHANGE, $digest)) {
                    return PlanRefusal::forModel($this->refusals[PlanMachine::CHANGE.$digest] ?? 'plan.not_waiting');
                }
                try {
                    $this->plans->mutate($this->session, fn (array $plan): array => PlanMachine::applyChange($plan, $digest, $this->plans->now(), $this->plans->runId($this->session)));
                } catch (PlanRefused $refused) {
                    return PlanRefusal::forModel($refused->code());
                }

                return 'The person approved the change. The plan now follows the new steps.';
            });
    }

    /**
     * Whether the host's call policy lets this call proceed, asked BEFORE a
     * proposal is recorded. Core asks whether a call needs approval before the
     * authorization guard around the handler runs, so a draft the host forbids
     * would otherwise be stored as proposed and hold the session's one plan
     * slot. Fails closed when the tool is not built yet, which cannot happen
     * at call time.
     *
     * @param  array<array-key, mixed>  $arguments
     */
    private function allowed(?Tool $tool, array $arguments): bool
    {
        if (! $tool instanceof Tool) {
            return false;
        }

        /** @var array<string, mixed> $arguments */
        return app(ToolAuthorizer::class)->allowsCall($this->session, $tool, $arguments);
    }

    /** A person approved exactly this call, and it has not run yet. Read from the thread. */
    private function approved(string $tool, string $digest): bool
    {
        return $this->session->thread()->approvedCallsAwaitingResult($tool, $digest) > 0;
    }
}
