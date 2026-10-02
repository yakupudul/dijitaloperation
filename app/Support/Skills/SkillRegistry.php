<?php

namespace App\Support\Skills;

use InvalidArgumentException;
use RuntimeException;

/**
 * Built-in Skill registry. Modules register filesystem roots; loader parses Markdown.
 *
 * Skill slugs may repeat across modules; use getForModule() for module-scoped resolution.
 */
final class SkillRegistry
{
    /**
     * @var list<array{module: string, absolute_root: string}>
     */
    private array $roots = [];

    /**
     * @var list<SkillDefinition>|null
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
        $matches = $this->matchesForSlug($slug);
        if ($matches === []) {
            throw new InvalidArgumentException("Unknown Skill [{$slug}].");
        }

        if (count($matches) > 1) {
            return $matches[0];
        }

        return $matches[0];
    }

    public function getForModule(string $module, string $slug): SkillDefinition
    {
        foreach ($this->all() as $skill) {
            if ($skill->module === $module && $skill->slug === $slug) {
                return $skill;
            }
        }

        throw new InvalidArgumentException("Unknown Skill [{$slug}] for module [{$module}].");
    }

    public function has(string $slug): bool
    {
        return $this->matchesForSlug($slug) !== [];
    }

    public function hasForModule(string $module, string $slug): bool
    {
        foreach ($this->all() as $skill) {
            if ($skill->module === $module && $skill->slug === $slug) {
                return true;
            }
        }

        return false;
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
        if ($this->skills !== null) {
            return $this->skills;
        }

        $skills = [];

        foreach ($this->roots as $root) {
            $seenInModule = [];
            foreach ($this->loader->loadFromRoot($root) as $skill) {
                if (isset($seenInModule[$skill->slug])) {
                    throw new RuntimeException("Duplicate Skill slug [{$skill->slug}] in module [{$skill->module}].");
                }
                $seenInModule[$skill->slug] = true;
                $skills[] = $skill;
            }
        }

        usort(
            $skills,
            fn (SkillDefinition $a, SkillDefinition $b): int => [$a->module, $a->name] <=> [$b->module, $b->name]
        );

        return $this->skills = $skills;
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
        $this->all();
    }

    /**
     * @return list<SkillDefinition>
     */
    private function matchesForSlug(string $slug): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (SkillDefinition $skill): bool => $skill->slug === $slug
        ));
    }
}
