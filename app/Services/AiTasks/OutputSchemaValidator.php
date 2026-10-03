<?php

namespace App\Services\AiTasks;

/**
 * Checks a submitted AI task output against the agent's JSON schema (the subset laravel/ai agents produce: object
 * with properties / required / additionalProperties, array items, string enum, integer, number, boolean, null,
 * nullable type lists, anyOf). Returns readable errors so Claude can correct and submit again.
 */
final class OutputSchemaValidator
{
    private const int MAX_ERRORS = 20;

    /**
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    public function errors(mixed $value, array $schema): array
    {
        $errors = [];
        $this->check($value, $schema, '$', $errors);

        return array_slice($errors, 0, self::MAX_ERRORS);
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $errors
     */
    private function check(mixed $value, array $schema, string $path, array &$errors): void
    {
        if (count($errors) >= self::MAX_ERRORS) {
            return;
        }
        if (isset($schema['anyOf']) && is_array($schema['anyOf'])) {
            foreach ($schema['anyOf'] as $branch) {
                $branchErrors = [];
                $this->check($value, (array) $branch, $path, $branchErrors);
                if ($branchErrors === []) {
                    return;
                }
            }
            $errors[] = $path.': hiçbir seçenekle eşleşmiyor';

            return;
        }
        $types = isset($schema['type']) ? (array) $schema['type'] : [];
        if ($types !== [] && ! $this->matchesAny($value, $types)) {
            $errors[] = $path.': '.implode('|', $types).' bekleniyor, '.get_debug_type($value).' geldi';

            return;
        }
        if (isset($schema['enum']) && is_array($schema['enum']) && $value !== null && ! in_array($value, $schema['enum'], true)) {
            $errors[] = $path.': izin verilen değerler '.implode(', ', array_map('strval', $schema['enum']));
        }
        if (is_array($value) && array_is_list($value) && isset($schema['items']) && is_array($schema['items'])) {
            foreach ($value as $index => $item) {
                $this->check($item, $schema['items'], $path.'['.$index.']', $errors);
            }
        }
        if (is_array($value) && ($value === [] || ! array_is_list($value)) && in_array('object', $types, true)) {
            $this->checkObject($value, $schema, $path, $errors);
        }
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $errors
     */
    private function checkObject(array $value, array $schema, string $path, array &$errors): void
    {
        $properties = (array) ($schema['properties'] ?? []);
        foreach ((array) ($schema['required'] ?? []) as $required) {
            if (! array_key_exists($required, $value)) {
                $errors[] = $path.'.'.$required.': zorunlu alan eksik';
            }
        }
        foreach ($value as $key => $item) {
            if (isset($properties[$key]) && is_array($properties[$key])) {
                $this->check($item, $properties[$key], $path.'.'.$key, $errors);
            } elseif (($schema['additionalProperties'] ?? true) === false) {
                $errors[] = $path.'.'.$key.': şemada olmayan alan';
            }
        }
    }

    /** @param  list<string>  $types */
    private function matchesAny(mixed $value, array $types): bool
    {
        foreach ($types as $type) {
            $matches = match ($type) {
                'object' => is_array($value) && ($value === [] || ! array_is_list($value)),
                'array' => is_array($value) && array_is_list($value),
                'string' => is_string($value),
                'integer' => is_int($value),
                'number' => is_int($value) || is_float($value),
                'boolean' => is_bool($value),
                'null' => $value === null,
                default => true,
            };
            if ($matches) {
                return true;
            }
        }

        return false;
    }
}
