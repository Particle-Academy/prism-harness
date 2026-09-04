<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Prism\Harness\Contracts\SessionStore;
use Prism\Harness\Enums\Durability;
use Prism\Harness\Sessions\Session;

/**
 * The cross-language session-key corpus from `prism-parity`.
 *
 * This package is the REFERENCE, so this file proves the corpus has not drifted
 * from the code it was generated against. Without it, both ports would be
 * asserting against a snapshot of a format this package had since changed, and
 * every one of those tests would stay green while the claim quietly stopped
 * being true — in two other repositories, on somebody else's next run.
 *
 * The key is the address a PHP application and an agent use to resolve the same
 * conversation. Drift does not error; it produces a conversation that appears
 * empty.
 */
final class CorpusParticipant extends Model
{
    // Set after construction, not through the constructor: Eloquent's boot
    // sequence instantiates a model with no arguments.
    public string $morphClass = '';

    public string $corpusKey = '';

    public static function for(string $type, string $id): self
    {
        $participant = new self;
        $participant->morphClass = $type;
        $participant->corpusKey = $id;

        return $participant;
    }

    #[Override]
    public function getMorphClass(): string
    {
        return $this->morphClass;
    }

    #[Override]
    public function getKey(): string
    {
        return $this->corpusKey;
    }
}

/** Never read from. `key()` derives from the participant and scope alone. */
final class UnusedStore implements SessionStore
{
    public function get(string $key): ?array
    {
        return null;
    }

    public function put(string $key, array $payload, ?int $ttlSeconds = null): void {}

    public function forget(string $key): void {}

    public function withLock(string $key, Closure $callback, int $ttlSeconds = 10, int $waitSeconds = 5): mixed
    {
        return $callback();
    }

    public function durability(): Durability
    {
        return Durability::Volatile;
    }
}

function sessionKeyCorpus(): array
{
    return json_decode(
        // `Fixtures`, capital F, because that is where the file is TRACKED.
        // Windows resolved the lowercase spelling to the same directory, so this
        // passed on every developer machine and failed on Linux CI from the day
        // it was written -- main was red for three days and nothing said so.
        (string) file_get_contents(__DIR__.'/../Fixtures/harness-session-key.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    )['cases'];
}

function keyFor(array $case): string
{
    return (new Session(
        CorpusParticipant::for($case['participant']['type'], $case['participant']['id']),
        $case['scope'],
        new UnusedStore,
        new UnusedStore,
    ))->key();
}

it('is the whole suite, not a subset someone trimmed to green', function (): void {
    expect(sessionKeyCorpus())->toHaveCount(9);
});

it('still produces the address the corpus recorded', function (): void {
    foreach (sessionKeyCorpus() as $case) {
        expect(keyFor($case))->toBe($case['key']['php'], $case['id'].' — '.$case['title']);
    }
});

it('agrees with both ports on every row', function (): void {
    foreach (sessionKeyCorpus() as $case) {
        expect($case['key']['ts'])->toBe($case['key']['php'], $case['id']);
        expect($case['key']['py'])->toBe($case['key']['php'], $case['id']);
    }
});

it('keeps two participant TYPES with the same id apart', function (): void {
    // User 7 and Team 7 must not share a conversation. Asserted directly rather
    // than inferred from two rows happening to differ.
    $cases = collect(sessionKeyCorpus())->keyBy('id');

    expect($cases['key-0001']['participant']['id'])->toBe($cases['key-0004']['participant']['id'])
        ->and(keyFor($cases['key-0001']))->not->toBe(keyFor($cases['key-0004']));
});
