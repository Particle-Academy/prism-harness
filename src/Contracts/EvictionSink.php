<?php

declare(strict_types=1);

namespace Prism\Harness\Contracts;

use Prism\Harness\Context\DiscardEvicted;
use Prism\Prism\Contracts\Message;

/**
 * Where messages go when they leave the context window.
 *
 * THIS IS WHAT SEPARATES COMPACTION FROM LOSS. A window that shrinks and hands
 * the removed turns to nobody is cheaper and strictly worse: the agent can no
 * longer see what it did, and — measured, on a real audit workload — it repeats
 * work it cannot see, or answers from a gap. One consumer's agent asserted a
 * count from evidence that had already been cleared, was right by coincidence,
 * and caught itself. There is no error and no log line when that goes the other
 * way.
 *
 * That near-miss is the durable part of the finding. A stronger claim was made
 * from the same run — that clearing DATA made the agent abandon the task — and
 * it did not survive a re-run: with the agent's rules exempted it cleared six
 * times more data and finished. The agent's own explanation for stopping was
 * about the data and was wrong.
 *
 * So a strategy returns what it evicted, and the harness gives it to a sink. The
 * detail leaves the window and stays reachable.
 *
 * ## Deliberately an interface, and deliberately not required
 *
 * The harness does not depend on `prism-memory`. An application that has it
 * wires a sink that writes into a chat-scoped collection, and the evicted turns
 * become recallable by meaning. An application that wants a log file, an audit
 * table, or a queue writes twelve lines instead. An application that genuinely
 * does not care uses {@see DiscardEvicted} and has said
 * so out loud.
 *
 * That last one is the point of it existing rather than being the default: this
 * package will not decide that somebody's conversation is disposable on their
 * behalf, but it will let them decide it in one line.
 */
interface EvictionSink
{
    /**
     * Take custody of messages that have left the window.
     *
     * Called with the messages a strategy evicted, oldest first, for one
     * conversation identified by `$scope`.
     *
     * MUST NOT THROW on a failure it can survive. A sink that raises takes the
     * agent's turn down with it, which trades a degraded conversation for no
     * conversation — the wrong way round, since the model can still answer from
     * what remains in the window. Log and continue.
     *
     * @param  list<Message>  $messages
     * @param  string  $scope  The conversation these came from, for a sink that
     *                         keeps them apart. A thread key, not a user id.
     */
    public function store(array $messages, string $scope): void;
}
