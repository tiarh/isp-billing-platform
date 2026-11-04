<?php

use App\Jobs\RefreshMonitoringCacheJob;
use App\Jobs\RunMonitoringCollectorJob;
use App\Jobs\SyncOltOpticalJob;
use App\Models\Setting;
use App\Services\CustomerOnu\CustomerOnuFieldSyncService;
use App\Services\MonitoringAdmissionGate;
use App\Services\OltCollectorService;
use App\Services\Ops\VmAlertService;
use App\Services\OpsAlertService;
use App\Services\Outage\OutageScheduleGate;
use App\Services\Outage\OutageSettings;
use App\Services\Outage\OutageShadowDigest;
use Cron\CronExpression;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule;

// Safe is the default. Full mode is an explicit capacity decision; starting
// schedule:work must not fan out every device collector unexpectedly.
$schedulerMode = (string) config('services.ebilling_scheduler.mode', 'safe');
$fullSchedulerEnabled = $schedulerMode === 'full';
$monitoringSchedulerEnabled = in_array($schedulerMode, ['monitoring', 'full'], true);
$monitoringDecompositionEnabled = static fn (): bool => (bool) config(
    'services.monitoring.queue_decomposition_enabled',
    false,
);
$monitoringScheduleGate = static fn (): bool => app(
    MonitoringAdmissionGate::class,
)->allows('scheduler');
$huaweiOpticalScheduleGate = static fn (): bool => app(
    MonitoringAdmissionGate::class,
)->allowsHuaweiOptical();
// OLT collector lane (SNMP/CLI state + RX + inventory) is core NOC truth:
// exempt from host-load/queue gate, still honors operator hold + DB check.
// Router-health/ACS collectors stay behind $monitoringScheduleGate; the PPPoE
// session lanes use $routerSessionCollectorSchedule (below).
$oltCollectorScheduleGate = static fn (): bool => app(
    MonitoringAdmissionGate::class,
)->allowsOltCollector();
$oltCollectorSchedule = static fn (): bool => ! $monitoringDecompositionEnabled()
    && $oltCollectorScheduleGate();
$legacyMonitoringSchedule = static fn (): bool => ! $monitoringDecompositionEnabled()
    && $monitoringScheduleGate();
// PPPoE session lanes (is_online freshness): exempt from host-load gate like
// the OLT lane, still yield to PSB/migration queues + operator hold + DB.
$routerSessionCollectorSchedule = static fn (): bool => app(
    MonitoringAdmissionGate::class,
)->allowsRouterSessionCollector();
// NOC dashboard read-model refresh: bounded DB aggregate, exempt from host-load
// gate so a busy host cannot freeze the whole cockpit (operator hold still wins).
$monitoringDashboardGate = static fn (): bool => app(
    MonitoringAdmissionGate::class,
)->allowsMonitoringDashboard();
// OLT RA inventory (1223 ONUs): bounded budget + 6-hourly cadence. Audit
// 2026-10-08: hourly runs hit the 600s worker timeout every hour and held
// worker-ops (network-enforcement lane) for ~10 min. Guarded by
// SchedulerSafetyContractOltInventoryRaTest.
$oltInventoryBudget = OltCollectorService::inventoryBudgetSeconds();
$oltInventoryJobTimeout = $oltInventoryBudget + (int) config('services.olt_inventory_sync.job_grace_seconds', 45);
$oltInventoryRaCron = (string) config('services.olt_inventory_sync.ra_cron', '2 1-23/6 * * *');
if (! CronExpression::isValidExpression($oltInventoryRaCron)) {
    $oltInventoryRaCron = '2 1-23/6 * * *';
}
// OLT RA SNMP inventory (owner approved 2026-10-08; ~6 s for 1223 ONUs):
// SNMP every 15 min, SSH once a day as a cross-check. Without OLT 1 in
// services.olt_inventory_sync.snmp_olt_ids the SSH schedule above applies.
// Guarded by SchedulerSafetyContractOltInventorySnmpTest.
$oltInventoryRaViaSnmp = in_array(1, array_map('intval', (array) config('services.olt_inventory_sync.snmp_olt_ids', [])), true);
$oltInventoryRaSnmpCron = (string) config('services.olt_inventory_sync.ra_snmp_cron', '4,19,34,49 * * * *');
if (! CronExpression::isValidExpression($oltInventoryRaSnmpCron)) {
    $oltInventoryRaSnmpCron = '4,19,34,49 * * * *';
}
$oltInventoryRaSshCron = (string) config('services.olt_inventory_sync.ra_ssh_cron', '10 3 * * *');
if (! CronExpression::isValidExpression($oltInventoryRaSshCron)) {
    $oltInventoryRaSshCron = '10 3 * * *';
}

Schedule::command('billing:check-overdue')
    ->dailyAt('15:00')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping(15)
    ->description('Check overdue invoices and isolate delinquent customers');
// STEP 2026-10-02 (owner, R2): faster reconnect cadence. The 15:00 run stays as
// the main isolation window; this 2-hourly pass mainly picks up paid-arrears
// reconnects so a customer who pays does not wait until 15:00 to come back.
// Re-enabled after the paid-through-prior-month reconnect fix (PR #305) was
// deployed and the September-paid isolated backfill completed.
Schedule::command('billing:check-overdue')
    ->everyTwoHours()
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping(15)
    ->runInBackground()
    ->description('Two-hourly billing policy pass (fast paid-arrears reconnects)');

// PG-8: online payment fallbacks. Both are no-ops until a payment gateway is
// configured. check-pending asks the provider only for open attempts whose
// callback may have been missed, with sparse back-off (Duitku rate-limits
// transactionStatus). release-held credits payments held while the Payment
// write kill switch was OFF (safety net; the UI toggle also dispatches it).
// Off the 5-minute boundaries (SchedulerStaggerContractTest).
Schedule::command('payments:check-pending-gateway')
    ->cron('7,22,37,52 * * * *')
    ->withoutOverlapping(15)
    ->runInBackground()
    ->description('Payment gateway: status check for open online payments (missed callbacks)');
Schedule::command('payments:release-held')
    ->cron('13,43 * * * *')
    ->withoutOverlapping(15)
    ->runInBackground()
    ->description('Payment gateway: credit payments held while kill switch was OFF');

// F0 PR-D: read-only router <-> DB isolation reconciliation. Runs 07:47 WIB so
// the count-only NOC summary lands before 08:00. OFF by default
// (NETWORK_RECONCILE_SCHEDULE_ENABLED); reads /ppp only, never writes to a
// router. Guarded by SchedulerSafetyContractNetworkReconcileTest.
Schedule::command('billing:reconcile-network --store'.(config('services.network_reconcile.notify', true) ? ' --notify' : ''))
    ->dailyAt('07:47')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping(30)
    ->runInBackground()
    ->when(static fn (): bool => (bool) config('services.network_reconcile.schedule_enabled', false))
    ->description('Read-only router vs DB isolation reconciliation (enforcement_drift)');

