<?php

declare(strict_types=1);

namespace Prism\Harness\Sessions;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use LogicException;
use Prism\Harness\Participants\ParticipantRef;
use Prism\Harness\Support\LiveThreadKey;

/** Immutable storage identity. Display/persona names never enter its derivation. */
final readonly class SessionAddress
{
    public function __construct(
        public ?string $participantType,
        public ?string $participantId,
        public string $scope,
        public ?string $agent = null,
        public bool $reference = false,
    ) {
        self::validateAgent($agent);
        if (($participantType === null) !== ($participantId === null)) {
            throw new InvalidArgumentException('Participant type and id must both be present or both be absent.');
        }
        if ($agent !== null || $reference) {
            foreach ([$participantType, $participantId, $scope] as $value) {
                if ($value !== null && preg_match('//u', $value) !== 1) {
                    throw new InvalidArgumentException('Harness addresses require valid UTF-8.');
                }
            }
        }
    }

    public static function validateAgent(?string $agent): void
    {
        if ($agent !== null && preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/D', $agent) !== 1) {
            throw new InvalidArgumentException('Harness namespace must be a configured lowercase identifier of 1–64 ASCII bytes.');
        }
    }

    public static function forParticipant(Model|ParticipantRef|null $participant, string $scope, ?string $agent = null): self
    {
        return new self(
            $participant instanceof Model ? $participant->getMorphClass() : $participant?->type,
            $participant instanceof Model ? (string) $participant->getKey() : $participant?->id,
            $scope,
            $agent,
            $participant instanceof ParticipantRef,
        );
    }

    public function sessionKey(): string
    {
        if ($this->participantType === null || $this->participantId === null) {
            throw new LogicException('A session requires a participant.');
        }
        if ($this->agent === null) {
            return 'session:'.substr(sha1($this->participantType), 0, 12).':'.$this->participantId.':'.$this->scope;
        }

        return 'session:'.$this->digest('prism-harness.session.v1');
    }

    public function threadKey(): string
    {
        return $this->agent === null
            ? LiveThreadKey::key($this->participantType, $this->participantId, $this->scope)
            : $this->digest('prism-harness.thread.v1');
    }

    public function indexKey(): string
    {
        return $this->digest('prism-harness.address.v1');
    }

    private function digest(string $domain): string
    {
        return hash('sha256', json_encode([
            $domain, $this->agent, $this->participantType, $this->participantId, $this->scope,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS));
    }
}
