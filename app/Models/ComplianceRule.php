<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $pack_id
 * @property string $rule_key
 * @property string $kind
 * @property string $label
 * @property list<string> $patterns
 * @property string $message
 * @property string $severity
 * @property ?list<string> $applies_to
 * @property bool $active
 * @property string $origin
 */
class ComplianceRule extends Model
{
    protected $fillable = ['pack_id', 'rule_key', 'kind', 'label', 'patterns', 'message', 'severity', 'applies_to', 'active', 'origin'];

    protected function casts(): array
    {
        return ['patterns' => 'array', 'applies_to' => 'array', 'active' => 'boolean'];
    }

    public function appliesTo(string $source): bool
    {
        return $this->applies_to === null || $this->applies_to === [] || in_array($source, $this->applies_to, true);
    }
}