// Unconditional scheduler heartbeat so the control-plane observability snapshot
// (scheduler_last_run) stops reporting null when the scheduler is healthy.
// One lightweight upsert per minute; safe in safe/full/monitoring modes.
Schedule::call(static function (): void {
    Setting::set(
        'scheduler_last_run_at',
        now('Asia/Jakarta')->toIso8601String(),
        'text',
        'ops',
        'Scheduler last run marker',
    );
})
    ->everyMinute()
    ->timezone('Asia/Jakarta')
    ->name('scheduler-heartbeat')
    ->description('Mark scheduler_last_run_at so NOC control plane shows scheduler alive');

// PR-ALERT dead-man: ping HEALTHCHECK_SCHEDULER_URL (throttled to ~5 min in
// OpsAlertService) so an external check alerts when the scheduler stops.
// Disabled while the URL is empty; every scheduler mode.
Schedule::call(static fn () => app(OpsAlertService::class)->pingSchedulerDeadMan())
    ->everyMinute()
    ->name('scheduler-dead-man')
    ->when(static fn (): bool => app(OpsAlertService::class)->schedulerDeadManConfigured())
    ->description('Dead-man ping to external healthcheck (scheduler alive)');

// Monthly invoice generation is core billing, not a monitoring collector. It
// must run in every scheduler mode (safe/monitoring/full); previously it lived
// inside the full-only block, so with EBILLING_SCHEDULER_MODE=monitoring the
// monthly run never happened and new customers were never billed.
Schedule::command('billing:generate')
    ->monthlyOn(1, '07:00')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping(120)
    ->runInBackground()
    ->description('Generate monthly invoices for active customers');

// Owner 2026-10-08: daily self-heal. Any active/isolated customer that became
// billable after the 1st (manual activation, raw insert, import) and still has
// no CURRENT-period invoice gets it (due date floored to the creation date,
// so isolation is H+3 after creation). Never arrears, join month, Rp0 or NULL
// join_date (reported only). Idempotent; runs after the 07:00 monthly run.
Schedule::command('billing:generate --catch-up')
    ->dailyAt('09:10')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping(60)
    ->runInBackground()
    ->description('Daily catch-up: current-period invoice for billable customers missing it');

// Owner 2026-10-09: safety net for pickup cases whose ONU was reused by another
// live customer (the Customer saved hook closes them live). Report-only until
// setting device_recovery_close_reused_apply=1; kill switch
// device_recovery_close_reused_enabled (default on). Never touches devices.
Schedule::command('device-recovery:close-reused')
    ->dailyAt('02:37')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping(30)
    ->runInBackground()
    ->description('Daily: close pickup cases whose ONU is reused by a live customer (dry-run until enabled)');

// Roadmap 1.3 PR-4b: sweeper for guard-deferred terminations. Re-evaluates the
// lifecycle guard for every pending row; REPORT-ONLY until the owner sets
// lifecycle_deferred_sweeper_apply=1 (kill switch lifecycle_deferred_sweeper_enabled,
// default on). The held ONU delete re-dispatch inside it stays off until
// lifecycle_deferred_onu_redispatch_apply=1. Never overrides the guard, never
// cancels. Outside the full-only block so it runs in monitoring mode (prod).
Schedule::command('customers:finalize-deferred-terminations')
    ->hourlyAt(41)
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping(50)
    ->onOneServer()
    ->runInBackground()
    ->description('Hourly: re-check guard-deferred terminations (report-only until enabled)');

// Daily ops digest of pending terminations (WA to the SKYNET REPORT ops group,
// deduped per day; nothing is sent when 0 pending and nothing changed yesterday).
Schedule::command('customers:finalize-deferred-terminations --digest')
    ->dailyAt('08:22')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping(20)
    ->onOneServer()
    ->runInBackground()
    ->description('Daily 08:22 WIB: ops WA digest of pending (guard-deferred) terminations');

// Owner 2026-10-08: pending_installation -> active once ONU (state + RX) and
// PPPoE are online and fresh. Kill switch BILLING_AUTO_ACTIVATE_ONLINE=false;
// cap BILLING_AUTO_ACTIVATE_MAX_PER_RUN. Reads snapshots only (no device).
Schedule::command('customers:auto-activate-online')
    // LOAD 2026-10-08: off the :x6 OLT RX/correlate burst; still right after
    // the PPPoE session lanes (:x4/:x7) so is_online is fresh. Same 15 min cadence.
    ->cron('13,28,43,58 * * * *')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping(14)
    ->runInBackground()
    ->description('Auto-activate PSB customers online on OLT + PPPoE (fresh)');

// Safety net for PSB OCR: when ProcessPsbMediaOcrJob times out, the post-OCR
// continuation is never queued and the draft stays at 'received'/ocr_pending.
// Runs in every scheduler mode (monitoring included) like billing:generate.
Schedule::command('psb:recover-stuck-ocr --apply')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->runInBackground()
    ->description('Recover PSB drafts stuck after OCR (re-queue post-OCR continuation)');

// Owner 2026-10-11: wa_chat_signals retention is 18 months. Weekly, chunked
// delete of older rows (logs counts). Runs in every scheduler mode like
// psb:recover-stuck-ocr; Sunday 03:43 Jakarta is off every 5-minute boundary
// and the weekly cadence never lands in the stagger contract's sample hour.
Schedule::command('wa-signals:prune --months=18 --apply')
    ->weeklyOn(0, '03:43')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping(30)
    ->onOneServer()
    ->runInBackground()
    ->description('Weekly: prune work wa_chat_signals older than 18 months (chunked)');

// PSB WA: command jalan tiap jam; jendela & jarak rekap diatur di Settings
// (psb_recap_start_hour/end_hour/interval_hours, default 09-21 WIB tiap 2 jam).
// Di dalam command: skip di luar jadwal / jika 0 draft / data tidak berubah.
// Owner 2026-10-14: runs in every scheduler mode (prod = monitoring) so the
// auto recap actually fires. Only the recap: psb:poll-sn and the technician
// progress entries stay full-only. :07 keeps it off the 5-minute boundaries.
Schedule::command('psb:hourly-report')
    ->hourlyAt(7)
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping(10)
    ->onOneServer()
    ->runInBackground()
    ->description('PSB WA: rekap otomatis sesuai jadwal Settings (default 09-21 WIB tiap 2 jam, hanya jika data berubah)');

