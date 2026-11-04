<?php

namespace App\Models;

use LogicException;
use Spatie\Activitylog\Models\Activity as BaseActivity;

class Activity extends BaseActivity
{
    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new LogicException('Activity log entries are append-only and cannot be updated.');
        });
    }
}
