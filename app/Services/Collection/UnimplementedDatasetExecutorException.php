<?php

namespace App\Services\Collection;

use App\Enums\Collection\CollectionErrorCategory;
use RuntimeException;

final class UnimplementedDatasetExecutorException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly CollectionErrorCategory $category = CollectionErrorCategory::UnimplementedCapability,
    ) {
        parent::__construct($message);
    }
}