// Skynet Report WA group: yesterday's PSB + migration report at 08:03 WIB, hourly catch-up until
// 11:03 (DB ledger = once per day per group). Top-level on purpose (prod runs the monitoring
// scheduler mode). No-op until report_daily_enabled=1 AND report_wa_group_ids is set. :03 keeps
// it off the :x0/:x5 boundaries (SchedulerStaggerContractTest).
Schedule::command('report:daily-wa')
    ->cron('3 8-11 * * *')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping(30)
    ->onOneServer()
    ->runInBackground()
    ->description('Skynet Report WA: laporan harian kemarin 08:03 WIB (catch-up s/d 11:03, sekali per hari per grup)');

// Online payment reconciliation (Duitku pre-live). Read-only totals digest of
// yesterday to the Skynet Report group, 08:17 WIB with one catch-up at 09:17 (:13 was at the stagger cap)
// (ledger report_key pay_recon = once per day per group). No-op until
// payments_recon_daily_enabled=1 AND report_wa_group_ids is set. Hours 8-9 only,
// so the stagger contract's sample hour (10) is untouched.
Schedule::command('payments:reconcile-daily')
    ->cron('17 8,9 * * *')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping(30)
    ->onOneServer()
    ->runInBackground()
    ->description('Pembayaran online: rekon harian kemarin 08:17 WIB ke grup Skynet Report (read-only, flag payments_recon_daily_enabled)');

// Read-only toward MikroTik (getProfiles only). Top level (not inside $fullSchedulerEnabled) so it also
// runs in safe/monitoring mode on prod; otherwise display speeds go stale.
Schedule::command('routers:sync-profiles-draft')
    ->dailyAt('12:30')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground()
    ->description('Auto-sync PPP profile semua MikroTik ke Package draft (tanpa menimpa harga/nama operator)');

