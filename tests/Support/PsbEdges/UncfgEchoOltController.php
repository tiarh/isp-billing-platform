<?php

namespace Tests\Support\PsbEdges;

use App\Http\Controllers\OltC300Controller;
use App\Models\PsbDraft;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Test edge: the C300 controller as the PSB conductor sees it. The uncfg list echoes
 * the SN of every PsbDraft (port 1/1/1, onu 1), so the real matcher finds each draft's
 * ONU; provision-fiberhome answers with a mutable canned answer. Counts calls.
 * Replaces the old findUncfgForDraft / provisionOlt harness overrides.
 */
class UncfgEchoOltController extends OltC300Controller
{
    public int $uncfgCalls = 0;

    public int $provisionCalls = 0;

    /** Successful provisions only. */
    public int $provisioned = 0;

    /** @var array{status:int, body:array<string, mixed>} */
    public array $answer = ['status' => 200, 'body' => ['success' => true, 'onu_id' => 7, 'vlan' => 100]];

    public function __construct() {}

    public function listUncfg(Request $request)
    {
        $this->uncfgCalls++;
        $rows = [];
        foreach (PsbDraft::query()->whereNotNull('sn')->pluck('sn')->unique() as $sn) {
            $rows[] = ['sn' => (string) $sn, 'port' => '1/1/1', 'onu_id' => 1];
        }

        return new JsonResponse(['onus' => $rows]);
    }

    public function provisionFiberhome(Request $request)
    {
        $this->provisionCalls++;
        if ($this->answer['status'] < 400 && ! empty($this->answer['body']['success'])) {
            $this->provisioned++;
        }

        return new JsonResponse($this->answer['body'], $this->answer['status']);
    }
}
