<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FacebookAnalyticsReviewPlan extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['actions' => 'encrypted:array', 'version' => 'integer', 'expected_posts' => 'integer'];
    }
}