if ($fullSchedulerEnabled) {
    // Scheduled Commands
    Schedule::command('pppoe:blocks-reconcile --only-active --timeout=18')
        ->dailyAt('06:20')
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(20)
        ->runInBackground()
        ->description('Read-only reconcile PPPoE static pool counters from MikroTik secrets');

    // Legacy ticket:report-wa is intentionally not scheduled; technician progress uses the new 12:00/17:00/dynamic format.

    // Technician progress automation. Default setting keeps this in dry-run/no-send until Tiar enables it.
    Schedule::command('technician-progress:scrape-legacy --materialize')
        ->dailyAt('20:50')
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(10)
        ->runInBackground()
        ->description('Read legacy ticket/PSB scraper and store draft technician auto-plan tasks');

    Schedule::command('technician-progress:daily --mode=preview')
        ->dailyAt('21:00')
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(10)
        ->description('Preview jadwal teknisi besok (dry-run by default)');

    Schedule::command('technician-progress:daily --mode=morning')
        ->dailyAt('08:00')
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(10)
        ->description('Kirim jadwal teknisi 08:00 only when live toggles allow');

    Schedule::command('technician-progress:daily --mode=summary --at=12:00')
        ->dailyAt('12:00')
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(10)
        ->description('Rekap progress teknisi 12:00 only when live toggles allow');

    Schedule::command('technician-progress:daily --mode=summary --at=17:00')
        ->dailyAt('17:00')
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(10)
        ->description('Rekap progress teknisi 17:00 only when live toggles allow');

    Schedule::command('technician-progress:daily --mode=summary --at=20:30 --if-active-since=17:00')
        ->dailyAt('20:30')
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(10)
        ->description('Rekap malam only when PSB/deploy/maintenance masih bergerak setelah 17:00');

    // MikroTik Online/Secret PRIMARY: skynet-ppp-exporter (count-only, sequential, light).
    // Part of mktxp stack — does not parallel-hammer API (safe for Krian-class pools).
    Schedule::command('routers:import-ppp-metrics')
        ->everyTwoMinutes()
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(2)
        ->runInBackground()
        ->when($monitoringScheduleGate)
        ->description('MikroTik Online/Secret from mktxp PPP count exporter');

    // Plan C / C0: T0 pulse → router_mt_snapshots shadow (does NOT replace Plan B UI).
    Schedule::command('routers:mt-pulse-ingest --source=exporter')
        ->everyMinute()
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(1)
        ->runInBackground()
        ->when(fn (): bool => (bool) config('services.mt_plan_c.enabled', true)
            && $monitoringScheduleGate())
        ->description('Plan C T0 pulse shadow (router_mt_snapshots)');

    // MikroTik LIVE secondary: CPU/uptime only (Plan C skips Online write when enabled).
    Schedule::command('routers:live-health --concurrency=2 --timeout=18 --no-customer-flags')
        ->everyFiveMinutes()
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(4)
        ->runInBackground()
        ->when($legacyMonitoringSchedule)
        ->description('MikroTik LIVE secondary: resource/health (light; Online via Plan C pulse)');

    // Plan C / C2: T1 small pools often; large (Krian/Randuagung) less often
    Schedule::command('routers:mt-session-sync --only-active --skip-large --timeout=18')
        ->cron('1,4,7,10,13,16,19,22,25,28,31,34,37,40,43,46,49,52,55,58 * * * *')
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(10)
        ->runInBackground()
        ->when(fn (): bool => (bool) config('services.mt_plan_c.enabled', true)
            && (bool) config('services.mt_plan_c.sessions_enabled', true)
            && $monitoringScheduleGate())
        ->description('Plan C T1: is_online small routers');

    Schedule::command('routers:mt-session-sync --only-active --only-large --timeout=28')
        ->cron('8,23,38,53 * * * *')
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(20)
        ->runInBackground()
        ->when(fn (): bool => (bool) config('services.mt_plan_c.enabled', true)
            && (bool) config('services.mt_plan_c.sessions_enabled', true)
            && $monitoringScheduleGate())
        ->description('Plan C T1: is_online large routers (Krian-class)');

    // Plan C / C3: T2 secrets diff — priority dirty, limit 6 per tick to avoid long locks
    Schedule::command('routers:mt-secrets-diff --only-active --limit=6 --timeout=40')
        ->everyFiveMinutes()
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(25)
        ->runInBackground()
        ->when(fn (): bool => (bool) config('services.mt_plan_c.enabled', true)
            && (bool) config('services.mt_plan_c.secrets_diff_enabled', true)
            && $monitoringScheduleGate())
        ->description('Plan C T2: secrets diff priority (limit 6)');

    // Fallback full secrets-map (queue) hourly — safety net if hash path stuck
    Schedule::command('routers:secrets-map --stagger_ms=2500 --only-active')
        ->hourlyAt(17)
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(40)
        ->runInBackground()
        ->when($monitoringScheduleGate)
        ->description('MikroTik READ fallback: hourly fullSync secrets map');

    Schedule::command('customers:reconcile-mikrotik-missing --apply --fix-pppoe-typos --max-age-days=45')
        ->hourlyAt(57)
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(10)
        ->runInBackground()
        ->description('Reconcile stale missing customers from router staged secrets');

    // Legacy eBilling scraper (server 102) — daily customer master sync before MikroTik scan.
    Schedule::command('sync:legacy')
        ->dailyAt('01:00')
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(90)
        ->runInBackground()
        ->description('Daily legacy scraper sync: areas, packages, customers, invoices from server 102');

    // MikroTik customer scan — runs daily at 02:00 WIB to import new customers into eBilling.
    // Uses timeout=30 to skip slow/offline routers gracefully.
    Schedule::command('routers:scan --timeout=30')
        ->dailyAt('02:00')
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(60)
        ->description('Daily MikroTik customer scan: sync new customers from all active routers into eBilling');

    // psb:auto-apply schedule removed (2026-10-14): no such command exists.

    Schedule::command('audit:https-config')
        ->dailyAt('06:00')
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(5)
        ->description('Audit APP_URL/route HTTPS — cegah mixed-content regression');

    Schedule::command('audit:wa-gateway-config')
        ->dailyAt('06:05')
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(5)
        ->description('Audit WA gateway + CSRF — cegah broadcast/QR regression setelah redeploy');

    // WA session heal — soft reconnect + alert if QR needed (prevents absensi "tidak detek konek")
    Schedule::command('wa:heal --quiet-ok')
        ->everyFiveMinutes()
        ->timezone('Asia/Jakarta')
        ->between('5:00', '23:00')
        ->withoutOverlapping(4)
        ->runInBackground()
        ->description('Heal Evolution WA session; alert ops if needs QR; catch-up absensi after recovery');

    Schedule::command('monitoring:dispatch-lanes')
        ->everyTwoMinutes()
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(3)
        ->runInBackground()
        ->when(fn (): bool => $monitoringDecompositionEnabled()
            && app(MonitoringAdmissionGate::class)->allows('dispatch_lanes_schedule'))
        ->description('Dispatch bounded router/OLT/ACS monitoring collectors when split lanes are enabled');

    Schedule::command('connectivity:scan-pon-mismatch')
        ->everyFiveMinutes()
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(10)
        ->runInBackground()
        ->when($oltCollectorSchedule)
        ->description('Scan PON offline + PPPoE online mismatches on ZTE C300');

    Schedule::command('connectivity:dispatch-alerts')
        ->cron('7,22,37,52 * * * *')
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(10)
        ->description('Dispatch debounced connectivity alerts to NOC WhatsApp group (every 15m, max 5 ODP/run)');

    // Enterprise OLT — tiered (honest physics: RX tree ~125s on OLT RA, live must stay light)
    // LIVE: status-only every 2m (<15s). Keeps prior RX; never blocks on full walk.
    Schedule::command('olt:live-sync --olt_id=1')
        ->everyTwoMinutes()
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(1)
        ->runInBackground()
        ->when($oltCollectorSchedule)
        ->description('Enterprise live: SNMP status only (<15s) OLT RA');

    // READ fallback (in-container): every 10m. Primary is host cron host-olt-snmp-rx.sh (docker --network host, */3).
    Schedule::command('olt:snmp-sync')
        ->cron('2,12,22,32,42,52 * * * *')
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(9)
        ->runInBackground()
        ->when($oltCollectorSchedule)
        ->description('Fallback SNMP RX tree (host cron primary every 3m)');

    // HiOSO inventory+optical (OltHiosoService) every 15m, staggered — keeps Monitoring OLT Fresh.
    Schedule::command('olt:rx-sync --olt_id=2')
        ->cron('3,18,33,48 * * * *')
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(14)
        ->runInBackground()
        ->when($oltCollectorSchedule)
        ->description('HiOSO inventory+RX: Mangliawan');

    // RCA-1: no OLT #3 entries any more (it does not exist on prod; Gadang is the Huawei OLT #11 and has its own
    // optical job). The freed :06/:21/:36/:51 slot is used by Srigading (#4) in the monitoring block below.
    Schedule::command('olt:rx-sync --olt_id=4')
        ->cron('9,24,39,54 * * * *')
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(14)
        ->runInBackground()
        ->when($oltCollectorSchedule)
        ->description('HiOSO inventory+RX: Srigading');

    Schedule::command('olt:rx-sync --olt_id=8')
        ->cron('12,27,42,57 * * * *')
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(14)
        ->runInBackground()
        ->when($oltCollectorSchedule)
        ->description('HiOSO inventory+RX: Martopuro');

    // INVENTORY tier: state + serial + names hourly, staggered per OLT
    if ($oltInventoryRaViaSnmp) {
        Schedule::command('olt:inventory-sync --olt_id=1 --source=snmp --timeout='.$oltInventoryBudget)
            ->cron($oltInventoryRaSnmpCron)
            ->timezone('Asia/Jakarta')
            ->withoutOverlapping(14)
            ->runInBackground()
            ->when($oltCollectorSchedule)
            ->description('Enterprise inventory sync: OLT RA');

        Schedule::command('olt:inventory-sync --olt_id=1 --source=ssh --timeout='.$oltInventoryBudget)
            ->cron($oltInventoryRaSshCron)
            ->timezone('Asia/Jakarta')
            ->withoutOverlapping(30)
            ->runInBackground()
            ->when($oltCollectorSchedule)
            ->description('Enterprise inventory sync: OLT RA (SSH cross-check)');
    } else {
        Schedule::command('olt:inventory-sync --olt_id=1 --timeout='.$oltInventoryBudget)
            ->cron($oltInventoryRaCron)
            ->timezone('Asia/Jakarta')
            ->withoutOverlapping(30)
            ->runInBackground()
            ->when($oltCollectorSchedule)
            ->description('Enterprise inventory sync: OLT RA');
    }

    Schedule::command('olt:inventory-sync --olt_id=2')
        ->hourlyAt(10)
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(55)
        ->runInBackground()
        ->when($oltCollectorSchedule)
        ->description('Enterprise inventory sync: OLT Mangliawan');

    Schedule::command('olt:inventory-sync --olt_id=4')
        ->hourlyAt(20)
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(55)
        ->runInBackground()
        ->when($oltCollectorSchedule)
        ->description('Enterprise inventory sync: OLT Srigading');

    Schedule::command('olt:inventory-sync --olt_id=8')
        ->hourlyAt(40)
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(55)
        ->runInBackground()
        ->when($oltCollectorSchedule)
        ->description('Enterprise inventory sync: OLT Martopuro');

    // Residual RX auto: system finds online+N/A itself → CLI attenuation (no manual ONU list).
    // Also runs after host SNMP import when gaps remain (OltSnmpImportJson).
    Schedule::command('olt:fhtt-rx-backfill --olt_id=1')
        ->cron('5,20,35,50 * * * *')
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(12)
        ->runInBackground()
        ->when($oltCollectorSchedule)
        ->description('Auto residual RX: online N/A → CLI attenuation OLT RA');

    // Collector health + weak RX monitoring (enterprise OLTs)
    Schedule::command('olt:collect-health')
        ->everyThirtyMinutes()
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(10)
        ->runInBackground()
        ->when($oltCollectorSchedule)
        ->description('Enterprise OLT collector health summary');

    Schedule::command('olt:weak-rx-scan')
        ->cron('10,40 * * * *')
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(10)
        ->runInBackground()
        ->when($oltCollectorSchedule)
        ->description('Enterprise OLT weak RX alert scan');

    Schedule::command('genieacs:sync')
        ->cron('25,55 * * * *')
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(20)
        ->runInBackground()
        ->when($legacyMonitoringSchedule)
        ->description('Auto-sync devices from GenieACS server');

    // Fill empty ACS tags from customer PPPoE or OLT onu_name (RT/RW/ODP) — catch devices not linked yet
    Schedule::command('genieacs:fill-empty-tags --limit=80')
        ->everyFifteenMinutes()
        ->timezone('Asia/Jakarta')
        ->between('5:00', '23:00')
        ->withoutOverlapping(15)
        ->runInBackground()
        ->when($legacyMonitoringSchedule)
        ->description('Fill empty GenieACS tags from PPPoE or olt_onus.onu_name');

    // Attendance catch-up — last 3 days (covers missed webhook / FileBrowser outage).
    // Requires services.nextcloud.pass (NEXTCLOUD_PASS) mapped in config/services.php.
    // every 15 min during work window so absensi photos appear quickly after WA reconnect.
    Schedule::command('attendance:sync-today --days=3 --limit=300')
        ->everyFifteenMinutes()
        ->timezone('Asia/Jakarta')
        ->between('5:00', '22:00')
        ->withoutOverlapping(20)
        ->runInBackground()
        ->description('Catch-up WA attendance photos (last 3 days WIB)');

    // Warm the monitoring dashboard cache — was everyMinute without overlap (queue pile-up + MySQL pressure).
    Schedule::job(new RefreshMonitoringCacheJob)
        ->cron('3-59/5 * * * *') // off the :x0/:x5 boundaries; payload TTL is 6 min
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(5)
        ->when($monitoringDashboardGate)
        ->description('Pre-warm NOC monitoring dashboard cache');

    Schedule::command('monitoring:traffic-purge')
        ->hourlyAt(23)
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(20)
        ->runInBackground()
        ->when(fn (): bool => (bool) config('services.monitoring.traffic.enabled', false)
            && app(MonitoringAdmissionGate::class)->allows('traffic_purge'))
        ->description('Chunked retention purge for dedicated customer traffic tables');

    Schedule::command('customers:refresh-service-kpi --limit=500')
        ->everyTenMinutes()
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(8)
        ->runInBackground()
        ->when($monitoringScheduleGate)
        ->description('Recompute service_online = PON + PPPoE + GenieACS ping');

    Schedule::command('customers:probe-internet --limit=3')
        ->everyFifteenMinutes()
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(20)
        ->runInBackground()
        ->when($legacyMonitoringSchedule)
        ->description('GenieACS IP ping probe on FiberHome CPE (batched)');

    // Evolution ops alert: failed_jobs + queue depth.
    Schedule::command('ops:health-alert')
        ->everyTenMinutes()
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(5)
        ->runInBackground()
        ->description('Evolution ops alert: failed_jobs / queue depth');

    // PSB WA: poll uncfg for drafts waiting SN (FO plug-in)
    Schedule::command('psb:poll-sn')
        ->everyFiveMinutes()
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(4)
        ->runInBackground()
        ->description('PSB WA: poll OLT uncfg for waiting SN');

    // psb:hourly-report is registered once above (all scheduler modes).
}

