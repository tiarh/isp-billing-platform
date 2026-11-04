<?php

namespace App\Http\Middleware;

use App\Exceptions\Olt\OltProvisioningBlockedException;
use App\Models\Olt;
use App\Services\Olt\OltProvisioningGuard;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckOltProvisioningApproval
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->boolean('dry_run')
            && ($request->is('api/olt-c300/provision-fiberhome') || $request->is('api/olt-huawei/provision'))) {
            return $next($request);
        }

        $olt = $this->resolveOlt($request);
        if (! $olt) {
            return $next($request);
        }

        $approval = $request->attributes->get('olt_provisioning_approval');
        if (! is_array($approval)) {
            $approval = [
                'approval' => $request->input('approval') ?: $request->header('X-OLT-Approval'),
                'approved_at' => $request->input('approved_at') ?: $request->header('X-OLT-Approved-At'),
                'signature' => $request->input('signature') ?: $request->header('X-OLT-Signature'),
            ];
        }
        if (OltProvisioningGuard::matchesLiveConfirmation($request->input('live_confirm'), $request->route()?->getName())
            && $request->user()?->isGlobalAdmin()
            && trim((string) ($approval['approval'] ?? '')) === '') {
            $approval = app(OltProvisioningGuard::class)->issueInternalApproval(
                $olt,
                (string) ($request->route()?->getName() ?: 'olt-write'),
                'operator-'.$request->user()->getKey(),
            );
        }

        try {
            $result = app(OltProvisioningGuard::class)->provision($olt, [
                'commands' => ['route-write-gate'],
                'approval' => $approval['approval'] ?? null,
                'approved_at' => $approval['approved_at'] ?? null,
                'signature' => $approval['signature'] ?? null,
            ]);
        } catch (OltProvisioningBlockedException) {
            return $this->blockedResponse();
        }

        if ($result['dry_run']) {
            return $this->blockedResponse('dry_run');
        }

        $request->attributes->set('olt_provisioning_gate_checked', true);

        return $next($request);
    }

    private function resolveOlt(Request $request): ?Olt
    {
        $routeOlt = $request->route('olt');
        if ($routeOlt instanceof Olt) {
            return $routeOlt;
        }

        $oltId = $request->input('olt_id');
        if (! is_numeric($oltId) || (int) $oltId < 1) {
            return null;
        }

        return Olt::query()->find((int) $oltId);
    }

    private function blockedResponse(string $code = 'approval_required'): Response
    {
        return response()->json([
            'success' => false,
            'error_code' => 'olt_provisioning_'.$code,
            'message' => 'OLT write blocked by the provisioning safety gate. No device write was performed.',
        ], 423);
    }
}
