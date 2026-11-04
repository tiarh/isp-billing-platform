<?php

namespace Tests\Feature;

use App\Models\Activity;
use LogicException;
use Tests\TestCase;

class SecurityAuditGovernanceTest extends TestCase
{
    public function test_activity_log_uses_a_guarded_append_only_model_with_explicit_retention(): void
    {
        $config = file_get_contents(base_path('config/activitylog.php'));
        $model = file_get_contents(base_path('app/Models/Activity.php'));

        // config/activitylog.php imports the model (Pint: no FQCN); require the
        // import to be App\Models\Activity and the setting to use it.
        $this->assertStringContainsString('use App\Models\Activity;', $config);
        $this->assertStringContainsString("'activity_model' => Activity::class", $config);
        $this->assertStringContainsString("'delete_records_older_than_days' => 365", $config);
        $this->assertStringContainsString('static::updating', $model);
        $this->assertStringContainsString('Activity log entries are append-only', $model);
    }

    public function test_activity_log_rejects_update_events_at_runtime(): void
    {
        $activity = new Activity;

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Activity log entries are append-only');

        Activity::getEventDispatcher()->dispatch('eloquent.updating: '.Activity::class, $activity);
    }
}
