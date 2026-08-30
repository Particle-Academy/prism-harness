<?php

declare(strict_types=1);

use Prism\Harness\Skills\SkillRegistry;

it('ships Remotion as a Harness-owned skill with readable references', function (): void {
    $skills = app(SkillRegistry::class);

    expect($skills->read('remotion', 'SKILL.md'))->toContain('name: remotion-best-practices')
        ->and($skills->read('remotion', 'remotion-create/REFERENCE.md'))->toContain('Remotion');
});

it('refuses skill path traversal', function (): void {
    expect(fn (): string => app(SkillRegistry::class)->read('remotion', '../composer.json'))
        ->toThrow(InvalidArgumentException::class);
});
