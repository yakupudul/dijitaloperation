<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['service_catalog_item_id', 'label', 'normalized_key'])]
class ServiceMatchingKeyword extends Model {}
