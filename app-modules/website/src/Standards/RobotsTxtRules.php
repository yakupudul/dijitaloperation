<?php

namespace MoxDop\Website\Standards;

/**
 * robots.txt group selection and path matching per RFC 9309 (as Google and Bing apply it): the group whose
 * user-agent equals the crawler's product token wins, else "*"; within a group the longest matching rule wins
 * and Allow wins a tie; "*" and "$" are wildcards. Pure; no I/O.
 */
final class RobotsTxtRules
{
    /** @var array<string, list<array{allow: bool, path: string}>> agent (lower) => rules */
    private array $groups = [];

    public function __construct(string $body)
    {
        $agents = [];
        $inRules = false;
        foreach (preg_split('/\r\n|\r|\n/', mb_substr($body, 0, 65536)) ?: [] as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line) ?? '');
            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }
            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = mb_strtolower($field);
            if ($field === 'user-agent') {
                if ($inRules) {
                    $agents = [];
                    $inRules = false;
                }
                $agent = mb_strtolower($value);
                $agents[] = $agent;
                $this->groups[$agent] ??= [];

                continue;
            }
            if (! in_array($field, ['allow', 'disallow'], true)) {
                continue;
            }
            $inRules = true;
            if ($value === '') {
                continue; // "Disallow:" with no path allows everything.
            }
            foreach ($agents as $agent) {
                $this->groups[$agent][] = ['allow' => $field === 'allow', 'path' => $value];
            }
        }
    }

    public function allowed(string $agent, string $path): bool
    {
        $rules = $this->groups[mb_strtolower($agent)] ?? $this->groups['*'] ?? [];
        $best = null;
        foreach ($rules as $rule) {
            if (! $this->matches($rule['path'], $path)) {
                continue;
            }
            $length = strlen($rule['path']);
            if ($best === null || $length > $best[0] || ($length === $best[0] && $rule['allow'])) {
                $best = [$length, $rule['allow']];
            }
        }

        return $best === null || $best[1];
    }

    /** True when the agent has its own group (not only "*"). */
    public function hasGroup(string $agent): bool
    {
        return isset($this->groups[mb_strtolower($agent)]);
    }

    private function matches(string $pattern, string $path): bool
    {
        $anchored = str_ends_with($pattern, '$');
        $regex = str_replace('\*', '.*', preg_quote(rtrim($pattern, '$'), '#'));

        return preg_match('#^'.$regex.($anchored ? '$' : '').'#', $path) === 1;
    }
}
