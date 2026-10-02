<?php

namespace App\Contracts\IntelligenceCore;

use App\Support\IntelligenceProjection\WebsiteProjectionContext;
use App\Support\IntelligenceProjection\WebsiteProjectionContribution;

interface WebsiteProjectionSourceAdapter extends IntelligenceSourceAdapter
{
    public function project(WebsiteProjectionContext $context): WebsiteProjectionContribution;
}