// billing:sync-invoices-legacy is intentionally NOT scheduled anymore (owner
// 2026-10-13): the legacy feed has been frozen since 2026-09-28 and each run only
// rewrote old unpaid invoices. The artisan command stays for manual use.

// Monitoring-only mode: refresh read-only snapshots without enabling the
// billing/PSB/migration schedules inside the full scheduler block above.
if ($monitoringSchedulerEnabled) {
    // Huawei MA5608T optical read lane is safe in monitoring mode: it only
    // walks SNMP optical OIDs; Huawei SSH remains the write path.
    Schedule::job(new SyncOltOpticalJob((int) config('services.huawei_olt_snmp.olt_id', 11)))
        ->cron('1-59/5 * * * *')
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(4)
        ->when($huaweiOpticalScheduleGate)
        ->description('Huawei Gadang optical RX/distance via read-only SNMP');

    // M-4c-1 (owner approved 2026-10-11): per-PPPoE-user daily usage from
    // Prometheus into pppoe_usage_daily. ~13 routers x 2 instant queries per
    // run, off the request path, behind the same admission gate as the other
    // monitoring collectors (a closed gate just defers; the next run catches up
    // and self-finalizes yesterday, bounded). Off the 5-minute boundaries
    // (SchedulerStaggerContractTest), so finalize is 00:07 not 00:05, and off
    // the :x6 OLT/correlate burst (:14/:29/:44/:59 carry the fewest entries).
    // Backfill is never scheduled: operator runs --backfill-from by hand.
    Schedule::command('monitoring:pppoe-usage-rollup')
        ->cron('14,29,44,59 * * * *')
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(14)
        ->onOneServer()
        ->runInBackground()
        ->when($monitoringScheduleGate)
        ->description('Monitoring: daily PPPoE usage rollup from Prometheus (15 min)');

    Schedule::command('monitoring:pppoe-usage-rollup --finalize')
        ->dailyAt('00:07')
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(14)
        ->onOneServer()
        ->runInBackground()
        ->when($monitoringScheduleGate)
        ->description('Monitoring: finalize yesterday PPPoE usage (WIB)');
}

