<?php

namespace App\Services\Findings;

use App\Support\Findings\FindingRule;

final class FindingPresentationRenderer
{
    /**
     * @param  array<string, mixed>  $operands
     */
    public function title(FindingRule $rule, array $operands): string
    {
        return $this->render($rule->titleTemplate, $operands);
    }

    /**
     * @param  array<string, mixed>  $operands
     */
    public function summary(FindingRule $rule, array $operands): string
    {
        return $this->render($rule->summaryTemplate, $operands);
    }

    /**
     * @param  array<string, mixed>  $operands
     */
    private function render(string $template, array $operands): string
    {
        return (string) preg_replace_callback(
            '/\{([a-zA-Z0-9_.]+)\}/',
            function (array $match) use ($operands): string {
                $value = $operands[$match[1]] ?? null;
                if (is_bool($value)) {
                    return $value ? 'true' : 'false';
                }
                if (is_numeric($value)) {
                    $number = (float) $value;

                    return abs($number - round($number)) < 0.0001
                        ? (string) (int) round($number)
                        : rtrim(rtrim(number_format($number, 6, '.', ''), '0'), '.');
                }
                if ($value === null) {
                    return 'n/a';
                }

                return is_scalar($value) ? (string) $value : 'n/a';
            },
            $template,
        );
    }
}
