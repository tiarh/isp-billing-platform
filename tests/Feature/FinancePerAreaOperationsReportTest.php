<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\User;
use App\Services\OperationalAreaDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-core')]
class FinancePerAreaOperationsReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_operations_report_adapts_finance_filters_to_existing_read_only_service(): void
    {
        $admin = User::factory()->create(['role' => 'superadmin']);
        $area = Area::create(['code' => 'OPS-A', 'name' => 'Gadang']);

        $this->mock(OperationalAreaDashboardService::class, function ($mock) use ($admin): void {
            $mock->shouldReceive('summary')
                ->once()
                ->with(Mockery::on(function (Request $request) use ($admin): bool {
                    return $request->query('ops_month') === '2026-09'
                        && $request->query('ops_area') === 'Gadang'
                        && $request->user()?->is($admin);
                }))
                ->andReturn([
                    'summary' => ['migration_target' => 1],
                    'rows' => [],
                    'stale' => false,
                    'source_note' => 'report-only fixture',
                ]);
        });

        $response = $this->actingAs($admin)->getJson(route('api.finance.per-area.operations', [
            'period' => '2026-09',
            'area' => $area->id,
            'as_of' => '2026-09-30',
        ]));

        $response->assertOk()
            ->assertJsonPath('period', '2026-09')
            ->assertJsonPath('as_of', '2026-09-30')
            ->assertJsonPath('formula_version', 'operational-area-report-v1')
            ->assertJsonPath('summary.migration_target', 1);
    }

    public function test_operations_report_passes_project_rw_and_sub_area_filters(): void
    {
        $admin = User::factory()->create(['role' => 'superadmin']);

        $this->mock(OperationalAreaDashboardService::class, function ($mock): void {
            $mock->shouldReceive('summary')
                ->once()
                ->with(Mockery::on(fn (Request $request): bool => $request->query('ops_project') === 'krian'
                    && $request->query('ops_rw') === 'RW 10'
                    && $request->query('ops_sub_area') === 'sub-a'))
                ->andReturn(['summary' => [], 'rows' => []]);
        });

        $this->actingAs($admin)
            ->getJson(route('api.finance.per-area.operations', ['period' => '2026-09', 'project' => 'krian', 'rw' => 'RW 10', 'sub_area' => 'sub-a']))
            ->assertOk();
    }

    public function test_operations_report_rejects_overlong_filter_and_technician(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'superadmin']))
            ->getJson(route('api.finance.per-area.operations', ['project' => str_repeat('a', 101)]))
            ->assertUnprocessable();

        $this->actingAs(User::factory()->create(['role' => 'teknisi']))
            ->getJson(route('api.finance.per-area.operations', ['period' => '2026-09']))
            ->assertForbidden();
    }

    public function test_operations_report_rejects_foreign_area_and_invalid_period(): void
    {
        $area = Area::create(['code' => 'OPS-B', 'name' => 'Krian']);
        $allowed = Area::create(['code' => 'OPS-C', 'name' => 'Gadang']);
        $admin = User::factory()->create(['role' => 'admin']);
        $admin->areas()->attach($allowed);

        $this->actingAs($admin)
            ->getJson(route('api.finance.per-area.operations', ['period' => '2026-09', 'area' => $area->id]))
            ->assertForbidden();

        $this->actingAs(User::factory()->create(['role' => 'superadmin']))
            ->getJson(route('api.finance.per-area.operations', ['period' => '2026-09\' OR 1=1']))
            ->assertUnprocessable();
    }
}
