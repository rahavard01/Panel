<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PanelPlan extends Model
{
    protected $table = 'panel_plan';
    public $timestamps = false;

    protected $fillable = [
        'name',
        'enable',          // دقت: enable
        'default_price',
        'v2_plan_id',
        'plan_key',        // کلید ثابت مثل 'test','1m',...
    ];
}

