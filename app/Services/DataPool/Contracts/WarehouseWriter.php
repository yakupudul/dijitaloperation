<?php

namespace App\Services\DataPool\Contracts;

use App\Services\Collection\Contracts\NormalizedDatasetWriter;

/**
 * Provider-neutral warehouse boundary.
 * Collectors emit NormalizedDatasetBatch; implementations resolve physical storage.
 *
 * Future: BigQueryWarehouseWriter may implement this without changing collectors.
 * BigQuery production implementation: NONE in V1.
 */
interface WarehouseWriter extends NormalizedDatasetWriter {}
