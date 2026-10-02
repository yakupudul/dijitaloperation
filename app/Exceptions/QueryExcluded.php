<?php

namespace App\Exceptions;

use Illuminate\Validation\ValidationException;

final class QueryExcluded extends ValidationException
{
    public function __construct(public readonly array $expressions)
    {
        parent::__construct(validator([], []));
        $this->validator->errors()->add('query_text', __('query-exclusions.blocked', [
            'words' => implode(', ', array_column($expressions, 'label')),
        ]));
    }
}
