<?php

declare(strict_types=1);

namespace Prism\Harness\Skills;

use InvalidArgumentException;
use Prism\Prism\Tool;

final readonly class SkillRegistry
{
    public function __construct(private string $root) {}

    /** @param list<string> $names */
    public function augmentPrompt(string $systemPrompt, array $names): string
    {
        $sections = [];
        foreach ($names as $name) {
            $sections[] = sprintf("<skill name=\"%s\">\n%s\n</skill>", $name, $this->read($name, 'SKILL.md'));
        }

        if ($sections === []) {
            return $systemPrompt;
        }

        return trim($systemPrompt."\n\nThe following Harness-owned skills are available. Follow their routing instructions and use skill_read for referenced files. Do not copy skill files into the project workspace.\n\n".implode("\n\n", $sections));
    }

    public function read(string $name, string $path): string
    {
        if (preg_match('/^[a-z0-9][a-z0-9_-]*$/', $name) !== 1) {
            throw new InvalidArgumentException('Skill name is invalid.');
        }

        $relative = str_replace('\\', '/', trim($path));
        if ($relative === '' || str_starts_with($relative, '/') || preg_match('#(^|/)\.\.(/|$)#', $relative) === 1) {
            throw new InvalidArgumentException('Skill path must stay inside the registered skill.');
        }

        $skillRoot = realpath($this->root.DIRECTORY_SEPARATOR.$name);
        $file = realpath($this->root.DIRECTORY_SEPARATOR.$name.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative));
        if ($skillRoot === false || $file === false || ! is_file($file) || ! str_starts_with($file, $skillRoot.DIRECTORY_SEPARATOR)) {
            throw new InvalidArgumentException(sprintf('Skill resource [%s/%s] was not found.', $name, $relative));
        }

        $contents = file_get_contents($file);
        if ($contents === false) {
            throw new \RuntimeException(sprintf('Skill resource [%s/%s] could not be read.', $name, $relative));
        }

        return $contents;
    }

    public function readerTool(): Tool
    {
        return (new Tool)->as('skill_read')->for('Read a referenced file from a Harness-owned skill. Skill resources are capabilities, not project files.')
            ->withStringParameter('skill', 'Registered skill name.')
            ->withStringParameter('path', 'Path relative to that skill root, such as remotion-create/REFERENCE.md.')
            ->using(fn (string $skill, string $path): string => $this->read($skill, $path));
    }
}