if ($monitoringSchedulerEnabled && ! $fullSchedulerEnabled) {
    // STAGGER 2026-10-06: VM102 sits on an overcommitted Proxmox host (~30%
    // CPU steal, up to ~70% during bursts). Every */5, */10 and */30 entry used
    // to fire on the same minute (:00 had 13 events), so the burst itself drove
    // steal up. Frequencies are unchanged; only the start minute is offset so
    // no minute carries more than 5 events. Keep new entries off :00/:x0/:x5
    // unless they are business-critical (billing, PSB). Guarded by
    // SchedulerStaggerContractTest.
    $monitoringQueuesClear = static function (): bool {
        try {
            return app(MonitoringAdmissionGate::class)->allows('scheduler');
        } catch (Throwable) {
            return false;
        }
    };

    $monitoringJob = static function (string $lane, string $command, array $parameters, string $target, ?int $timeoutSeconds = null): RunMonitoringCollectorJob {
        return new RunMonitoringCollectorJob($lane, $command, $parameters, $target, $timeoutSeconds);
    };

    Schedule::job($monitoringJob('router', 'routers:live-health', [
        '--only-active' => true,
        '--no-customer-flags' => true,
        '--concurrency' => 2,
        '--timeout' => 8,
    ], 'router-health'))
        ->cron('2-59/5 * * * *')
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(8)
        ->when($monitoringQueuesClear)
        ->description('Monitoring-only: bounded MikroTik health snapshot');

    // Session lanes stay --read-only for customer rows, but --write-online-flags
    // keeps customers.is_online + pppoe_online_checked_at fresh (audit
    // 2026-10-08: is_online froze at the last manual "Sync router"). Offline is
    // only written from a complete name dump; kill-switch:
    // MT_PLAN_C_MONITORING_WRITE_ONLINE=false. Both lanes run every 10 min so
    // the PPPoE signal can stay inside ONLINE_PPP_MAX_AGE_MINUTES (15).
    Schedule::job($monitoringJob('router', 'routers:mt-session-sync', [
        '--only-active' => true,
        '--skip-large' => true,
        '--read-only' => true,
        '--write-online-flags' => true,
        '--timeout' => 12,
    ], 'router-sessions-small'))
        ->cron('7-59/10 * * * *')
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(12)
        ->when($routerSessionCollectorSchedule)
        ->description('Monitoring-only: bounded active PPPoE session snapshot');

    Schedule::job($monitoringJob('router', 'routers:mt-session-sync', [
        '--only-active' => true,
        '--only-large' => true,
        '--read-only' => true,
        '--write-online-flags' => true,
        '--timeout' => 12,
    ], 'router-sessions-large'))
        ->cron('4-59/10 * * * *')
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(9)
        ->when($routerSessionCollectorSchedule)
        ->description('Monitoring-only: staggered large-router PPPoE snapshot');

    // OLT collector lane: core NOC truth (state + RX + inventory). Uses the
    // isolated olt lane, which bypasses the host-load admission gate (operator
    // hold + DB availability still apply) so a busy host cannot silently
    // freeze optical data. RX for OLT RA keeps the host cron primary path.
    Schedule::job($monitoringJob('olt', 'olt:live-sync', [], 'olt-live'))
        ->cron('4-59/5 * * * *')
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(8)
        ->when($oltCollectorSchedule)
        ->description('Monitoring OLT: fast state snapshot (ZTE)');

    Schedule::job($monitoringJob('olt', 'olt:snmp-sync', ['--olt_id' => 1], 'olt-rx-ra'))
        ->cron('2,17,32,47 * * * *')
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(14)
        ->when($oltCollectorSchedule)
        ->description('Monitoring OLT RX: OLT RA (SNMP)');

    Schedule::job($monitoringJob('olt', 'olt:snmp-sync', ['--olt_id' => 9], 'olt-rx-krian'))
        ->cron('4,19,34,49 * * * *')
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(14)
        ->when($oltCollectorSchedule)
        ->description('Monitoring OLT RX: OLT Krian (SNMP)');

    Schedule::job($monitoringJob('olt', 'olt:fhtt-rx-backfill', ['--olt_id' => 1], 'olt-rx-residual-ra'))
        ->cron('6,21,36,51 * * * *')
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(13)
        ->when($oltCollectorSchedule)
        ->description('Monitoring OLT residual RX: OLT RA');

    Schedule::job($monitoringJob('olt', 'olt:rx-sync', ['--olt_id' => 2], 'olt-rx-hioso-2'))
        ->cron('3,18,33,48 * * * *')
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(14)
        ->when($oltCollectorSchedule)
        ->description('Monitoring OLT RX: HiOSO Mangliawan');

    // RCA-1: OLT #4 (HiOSO Srigading) joins the prod monitoring schedule (it was only in the full block, so its
    // data stopped on 2026-07-09 and 3 of 5 real incident tickets came from an unmonitored area). It takes the
    // slot of the removed OLT #3 entry (no such OLT on prod), so no minute gets busier.
    Schedule::job($monitoringJob('olt', 'olt:rx-sync', ['--olt_id' => 4], 'olt-rx-hioso-4', 240))
        ->cron('6,21,36,51 * * * *')
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(14)
        ->when($oltCollectorSchedule)
        ->description('Monitoring OLT RX: HiOSO Srigading');

    Schedule::job($monitoringJob('olt', 'olt:rx-sync', ['--olt_id' => 8], 'olt-rx-hioso-8'))
        ->cron('9,24,39,54 * * * *')
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(14)
        ->when($oltCollectorSchedule)
        ->description('Monitoring OLT RX: HiOSO Martopuro');

    if ($oltInventoryRaViaSnmp) {
        Schedule::job($monitoringJob('olt', 'olt:inventory-sync', [
            '--olt_id' => 1,
            '--timeout' => $oltInventoryBudget,
            '--source' => 'snmp',
        ], 'olt-inv-ra', $oltInventoryJobTimeout))
            ->cron($oltInventoryRaSnmpCron)
            ->timezone('Asia/Jakarta')
            ->withoutOverlapping(14)
            ->when($oltCollectorSchedule)
            ->description('Monitoring OLT inventory: OLT RA');

        Schedule::job($monitoringJob('olt', 'olt:inventory-sync', [
            '--olt_id' => 1,
            '--timeout' => $oltInventoryBudget,
            '--source' => 'ssh',
        ], 'olt-inv-ra-ssh', $oltInventoryJobTimeout))
            ->cron($oltInventoryRaSshCron)
            ->timezone('Asia/Jakarta')
            ->withoutOverlapping(30)
            ->when($oltCollectorSchedule)
            ->description('Monitoring OLT inventory: OLT RA (SSH cross-check)');
    } else {
        Schedule::job($monitoringJob('olt', 'olt:inventory-sync', [
            '--olt_id' => 1,
            '--timeout' => $oltInventoryBudget,
        ], 'olt-inv-ra', $oltInventoryJobTimeout))
            ->cron($oltInventoryRaCron)
            ->timezone('Asia/Jakarta')
            ->withoutOverlapping(30)
            ->when($oltCollectorSchedule)
            ->description('Monitoring OLT inventory: OLT RA');
    }

    Schedule::job($monitoringJob('olt', 'olt:inventory-sync', ['--olt_id' => 9], 'olt-inv-krian'))
        // LOAD 2026-10-08: was :05 (host */5 cron + PSB OCR boundary, and one
        // minute after Krian RX at :04). :53 is a quiet minute.
        ->hourlyAt(53)
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(55)
        ->when($oltCollectorSchedule)
        ->description('Monitoring OLT inventory: OLT Krian');

    Schedule::job($monitoringJob('olt', 'olt:inventory-sync', ['--olt_id' => 2], 'olt-inv-hioso-2'))
        ->hourlyAt(12)
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(55)
        ->when($oltCollectorSchedule)
        ->description('Monitoring OLT inventory: HiOSO Mangliawan');

    Schedule::job($monitoringJob('olt', 'olt:inventory-sync', ['--olt_id' => 4], 'olt-inv-hioso-4', 240))
        ->hourlyAt(22)
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(55)
        ->when($oltCollectorSchedule)
        ->description('Monitoring OLT inventory: HiOSO Srigading');

    Schedule::job($monitoringJob('olt', 'olt:inventory-sync', ['--olt_id' => 8], 'olt-inv-hioso-8'))
        ->hourlyAt(32)
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(55)
        ->when($oltCollectorSchedule)
        ->description('Monitoring OLT inventory: HiOSO Martopuro');

    Schedule::command('olt:weak-rx-scan')
        ->cron('12,42 * * * *')
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(10)
        ->runInBackground()
        ->when($oltCollectorSchedule)
        ->description('Monitoring OLT weak RX alert scan');

    Schedule::job($monitoringJob('acs', 'genieacs:sync', [], 'genieacs-snapshot'))
        ->cron('8-59/10 * * * *')
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(12)
        ->when($monitoringQueuesClear)
        ->description('Monitoring-only: bounded GenieACS snapshot');

    Schedule::job(new RunMonitoringCollectorJob(
        'evaluate',
        'connectivity:scan-pon-mismatch',
        [],
        'pon-correlation',
    ))
        ->cron('26,56 * * * *')
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(20)
        ->when($monitoringQueuesClear)
        ->description('Monitoring-only: correlate ODC/PON/PPPoE incidents');

    Schedule::job(new RefreshMonitoringCacheJob)
        ->cron('3-59/5 * * * *') // off the :x0/:x5 boundaries; payload TTL is 6 min
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(5)
        ->when($monitoringDashboardGate)
        ->description('Monitoring-only: pre-warm NOC dashboard read model');
}

