<?php

declare(strict_types=1);

namespace Prism\Harness\Tools;

use Prism\Harness\AgentRuntime;
use Prism\Harness\Contracts\DeclaresToolScope;
use Prism\Harness\Exceptions\ToolPolicyRefused;
use Prism\Prism\Tool;

/**
 * Which approval-gated tools run without asking a person (G-326). Owned by the
 * HOST. The deployment's comes from `prism-harness.agent.tool_policy`; a host
 * sets one for the current request or queued job, and may replace it live,
 * with {@see AgentRuntime::setToolPolicy()}. That one ends with the request or
 * job: it never applies to a session a later request or job serves.
 *
 * One JSON shape in every language, so a host builds it once:
 * `{ "revision": "r7", "allow": [{ "server": "files", "tool": "read" }, { "tool": "search" }], "honor": true }`.
 *
 * MATCHING IS EXACT. A name must already be in normal form and well formed, so
 * a padded or look-alike name never matches. An entry without `server` matches
 * only a native tool, never one an MCP server provides. No wildcards, patterns
 * or prefixes.
 *
 * REFUSED AT CONSTRUCTION, never silently dropped, so the host learns of it: a
 * malformed entry, a confirmation or approval tool, and an entry naming a tool
 * that declares `tier: destructive` among the tools passed in.
 *
 * `honor: false` turns the list off. The policy has no tool surface and is
 * never sent to the model.
 */
final class ToolPolicy
{
    public const TIERS = ['read_only', 'write', 'destructive'];

    /**
     * Characters that are invisible at either end of a tool name: the same
     * codepoint set human-plus's TrustPolicy uses, spelled out identically in
     * all three harness languages rather than left to `trim()`, which strips
     * none of the Unicode ones.
     */
    private const INVISIBLE = '\x{0000}\x{0009}-\x{000D}\x{0020}\x{0085}\x{00A0}\x{1680}\x{2000}-\x{200D}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';

    /** Verbs whose tools belong to the person: confirmation and approval. */
    private const RESERVED_VERBS = ['confirm', 'reject', 'accept', 'approve', 'deny'];

    /** The agent plan tools (G-360): a plan is a person's agreement, never auto-allowed. */
    public const RESERVED_TOOLS = ['plan_draft', 'plan_propose_change', 'plan_pause_for_person', 'plan_complete_step'];

    /** @param list<array{server?: string, tool: string}> $allow */
    private function __construct(
        public readonly string $revision,
        private readonly array $allow,
        public readonly bool $honor,
    ) {}

    /** Nothing runs without asking. */
    public static function none(): self
    {
        return new self('', [], true);
    }

    /**
     * @param  iterable<Tool|array{name: string, server?: string|null, tier?: string|null}>  $tools  declarations the host knows about
     *
     * @throws ToolPolicyRefused
     */
    public static function fromArray(mixed $data, iterable $tools = []): self
    {
        if (! is_array($data) || ($data !== [] && array_is_list($data))) {
            throw ToolPolicyRefused::because('it must be an object.');
        }
        foreach (array_keys($data) as $key) {
            if (! in_array($key, ['revision', 'allow', 'honor'], true)) {
                throw ToolPolicyRefused::because("unknown key \"{$key}\".");
            }
        }
        if (! is_string($data['revision'] ?? null)) {
            throw ToolPolicyRefused::because('revision must be a string.');
        }
        if (! is_bool($data['honor'] ?? null)) {
            throw ToolPolicyRefused::because('honor must be a boolean.');
        }
        if (! is_array($data['allow'] ?? null) || ! array_is_list($data['allow'])) {
            throw ToolPolicyRefused::because('allow must be a list.');
        }

        $declared = [];
        foreach ($tools as $tool) {
            $declared[] = self::declarationOf($tool);
        }

        $allow = [];
        foreach ($data['allow'] as $entry) {
            if (! is_array($entry) || ($entry !== [] && array_is_list($entry))) {
                throw ToolPolicyRefused::because('every allow entry must be an object.');
            }
            foreach (array_keys($entry) as $key) {
                if ($key !== 'server' && $key !== 'tool') {
                    throw ToolPolicyRefused::because("unknown entry key \"{$key}\".");
                }
            }
            $tool = $entry['tool'] ?? null;
            if (! self::isExactName($tool)) {
                throw ToolPolicyRefused::because('an entry names a tool that is not an exact, well-formed name.');
            }
            if (array_key_exists('server', $entry) && ! self::isExactName($entry['server'])) {
                throw ToolPolicyRefused::because('an entry names a server that is not an exact, well-formed name.');
            }
            /** @var string $tool */
            $server = isset($entry['server']) && is_string($entry['server']) ? $entry['server'] : null;
            if (self::isPersonOnly($tool)) {
                throw ToolPolicyRefused::because("\"{$tool}\" is a confirmation or approval tool, which only a person may run.");
            }
            foreach ($declared as $candidate) {
                if ($candidate['tier'] === 'destructive' && $candidate['name'] === $tool && $candidate['server'] === $server) {
                    throw ToolPolicyRefused::because("\"{$tool}\" declares tier destructive, which is never auto-allowed.");
                }
            }
            $allow[] = $server === null ? ['tool' => $tool] : ['server' => $server, 'tool' => $tool];
        }

        return new self($data['revision'], $allow, $data['honor']);
    }

