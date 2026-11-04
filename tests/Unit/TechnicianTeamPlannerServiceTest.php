<?php

namespace Tests\Unit;

use App\Services\TechnicianTeamPlannerService;
use PHPUnit\Framework\TestCase;

class TechnicianTeamPlannerServiceTest extends TestCase
{
    public function test_plans_jobs_by_area_and_task_type(): void
    {
        $planned = (new TechnicianTeamPlannerService)->plan([
            [
                'source' => 'local_psb',
                'task_type' => 'psb',
                'customer_name' => 'YUSAK',
                'site' => 'Suko',
                'task' => 'PSB RT03 RW04',
            ],
            [
                'source' => 'legacy_ticket',
                'task_type' => 'maintenance',
                'customer_name' => 'DJOKO',
                'site' => 'Comboran',
                'task' => 'Lemot',
                'priority' => 'urgent',
            ],
            [
                'source' => 'manual',
                'task_type' => 'pembangunan',
                'site' => 'Kalirejo',
                'task' => 'Deploy ODP 0/8',
            ],
        ]);

        $this->assertCount(3, $planned);
        $this->assertSame(1, $planned[0]['team_no']);
        $this->assertSame('SUKO', $planned[0]['site']);
        $this->assertSame(4, $planned[1]['team_no']);
        $this->assertSame('KALIREJO', $planned[1]['site']);
        $this->assertSame(7, $planned[2]['team_no']);
        $this->assertSame('urgent', $planned[2]['priority']);
    }

    public function test_splits_large_suko_psb_area_pool_to_ikr_teams(): void
    {
        $planned = (new TechnicianTeamPlannerService)->plan([
            [
                'source' => 'manual_wa',
                'task_type' => 'psb',
                'site' => 'Suko',
                'task' => 'STANDBY PSB SUKO TARGET 20/HARI',
            ],
        ]);

        $this->assertCount(3, $planned);
        $this->assertSame([1, 2, 3], array_column($planned, 'team_no'));
        $this->assertSame(['WIDI', 'FIKRI'], $planned[0]['technicians']);
        $this->assertSame(['REHAN', 'VALNO'], $planned[1]['technicians']);
        $this->assertSame(['ROBI', 'DIMAS'], $planned[2]['technicians']);
    }

    public function test_plans_pelurusan_and_terminasi_as_pembangunan_tasks(): void
    {
        $planned = (new TechnicianTeamPlannerService)->plan([
            [
                'source' => 'manual_wa',
                'task_type' => 'pembangunan',
                'site' => 'Capang',
                'task' => 'Pelurusan ODC',
            ],
            [
                'source' => 'manual_wa',
                'task_type' => 'terminasi',
                'site' => 'Kalirejo',
                'task' => 'Terminasi ODP 0/8',
            ],
        ]);

        $this->assertCount(2, $planned);
        $this->assertSame(4, $planned[0]['team_no']);
        $this->assertSame('TERMINASI ODP 0/8', $planned[0]['task']);
        $this->assertSame(6, $planned[1]['team_no']);
        $this->assertSame('PELURUSAN ODC', $planned[1]['task']);
    }

    public function test_distributes_mixed_build_jobs_across_field_teams(): void
    {
        $planned = (new TechnicianTeamPlannerService)->plan([
            ['source' => 'manual_wa', 'task_type' => 'psb', 'site' => 'Suko', 'task' => 'STANDBY PSB SUKO TARGET 20/HARI'],
            ['source' => 'manual_wa', 'task_type' => 'pembangunan', 'site' => 'Kalirejo', 'task' => 'DEPLOY ODP RW 15 0/4'],
            ['source' => 'manual_wa', 'task_type' => 'pembangunan', 'site' => 'Suko', 'task' => 'DEPLOY ODP 0/8'],
            ['source' => 'manual_wa', 'task_type' => 'pembangunan', 'site' => 'Capang', 'task' => 'PELURUSAN ODC'],
            ['source' => 'manual_wa', 'task_type' => 'pembangunan', 'site' => 'Gadang', 'task' => 'REPAIR ODP'],
        ]);

        $teamsByTask = [];
        foreach ($planned as $team) {
            $teamsByTask[$team['task']] = $team['team_no'];
        }

        $this->assertSame([1, 2, 3], array_slice(array_column($planned, 'team_no'), 0, 3));
        $this->assertSame(4, $teamsByTask['DEPLOY ODP RW 15 0/4']);
        $this->assertSame(5, $teamsByTask['DEPLOY ODP 0/8']);
        $this->assertSame(6, $teamsByTask['PELURUSAN ODC']);
        $this->assertSame(7, $teamsByTask['REPAIR ODP']);
    }

    public function test_respects_explicit_custom_technician_for_manual_task(): void
    {
        $planned = (new TechnicianTeamPlannerService)->plan([
            [
                'source' => 'manual_wa',
                'task_type' => 'pembangunan',
                'site' => 'Klampok',
                'task' => 'DEPLOY ODP',
                'technicians' => ['RIDWAN'],
            ],
        ]);

        $this->assertCount(1, $planned);
        $this->assertNull($planned[0]['team_no']);
        $this->assertSame('Team RIDWAN', $planned[0]['team_label']);
        $this->assertSame(['RIDWAN'], $planned[0]['technicians']);
    }
}