if (! $fullSchedulerEnabled) {
    // Billing enforcement is intentionally safe in this block: the command
    // remains a dry-run while the setting is true, but its schedule must exist
    // even when heavy device collectors stay disabled.
    Schedule::command('ops:health-alert')
        ->cron('9-59/10 * * * *')
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(5)
        ->runInBackground()
        ->description('Safe scheduler: private ops health only');

    Schedule::command('wa:heal --quiet-ok')
        ->cron('1-59/5 * * * *')
        ->timezone('Asia/Jakarta')
        ->between('5:00', '23:00')
        ->withoutOverlapping(4)
        ->runInBackground()
        ->description('Safe scheduler: keep Evolution WA state observable');
}

// Roadmap 2.4 PR-2: shadow outage detector. Top level (not inside $fullSchedulerEnabled) so it also
// runs under EBILLING_SCHEDULER_MODE=monitoring. Default OFF: OutageScheduleGate needs the
// outage_automation_enabled setting AND the monitoring hold to be clear, and never throws. It only
// reads collected tables and writes outage_* rows (no OLT/router I/O, no tickets, no customer WA).
// :x3/:x8 minutes keep the :x0/:x5 boundaries free (SchedulerStaggerContractTest).
if ($monitoringSchedulerEnabled) {
    Schedule::command('outage:detect')
        ->cron('3-59/5 * * * *')
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(4)
        ->onOneServer()
        ->runInBackground()
        ->when(static fn (): bool => OutageScheduleGate::shouldRun())
        ->description('Monitoring-only: outage shadow detector (2.4, record only)');
}

// RCA-2: 400 day retention (config outage.events_retention_days) of the diagnosis history tables
// (onu_state_events, cpe_events, pppoe_session_events, onu_rx_daily, pppoe_session_presence).
// DB only. 03:37 WIB is not a 5 minute boundary, :41, :16/:46 or an auto-activate / rollup minute.
if ($monitoringSchedulerEnabled) {
    Schedule::command('outage:events-prune')
        ->dailyAt('03:37')
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(60)
        ->onOneServer()
        ->runInBackground()
        ->description('Monitoring-only: prune diagnosis event history older than 400 days');

    // History archive (roadmap v2 section 9), off-peak, DB / read-only HTTP only (no OLT or router access):
    // - onu_rx_daily: 23:52 sees today's raw values (min / max / last), 01:12 fills what the tracker still holds
    //   of the days before (the tracker keeps only 8 days).
    // - pppoe_session_presence: 01:23 reads yesterday (and the day before if unfinished) from Prometheus
    //   (VM107, ~40 days retention). Soft-fails; the next night catches up.
    Schedule::command('outage:rx-archive')
        ->cron('52 23 * * *')
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(30)
        ->onOneServer()
        ->runInBackground()
        ->description('Monitoring-only: archive per-ONU daily RX (today)');
    Schedule::command('outage:rx-archive')
        ->dailyAt('01:12')
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(30)
        ->onOneServer()
        ->runInBackground()
        ->description('Monitoring-only: archive per-ONU daily RX (catch-up)');
    // PR-D: session bursts + area power groups of the last 15 minutes (DB only: reads pppoe_session_events / onu_state_events,
    // writes only outage_signals rows of type session_burst / area_power; < 3 s). The signal rows are always written, the
    // diagnosis lines have their own flags (default off). Never on a queue. Minute :x1 keeps :x0/:x5, :x3/:x8 (outage:detect),
    // :41, :16/:46 free (SchedulerStaggerContractTest). Every 10 minutes (:01, :11, ...): no minute residue of a 5 minute
    // cadence has room left under the per-minute cap; the 15 minute look-back overlaps the previous run, so every minute is read
    // once or twice and there is no gap unless a run is skipped. Gated like outage:detect: the outage_automation_enabled kill
    // switch and the monitoring operator hold stop it too.
    Schedule::command('outage:session-bursts')
        ->cron('1-59/10 * * * *')
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(4)
        ->onOneServer()
        ->runInBackground()
        ->when(static fn (): bool => OutageScheduleGate::shouldRun())
        ->description('Monitoring-only: PPPoE session bursts and area power groups (PR-D, DB only)');
    Schedule::command('diagnosis:presence-archive --budget-minutes=40')
        ->dailyAt('01:23')
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(120)
        ->onOneServer()
        ->runInBackground()
        ->description('Monitoring-only: archive PPPoE session gaps from Prometheus');
}

