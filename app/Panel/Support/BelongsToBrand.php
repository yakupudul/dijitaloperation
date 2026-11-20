<?php

namespace App\Panel\Support;

use App\Models\Brand;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Brand lock for a panel model: reads are limited to the current brand and fail closed (no rows) when no brand is
 * set; a new row takes the current brand and cannot be written without one.
 */
trait BelongsToBrand
{
    public static function bootBelongsToBrand(): void
    {
        static::addGlobalScope('brand', function (Builder $query): void {
            $context = app(PanelContext::class);
            if ($context->isSystem()) {
                return;
            }
            $brandId = $context->brandId();
            if ($brandId === null) {
                $query->whereRaw('1 = 0');

                return;
            }
            $query->where($query->getModel()->getTable().'.brand_id', $brandId);
        });

        static::creating(function (self $model): void {
            $context = app(PanelContext::class);
            if ($context->brandId() !== null && ! $context->isSystem()) {
                $model->brand_id = $context->brandId();
            }
            if (empty($model->brand_id)) {
                throw new LogicException(static::class.' cannot be saved without a brand.');
            }
        });

        static::updating(function (self $model): void {
            if ($model->isDirty('brand_id')) {
                throw new LogicException(static::class.' cannot move to another brand.');
            }
        });
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
