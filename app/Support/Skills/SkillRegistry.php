<?php

namespace App\Support\Skills;

use InvalidArgumentException;
use RuntimeException;

/**
 * Built-in Skill registry. Modules register filesystem roots; loader parses Markdown.
 */
final class SkillRegistry
{
    /**
     * @var list<array{module: string, absolute_root: string}>
     */
    private array $roots = [];

    /**
     * @var array<string, SkillDefinition>|null
     */
    private ?array $skills = null;

    public function __construct(
        private readonly BuiltInSkillLoader $loader,
    ) {}

    public function registerRoot(string $module, string $absoluteRoot): void
    {
        $real = realpath($absoluteRoot);
        if ($real === false || ! is_dir($real)) {
            throw new InvalidArgumentException("Cannot register Skill root [{$absoluteRoot}].");
        }

        foreach ($this->roots as $existing) {
            if ($existing['absolute_root'] === $real) {
                return;
            }
        }

        $this->roots[] = [
            'module' => $module,
            'absolute_root' => $real,
        ];
        $this->skills = null;
    }

    /**
     * @return list<array{module: string, absolute_root: string}>
     */
    public function roots(): array
    {
        return $this->roots;
    }

    public function get(string $slug): SkillDefinition
    {
        $skills = $this->allBySlug();
        if (! isset($skills[$slug])) {
            throw new InvalidArgumentException("Unknown Skill [{$slug}].");
        }

        return $skills[$slug];
    }

    public function has(string $slug): bool
    {
        return isset($this->allBySlug()[$slug]);
    }

    /**
     * @param  list<string>  $slugs
     * @return list<SkillDefinition>
     */
    public function many(array $slugs): array
    {
        $skills = [];
        foreach ($slugs as $slug) {
            $skills[] = $this->get($slug);
        }

        return $skills;
    }

    /**
     * @return list<SkillDefinition>
     */
    public function all(): array
    {
        $skills = array_values($this->allBySlug());
        usort(
            $skills,
            fn (SkillDefinition $a, SkillDefinition $b): int => [$a->module, $a->name] <=> [$b->module, $b->name]
        );

        return $skills;
    }

    /**
     * @return list<SkillDefinition>
     */
    public function forModule(string $module): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (SkillDefinition $skill): bool => $skill->module === $module
        ));
    }

    /**
     * @return list<string>
     */
    public function signaturesFor(array $slugs): array
    {
        $signatures = [];
        foreach ($slugs as $slug) {
            $signatures[] = $this->get($slug)->signature();
        }

        return $signatures;
    }

    public function reload(): void
    {
        $this->skills = null;
        $this->allBySlug();
    }

    /**
     * @return array<string, SkillDefinition>
     */
    private function allBySlug(): array
    {
        if ($this->skills !== null) {
            return $this->skills;
        }

        $bySlug = [];

        foreach ($this->roots as $root) {
            foreach ($this->loader->loadFromRoot($root) as $skill) {
                if (isset($bySlug[$skill->slug])) {
                    throw new RuntimeException("Duplicate Skill slug [{$skill->slug}].");
                }
                $bySlug[$skill->slug] = $skill;
            }
        }

        return $this->skills = $bySlug;
    }
}
