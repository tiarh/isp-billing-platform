<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Wa\WaGroupDirectory;
use App\Services\Wa\WaGroupGuards;
use App\Services\Wa\WaGroupRoles;
use App\Services\Wa\WaGroupSettingsWriter;
use App\Support\WaGroupJid;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings > Grup WhatsApp. Access: superadmin and an admin WITHOUT area scope (not NOC, not area admins).
 * No TOTP step-up on save (owner decision); every change writes an activity log row.
 * PSB / migration groups are shown read-only: this controller never writes any PSB setting.
 */
class WaGroupSettingsController extends Controller
{
    public function __construct(
        private readonly WaGroupRoles $roles,
        private readonly WaGroupDirectory $directory,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorizeAccess($request);
        $snapshot = $this->roles->snapshot();

        return Inertia::render('Settings/WaGroups', [
            'settings' => $snapshot,
            'version' => $this->roles->version($snapshot),
        ]);
    }

    /** Picker data: whitelisted {jid, name, members} + per-role selectable reasons. Never raw Evolution rows. */
    public function groups(Request $request): JsonResponse
    {
        $user = $this->authorizeAccess($request);
        $channel = $request->query('channel') === 'ticket' ? 'ticket' : 'ops';

        $fresh = $request->boolean('fresh');
        if ($fresh) {
            $key = 'wa-groups-fresh:'.$user->id;
            if (RateLimiter::tooManyAttempts($key, 3)) {
                $fresh = false;
            } else {
                RateLimiter::hit($key, 60);
            }
        }

        $dir = $this->directory->list($channel, $fresh);
        $s = $this->roles->snapshot();
        $psb = $s['readonly']['psb_group_jids'];
        $report = $s['report']['group_jid'] !== '' ? array_merge([$s['report']['group_jid']], $s['report']['extra_jids']) : [];
        $ops = $this->roles->effectiveOpsJid();
        $ticket = (string) $s['readonly']['ticket_group_jid'];

        $groups = [];
        foreach ($dir['groups'] as $g) {
            $jid = $g['jid'];
            $groups[] = [
                'jid' => $jid,
                'name' => $g['name'],
                'members' => $g['members'],
                'is_psb' => WaGroupGuards::inList($jid, $psb),
                'is_report' => WaGroupGuards::inList($jid, $report),
                'is_ops_alert' => WaGroupGuards::same($ops, $jid),
                'blocked' => [
                    'report' => WaGroupGuards::reportRefusal($jid, $psb),
                    'ops_alert' => WaGroupGuards::opsAlertRefusal($jid, $psb, $ticket),
                    'shadow' => WaGroupGuards::shadowRefusal($jid, (bool) $s['shadow']['allow_report_group'], $psb, $report, $ops),
                    'undelivered' => WaGroupGuards::undeliveredRefusal($jid, $psb, $report, $ops),
                    'ticket' => WaGroupGuards::opsAlertRefusal($jid, $psb, '') ?? (WaGroupGuards::same($ops, $jid) ? WaGroupGuards::REASON_OPS : null),
                    'connectivity' => WaGroupGuards::notPsbRefusal($jid, $psb),
                    'attendance' => WaGroupGuards::notPsbRefusal($jid, $psb),
                    'technician_share' => WaGroupGuards::notPsbRefusal($jid, $psb),
                    'technician_input' => WaGroupGuards::notPsbRefusal($jid, $psb),
                ],
            ];
        }

        return response()->json([
            'channel' => $channel,
            'groups' => $groups,
            'stale' => $dir['stale'],
            'fetched_at' => $dir['fetched_at'],
            'error_code' => $dir['error_code'],
        ]);
    }

    /** Read-only membership probe (no write, no message). */
    public function membership(Request $request): JsonResponse
    {
        $this->authorizeAccess($request);
        $data = $request->validate([
            'channel' => ['required', Rule::in(['ops', 'ticket'])],
            'jid' => ['required', 'string', 'max:255'],
        ]);
        $jid = WaGroupJid::normalize($data['jid']);
        if (! WaGroupGuards::isGroupJid($jid)) {
            return response()->json(['member' => null, 'subject' => null, 'size' => null, 'error' => 'format'], 422);
        }
        $m = $this->directory->membership($data['channel'], $jid);

        return response()->json([
            'member' => $m['member'],
            'subject' => $m['subject'],
            'size' => $m['size'],
            'error' => $m['error'] === null ? null : 'unverified',
        ]);
    }

    public function update(Request $request, WaGroupSettingsWriter $writer): RedirectResponse
    {
        $user = $this->authorizeAccess($request);

        $data = $request->validate([
            'section' => ['required', Rule::in(WaGroupRoles::SECTIONS)],
            'version' => ['required', 'string', 'size:40'],
            'force_unverified' => ['nullable', 'array'],
            'force_unverified.*' => [Rule::in(WaGroupRoles::SECTIONS)],
            'group_jid' => ['nullable', 'string', 'max:255'],
            'jid' => ['nullable', 'string', 'max:255'],
            'channel' => ['nullable', 'string', 'max:10'],
            'allow_report_group' => ['nullable', 'boolean'],
            'daily_enabled' => ['nullable', 'boolean'],
            'recap_cmd_enabled' => ['nullable', 'boolean'],
            'pppoe_cmd_enabled' => ['nullable', 'boolean'],
            'onu_daily_enabled' => ['nullable', 'boolean'],
            'psb_detail_daily_enabled' => ['nullable', 'boolean'],
            'onu_recap_cmd_enabled' => ['nullable', 'boolean'],
            'label' => ['nullable', 'string', 'max:255'],
            'share_jid' => ['nullable', 'string', 'max:255'],
            'share_label' => ['nullable', 'string', 'max:120'],
            'input_jid' => ['nullable', 'string', 'max:255'],
            'input_label' => ['nullable', 'string', 'max:120'],
            // PSB / migration groups and the global #rekap switch are read-only here (edited in Aktivasi > PSB WA).
            'psb_wa_group_ids' => ['prohibited'],
            'psb_recap_cmd_enabled' => ['prohibited'],
        ]);

        $fields = array_intersect_key($data, array_flip(WaGroupSettingsWriter::FIELDS[$data['section']]));
        $result = $writer->apply($data['section'], $fields, $data['version'], (array) ($data['force_unverified'] ?? []), $user);

        if (! $result['ok']) {
            throw ValidationException::withMessages($result['errors']);
        }

        return back()->with('success', $result['changed'] === []
            ? 'Tidak ada perubahan.'
            : 'Pengaturan grup WhatsApp disimpan.');
    }

    /** Superadmin or an admin without area scope. NOC and area-scoped admins get 403. */
    private function authorizeAccess(Request $request): User
    {
        $user = $request->user();
        abort_unless($user && ($user->isSuperAdmin() || ($user->isAdmin() && ! $user->hasAreaScope())), 403);

        return $user;
    }
}
