<?php

namespace App\Enums;

enum AssistantClarificationReason: string
{
    case CustomerScopeRequired = 'customer_scope_required';
    case BrandScopeRequired = 'brand_scope_required';
    case DigitalAssetScopeRequired = 'digital_asset_scope_required';
    case DateRangeRequired = 'date_range_required';
    case MetricRequired = 'metric_required';
    case GoalSelectionRequired = 'goal_selection_required';
    case AmbiguousEntity = 'ambiguous_entity';
    case AmbiguousIntent = 'ambiguous_intent';
    case CanonicalOrderUnavailable = 'canonical_order_unavailable';
}