// ---- RCA-4 (diagnosis) ----
// Every 15 minutes: a fresh stored diagnosis of each open incident ticket (cap 200). DB only (no router / OLT /
// ACS I/O, nothing queued). :9/:24/:39/:54 are not 5-minute boundaries, not :41, not :16/:46 (stagger contract).
// Flag diagnosis_enabled = 0 makes the command a no-op.
if ($monitoringSchedulerEnabled) {
    Schedule::command('outage:diagnose-open')
        ->cron('9,24,39,54 * * * *')
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(10)
        ->onOneServer()
        ->runInBackground()
        ->description('Monitoring-only: refresh stored diagnosis of open incident tickets (RCA-4, DB only)');

    // Monday 08:12 WIB: precision of last week's diagnoses (page card; WA only if diagnosis_weekly_wa_enabled = 1).
    Schedule::command('outage:diagnosis-precision')
        ->weeklyOn(1, '08:12')
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(30)
        ->onOneServer()
        ->runInBackground()
        ->description('Monitoring-only: weekly precision report of the outage diagnosis (RCA-4)');

    // Learn & measure: hourly outcome labels (what the stored data says fixed a closed incident). DB only: indexed
    // reads, writes outage_outcome_labels only, at most 500 subjects (< 20 s). Not on any queue. :11 is not a
    // 5-minute boundary, not :41, not :16/:46, and not :23 (the presence archive starts at 01:23). diagnosis_outcome_labels_enabled = 0 makes it a no-op.
    Schedule::command('outage:outcome-label')
        ->cron('11 * * * *')
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(10)
        ->onOneServer()
        ->runInBackground()
        ->description('Monitoring-only: outcome labels of closed incidents from stored data (DB only)');

    // Clears the context snapshot of detector episodes older than 180 days (the diagnosis rows stay). DB only.
    Schedule::command('outage:diagnosis-context-prune')
        ->dailyAt('03:52')
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(30)
        ->onOneServer()
        ->runInBackground()
        ->description('Monitoring-only: clear auto_episode diagnosis snapshots older than 180 days');
}
// ---- end RCA-4 ----

// ===== RCA-3 (CPE layer): BEGIN =====
// 5-minute watch of symptomatic ONUs. Default OFF (setting cpe_watch_enabled=0): `when()` keeps the process from
// even starting. It queues getParameterValues WITHOUT connection_request and reads the answers back; DB + GenieACS
// NBI only, no queue job (nothing is dispatched onto psb / migration-* / wa). :2/:7/... keeps :x0/:x5, :x3/:x8
// (outage:detect), :41, :16/:46 free (SchedulerStaggerContractTest).
if ($monitoringSchedulerEnabled) {
    Schedule::command('cpe:watch')
        ->cron('2-59/5 * * * *')
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(4)
        ->onOneServer()
        ->runInBackground()
        ->when(static fn (): bool => OutageSettings::cpeWatchEnabled())
        ->description('Monitoring-only: RCA-3 5-menit pantau ONU bermasalah (default mati)');
}
// ===== RCA-3 (CPE layer): END =====

// Roadmap 2.4 PR-4: daily shadow summary (counts only, no PII) to the technical log group
// outage_shadow_log_jid. Default OFF (outage_shadow_digest_enabled); the target is guarded (never PSB).
// 07:42 and a retry slot 09:42 WIB (a quiet window ending late or a failed send; the per-day key makes the
// second run a no-op once sent). Not a 5-minute boundary, not :14/:29/:44/:59, :41, :16/:46.
if ($monitoringSchedulerEnabled) {
    Schedule::command('outage:shadow-digest')
        ->cron('42 7,9 * * *')
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(30)
        ->onOneServer()
        ->runInBackground()
        ->when(static fn (): bool => OutageShadowDigest::shouldRun())
        ->description('Monitoring-only: outage shadow daily summary (2.4, log group, flag off by default)');
}

// Migration auto-reconcile (owner 2026-10-15): open migrations whose new ONU AND PPPoE are online are marked
// synced. Top level (not inside $fullSchedulerEnabled) so it runs under monitoring mode too. Report-only until
// the setting migration_auto_reconcile_apply=1; DB only, never a device/WA. :16/:46 avoid :x0/:x5, :41, the
// auto-activate minutes (:13/28/43/58) and the rollup minutes (SchedulerStaggerContractTest).
Schedule::command('migration:auto-reconcile')
    ->cron('16,46 * * * *')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping(25)
    ->onOneServer()
    ->runInBackground()
    ->description('Migrasi: tandai selesai otomatis bila ONU + PPPoE online (report-only sampai diizinkan)');

// Ops WA alert when VM102 is in trouble (CPU steal / GenieACS inform drop / stale snapshot). Top level so it
// exists in monitoring mode too. Mode is the Setting ops_vm_alert_mode (default off => the entry never runs;
// `ops:vm-alert --set-mode=log|on`). Scheduler process only: no queue, no PSB/migration/ops worker involved.
// :x6 keeps it off :x0/:x5 (SchedulerStaggerContractTest).
Schedule::command('ops:vm-alert')
    ->cron('6-59/10 * * * *')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping(8)
    ->onOneServer()
    ->runInBackground()
    ->when(static function (): bool {
        try {
            return VmAlertService::currentMode() !== 'off';
        } catch (Throwable) {
            return false;
        }
    })
    ->description('Ops: VM102 steal / GenieACS inform alert');

// Customer ONU field sync (owner 2026-10-15): daily 09:23 WIB, evaluate + apply olt_id / port label / (migration)
// SN from live olt_onus. Top level so it exists under monitoring mode too, but OFF until the owner sets
// customer_onu_sync_schedule_enabled=1 (default 0); customer_onu_sync_enabled is the master kill switch. DB only:
// never an OLT / MikroTik / GenieACS / WhatsApp call. :23 avoids :x0/:x5, :13/:28/:43/:58 (auto-activate),
// :14/:29/:44/:59 (rollup), :41, :16/:46 and the scan-pon-mismatch windows (SchedulerStaggerContractTest).
Schedule::command('customers:sync-onu-fields --scheduled')
    ->dailyAt('09:23')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping(30)
    ->onOneServer()
    ->runInBackground()
    ->when(static function (): bool {
        try {
            // same parsing as the command (filter_var), so "0"/"false"/"off" mean OFF in both places
            $s = app(CustomerOnuFieldSyncService::class)->settings();

            return $s['enabled'] && $s['schedule_enabled'];
        } catch (Throwable) {
            return false;
        }
    })
    ->description('Pelanggan: sinkron OLT/port/SN dari ONU (harian; nonaktif sampai diizinkan)');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
