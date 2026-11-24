<?php

declare(strict_types=1);

namespace Prism\Harness\Plans;

/**
 * The rules for text a model writes into a plan (G-360).
 *
 * Every field is model output, and the model may have been steered by a
 * document it read. So a field is REFUSED, never truncated, when it is empty,
 * over its cap, not valid UTF-8, or contains a character from FORBIDDEN: the
 * C0 and C1 controls (line breaks included), the guillemets that fence model
 * text in the plan context, zero-width and bidi controls, the line and
 * paragraph separators, word joiners and the byte order mark. A field naming
 * one of the plan frame's own markers is refused as well, so a title cannot
 * close the frame and write text that reads as the host's.
 *
 * The same codepoint set is spelled out in all three languages. Lengths are
 * counted in Unicode code points in all three.
 */
final class PlanText
{
    public const GOAL = 200;

    public const TITLE = 160;

    public const INSTRUCTION = 600;

    public const QUESTION = 300;

    public const OPTION = 80;

    public const OPTIONS = 6;

    public const SUMMARY = 200;

    public const REASON = 200;

    public const ANSWER = 1000;

    /** Render caps inside the plan context. */
    public const RENDER_TITLE = 120;

    public const RENDER_INSTRUCTION = 400;

    public const RENDER_NOTE = 160;

    public const RENDER_ANSWER = 300;

    public const FORBIDDEN = '\x{0000}-\x{001F}\x{007F}-\x{009F}\x{00AB}\x{00BB}\x{200B}-\x{200F}\x{2028}-\x{202E}\x{2060}-\x{2069}\x{FEFF}\x{FFF9}-\x{FFFB}';

    /** Lower-case markers of the plan frame; a field containing one is refused. */
    public const MARKERS = ['[plan', '[end of plan'];

    /** Whether a model-written field is acceptable as it stands. */
    public static function acceptable(mixed $value, int $max): bool
    {
        if (! is_string($value) || $value === '' || ! mb_check_encoding($value, 'UTF-8')) {
            return false;
        }
        if (mb_strlen($value, 'UTF-8') > $max) {
            return false;
        }
        if (preg_match('/['.self::FORBIDDEN.']/u', $value) === 1) {
            return false;
        }
        $lower = mb_strtolower($value, 'UTF-8');
        foreach (self::MARKERS as $marker) {
            if (str_contains($lower, $marker)) {
                return false;
            }
        }

        return true;
    }

    /** Text cleaned for the plan context: forbidden characters removed, markers defused, cut to $max code points. */
    public static function render(string $value, int $max): string
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            return '';
        }
        $clean = (string) preg_replace('/['.self::FORBIDDEN.']/u', '', $value);
        $clean = (string) preg_replace('/\[(end of plan|plan)/iu', '($1', $clean);
        if (mb_strlen($clean, 'UTF-8') > $max) {
            $clean = mb_substr($clean, 0, $max - 1, 'UTF-8').'…';
        }

        return $clean;
    }

    /** Code points, the unit every cap here is counted in. */
    public static function length(string $value): int
    {
        return mb_strlen($value, 'UTF-8');
    }
}
