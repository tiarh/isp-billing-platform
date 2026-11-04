<?php

namespace Tests\Support\PsbGolden;

use App\Http\Controllers\OltC300Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Golden-test recording fake of the C300 controller endpoints the PSB flow calls
 * (list-uncfg and provision-fiberhome). Answers come from a queue the test fills.
 */
final class PsbGoldenOltController extends OltC300Controller
{
    /** @var list<array<string, mixed>> uncfg rows returned by listUncfg */
    public array $uncfg = [];

    /** @var array<string, mixed>|null a fixed error payload for listUncfg */
    public ?array $uncfgError = null;

    /** @var array{status:int, body:array<string, mixed>} */
    public array $provisionAnswer = ['status' => 200, 'body' => ['success' => true, 'onu_id' => 7, 'vlan' => 100]];

    public function __construct(private PsbGoldenRecorder $rec) {}

    public function listUncfg(Request $request)
    {
        $this->rec->add('olt.listUncfg', ['olt_id' => (int) $request->input('olt_id')]);
        if ($this->uncfgError !== null) {
            return new JsonResponse($this->uncfgError);
        }

        return new JsonResponse(['onus' => $this->uncfg]);
    }

    public function provisionFiberhome(Request $request)
    {
        $input = $request->all();
        foreach ($input as $k => $v) {
            if (preg_match('/(^|_)(password|passwd|pass|secret|api_?key|token)$/i', (string) $k) === 1 && (is_string($v) || is_int($v)) && ! str_starts_with((string) $v, '<pw')) {
                $input[$k] = '<pw len='.strlen((string) $v).'>';
            }
        }
        ksort($input);
        $this->rec->add('olt.provisionFiberhome', [
            'input' => $input,
            'approval_present' => $request->attributes->has('olt_provisioning_approval'),
        ]);

        return new JsonResponse($this->provisionAnswer['body'], $this->provisionAnswer['status']);
    }
}
