<?php

namespace App\Services\DataPool\Support;

final class RecordFingerprint
{
    /**
     * @param  list<string>  $naturalKey
     * @param  array<string, mixed>  $record
     */
    public function for(string $datasetId, array $naturalKey, array $record): string
    {
        $parts = [$datasetId];
        foreach ($naturalKey as $column) {
            if (! array_key_exists($column, $record)) {
                throw new \InvalidArgumentException("Natural key field [{$column}] missing for fingerprint on [{$datasetId}]");
            }
            $parts[] = $column.'='.$this->normalize($record[$column]);
        }

        return hash('sha256', implode('|', $parts));
    }

    private function normalize(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }
}