    /** @return list<array{server?: string, tool: string}> */
    public function allow(): array
    {
        return $this->allow;
    }

    /**
     * Does this call run without asking?
     *
     * False when the policy is not honoured, when the tool is malformed or not
     * in normal form, reserved for the person, or declares `destructive`, and
     * when no entry names exactly this server and tool.
     *
     * Pass {@see ToolRegistry::declarationOf()} for a resolved tool: a Tool on
     * its own only carries what it claims about its server.
     *
     * @param  Tool|array{name?: mixed, server?: mixed, tier?: mixed}  $tool
     */
    public function allows(Tool|array $tool): bool
    {
        $declared = self::declarationOf($tool);
        if (! $this->honor || ! self::isExactName($declared['name']) || self::isPersonOnly($declared['name']) || $declared['tier'] === 'destructive') {
            return false;
        }
        if ($declared['server'] !== null && ! self::isExactName($declared['server'])) {
            return false;
        }
        foreach ($this->allow as $entry) {
            if ($entry['tool'] === $declared['name'] && ($entry['server'] ?? null) === $declared['server']) {
                return true;
            }
        }

        return false;
    }

    /** @return array{revision: string, allow: list<array{server?: string, tool: string}>, honor: bool} */
    public function toArray(): array
    {
        return ['revision' => $this->revision, 'allow' => $this->allow, 'honor' => $this->honor];
    }

    /**
     * What a tool declares: its name, the MCP server it comes from, and its tier.
     *
     * @param  Tool|array{name?: mixed, server?: mixed, tier?: mixed}  $tool
     * @return array{name: string, server: ?string, tier: ?string}
     */
    public static function declarationOf(Tool|array $tool): array
    {
        if ($tool instanceof Tool) {
            return [
                'name' => $tool->name(),
                'server' => $tool instanceof DeclaresToolScope ? $tool->toolServer() : null,
                'tier' => $tool instanceof DeclaresToolScope ? $tool->toolTier() : null,
            ];
        }

        return [
            'name' => is_string($tool['name'] ?? null) ? $tool['name'] : '',
            'server' => is_string($tool['server'] ?? null) ? $tool['server'] : null,
            'tier' => is_string($tool['tier'] ?? null) ? $tool['tier'] : null,
        ];
    }

    /** A name with the invisible edges removed; null when it is not valid UTF-8. */
    public static function normalise(string $name): ?string
    {
        $normalised = preg_replace('/^['.self::INVISIBLE.']+|['.self::INVISIBLE.']+$/u', '', $name);

        return is_string($normalised) ? $normalised : null;
    }

    /** ASCII letters, digits, `_ . : -`; at most 128; ASCII-only defeats homoglyphs. */
    public static function isWellFormed(string $name): bool
    {
        return preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.:-]{0,127}\z/', $name) === 1;
    }

    /**
     * A confirmation or approval tool: any token is a reserved verb, or the
     * name is a plan tool (G-360). Invalid UTF-8 counts as reserved.
     *
     * The plan tools are named here because the verb tokeniser does not catch
     * them: a plan_draft or a plan change that a policy let run without asking
     * would be a plan no person approved.
     */
    public static function isPersonOnly(string $name): bool
    {
        $normalised = self::normalise($name);
        if ($normalised === null) {
            return true;
        }
        if (in_array(strtolower($normalised), self::RESERVED_TOOLS, true)) {
            return true;
        }
        foreach (preg_split('/[_.:-]+/', $normalised) ?: [] as $segment) {
            foreach (preg_split('/(?<=[a-z0-9])(?=[A-Z])/', $segment) ?: [] as $token) {
                if (in_array(strtolower($token), self::RESERVED_VERBS, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function isExactName(mixed $name): bool
    {
        return is_string($name) && self::normalise($name) === $name && self::isWellFormed($name);
    }
}
