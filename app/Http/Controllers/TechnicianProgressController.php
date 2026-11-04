<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\TechnicianProgressService;
use App\Services\TechnicianProgressTemplateService;
use App\Services\Wa\WaGroupDirectory;
use App\Services\Wa\WaGroupGuards;
use Illuminate\Http\Request;

class TechnicianProgressController extends Controller
{
    public function show(Request $request, TechnicianProgressTemplateService $service, TechnicianProgressService $progress)
    {
        $date = $request->query('date');
        $date = is_string($date) ? $date : null;
        $includeLegacy = $request->boolean('include_legacy', false);

        return response()->json(array_merge(
            $service->snapshot($date, null, null, $includeLegacy),
            $progress->config(),
            ['activity' => $progress->activitySnapshot($date)]
        ));
    }

    public function preview(Request $request, TechnicianProgressTemplateService $service)
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date'],
            'manual_text' => ['nullable', 'string', 'max:12000'],
            'notes' => ['nullable', 'string', 'max:4000'],
        ]);

        return response()->json($service->snapshot(
            $validated['date'] ?? null,
            $validated['manual_text'] ?? null,
            $validated['notes'] ?? null
        ));
    }

    public function summary(Request $request, TechnicianProgressService $progress)
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date'],
            'at' => ['nullable', 'date_format:H:i'],
        ]);

        return response()->json([
            'message' => $progress->buildSummaryMessage($validated['date'] ?? null, $validated['at'] ?? null),
            'dry_run_enabled' => $progress->config()['dry_run_enabled'],
        ]);
    }

    public function save(Request $request, TechnicianProgressTemplateService $service, TechnicianProgressService $progress)
    {
        $validated = $request->validate([
            // Share/Input groups + labels live on Pengaturan > Grup WhatsApp (WaGroupSettingsWriter) only: a
            // template/schedule save must never touch (let alone wipe) them.
            'share_group_jid' => ['prohibited'],
            'share_group_label' => ['prohibited'],
            'input_group_jid' => ['prohibited'],
            'input_group_label' => ['prohibited'],
            'manual_text' => ['nullable', 'string', 'max:12000'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'preview_time' => ['nullable', 'date_format:H:i'],
            'morning_send_time' => ['nullable', 'date_format:H:i'],
            'summary_times' => ['nullable', 'string', 'max:80'],
            'auto_send_enabled' => ['nullable', 'boolean'],
            'summary_enabled' => ['nullable', 'boolean'],
            'dry_run_enabled' => ['nullable', 'boolean'],
            'reply_enabled' => ['nullable', 'boolean'],
            'reply_channel' => ['nullable', 'in:ops,ticket'],
            'photo_archive_enabled' => ['nullable', 'boolean'],
            'photo_attach_minutes' => ['nullable', 'integer', 'min:5', 'max:720'],
            'auto_area_enabled' => ['nullable', 'boolean'],
            'legacy_scraper_enabled' => ['nullable', 'boolean'],
        ]);

        // The auto reply into the Input group is sent on reply_channel: when that channel changes, the bot number
        // of the NEW channel must be in the Input group (a personal chat has no membership to probe).
        $newChannel = $validated['reply_channel'] ?? null;
        $oldChannel = strtolower(trim((string) Setting::get('ticket_progress_reply_channel', 'ticket'))) === 'ops' ? 'ops' : 'ticket';
        if ($newChannel !== null && $newChannel !== $oldChannel) {
            $inputJid = WaGroupGuards::normalizeTarget((string) Setting::get('wa_ticket_progress_input_group_jid', ''));
            if (WaGroupGuards::isGroupJid($inputJid)
                && app(WaGroupDirectory::class)->membership($newChannel, $inputJid)['member'] === false) {
                return response()->json([
                    'message' => 'Nomor WA ('.$newChannel.') bukan anggota Grup Input progres teknisi. Tambahkan nomor ke grup atau pilih saluran lain.',
                    'errors' => ['reply_channel' => ['Nomor WA ('.$newChannel.') bukan anggota Grup Input progres teknisi.']],
                ], 422);
            }
        }

        Setting::set('ticket_progress_manual_text', (string) ($validated['manual_text'] ?? ''), 'text', 'ticket', 'Template manual progress harian teknisi');
        Setting::set('ticket_progress_notes', (string) ($validated['notes'] ?? ''), 'text', 'ticket', 'Catatan progress harian teknisi');
        Setting::set('ticket_progress_preview_time', (string) ($validated['preview_time'] ?? '21:00'), 'text', 'ticket', 'Jam preview jadwal teknisi H-1');
        Setting::set('ticket_progress_morning_send_time', (string) ($validated['morning_send_time'] ?? '08:00'), 'text', 'ticket', 'Jam kirim jadwal teknisi');
        Setting::set('ticket_progress_summary_times', (string) ($validated['summary_times'] ?? '12:00,15:00,17:00'), 'text', 'ticket', 'Jam rekap progress teknisi');
        Setting::set('ticket_progress_auto_send_enabled', ! empty($validated['auto_send_enabled']) ? '1' : '0', 'boolean', 'ticket', 'Auto kirim jadwal teknisi');
        Setting::set('ticket_progress_summary_enabled', ! empty($validated['summary_enabled']) ? '1' : '0', 'boolean', 'ticket', 'Auto kirim rekap progress teknisi');
        Setting::set('ticket_progress_dry_run_enabled', ! empty($validated['dry_run_enabled']) ? '1' : '0', 'boolean', 'ticket', 'Dry-run progress teknisi');
        $replyEnabled = array_key_exists('reply_enabled', $validated)
            ? ! empty($validated['reply_enabled'])
            : (bool) Setting::get('ticket_progress_reply_enabled', false);
        $photoArchiveEnabled = array_key_exists('photo_archive_enabled', $validated)
            ? ! empty($validated['photo_archive_enabled'])
            : (bool) Setting::get('ticket_progress_photo_archive_enabled', true);
        $autoAreaEnabled = array_key_exists('auto_area_enabled', $validated)
            ? ! empty($validated['auto_area_enabled'])
            : (bool) Setting::get('ticket_progress_auto_area_enabled', true);
        $legacyScraperEnabled = array_key_exists('legacy_scraper_enabled', $validated)
            ? ! empty($validated['legacy_scraper_enabled'])
            : (bool) Setting::get('ticket_progress_legacy_scraper_enabled', true);

        Setting::set('ticket_progress_reply_enabled', $replyEnabled ? '1' : '0', 'boolean', 'ticket', 'Auto reply grup kerjaan');
        Setting::set('ticket_progress_reply_channel', (string) ($validated['reply_channel'] ?? Setting::get('ticket_progress_reply_channel', 'ticket')), 'text', 'ticket', 'Channel WA auto reply grup kerjaan');
        Setting::set('ticket_progress_photo_archive_enabled', $photoArchiveEnabled ? '1' : '0', 'boolean', 'ticket', 'Arsip foto progress teknisi');
        Setting::set('ticket_progress_photo_attach_minutes', (int) ($validated['photo_attach_minutes'] ?? Setting::get('ticket_progress_photo_attach_minutes', 90)), 'integer', 'ticket', 'Window attach foto progress');
        Setting::set('ticket_progress_auto_area_enabled', $autoAreaEnabled ? '1' : '0', 'boolean', 'ticket', 'Auto pembagian team berbasis area');
        Setting::set('ticket_progress_legacy_scraper_enabled', $legacyScraperEnabled ? '1' : '0', 'boolean', 'ticket', 'Scraper ticket/PSB eBilling lama');

        return response()->json([
            'success' => true,
            'progress' => array_merge($service->snapshot(null, null, null, false), $progress->config()),
            'message' => 'Template progress harian disimpan. Belum ada pesan WA yang dikirim.',
        ]);
    }
}
