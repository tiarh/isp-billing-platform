<?php

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AreaController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\Auth\TotpController;
use App\Http\Controllers\ClientArea\ClientAreaController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerCreditController;
use App\Http\Controllers\CustomerMigrationController;
use App\Http\Controllers\CustomerTrafficController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeviceRecoveryController;
use App\Http\Controllers\DiagnoseController;
use App\Http\Controllers\DismantleReasonReportController;
use App\Http\Controllers\FinanceBulkWaController;
use App\Http\Controllers\FinancePerAreaController;
use App\Http\Controllers\FinancePerAreaExportController;
use App\Http\Controllers\FinancePerAreaOperationsController;
use App\Http\Controllers\GenieAcsController;
use App\Http\Controllers\GenieAcsWebhookController;
use App\Http\Controllers\GlobalSearchController;
use App\Http\Controllers\HealthDeepController;
use App\Http\Controllers\HotspotVoucherController;
use App\Http\Controllers\InventarisController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\IsolatedController;
use App\Http\Controllers\MapsController;
use App\Http\Controllers\MonitoringController;
use App\Http\Controllers\MonitoringCpeHealthController;
use App\Http\Controllers\MonitoringRouterController;
use App\Http\Controllers\MonitoringSettingsController;
use App\Http\Controllers\MonitoringTicketController;
use App\Http\Controllers\MonitoringWidgetController;
use App\Http\Controllers\OdpOdcController;
use App\Http\Controllers\OltC300Controller;
use App\Http\Controllers\OltController;
use App\Http\Controllers\OltHuaweiController;
use App\Http\Controllers\OltVlanMappingController;
use App\Http\Controllers\OutageManualTicketController;
use App\Http\Controllers\OutageShadowController;
use App\Http\Controllers\PackageController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PaymentGatewayController;
use App\Http\Controllers\PaymentGatewaySettingsController;
use App\Http\Controllers\PaymentGatewayTransactionController;
use App\Http\Controllers\ProductionControlPlaneController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PsbDraftController;
use App\Http\Controllers\PsbImportController;
use App\Http\Controllers\PublicInvoiceController;
use App\Http\Controllers\PublicPaymentController;
use App\Http\Controllers\ResellerCommissionController;
use App\Http\Controllers\RouterController;
use App\Http\Controllers\RouterVlanController;
use App\Http\Controllers\SalesLeadController;
use App\Http\Controllers\SensitiveMediaController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\StorageProxyController;
use App\Http\Controllers\SupportTicketController;
use App\Http\Controllers\TechnicianProgressController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\UserManagementController;
use App\Http\Controllers\WaCampaignController;
use App\Http\Controllers\WaGroupSettingsController;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Router;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes - Skynet E-Billing
|--------------------------------------------------------------------------
|
| Routes organized by feature:
| - Dashboard (Accounting widgets)
| - Customer Management
| - Invoice Management
| - Payment Entry
| - Package Management
|
*/

// Redirect root to login
Route::get('/', function (Request $request) {
    // Dedicated client-area host (e.g. pay.example.test): land on the customer login.
    $home = (string) config('client_area.home_host');
    if (config('client_area.enabled') && $home !== '' && strtolower($request->getHost()) === $home) {
        return redirect()->route('client-area.login');
    }

    return redirect()->route('login');
});

// =====================================================
// Customer client area (/pelanggan) + legal pages.
// OFF unless CLIENT_AREA_ENABLED=true (404 otherwise).
// =====================================================
Route::middleware('client-area.enabled')->prefix('pelanggan')->group(function () {
    Route::get('/login', [ClientAreaController::class, 'showLogin'])
        ->name('client-area.login');
    Route::post('/login', [ClientAreaController::class, 'login'])
        ->name('client-area.login.submit');
    Route::post('/logout', [ClientAreaController::class, 'logout'])
        ->name('client-area.logout');
    Route::get('/', [ClientAreaController::class, 'dashboard'])
        ->name('client-area.dashboard');
});
Route::middleware('client-area.enabled:legal')->group(function () {
    Route::get('/syarat-ketentuan', [ClientAreaController::class, 'terms'])
        ->name('client-area.terms');
    Route::get('/kebijakan-refund', [ClientAreaController::class, 'refund'])
        ->name('client-area.refund');
});

// =====================================================
// Public Proxy for S3 Storage (MinIO)
// Serves files from MinIO using the local app domain
// =====================================================
// Anonymous: allowlisted folders only. Staff session: other non-protected folders.
// See StorageProxyController; the `storage-proxy` limiter is in AppServiceProvider.
Route::get('/storage/{path}', [StorageProxyController::class, 'show'])
    ->middleware('throttle:storage-proxy')
    ->where('path', '.*')
    ->name('storage.public');

// =====================================================
// Public Payment Routes
// =====================================================
Route::get('/isolated', [IsolatedController::class, 'index'])
    ->middleware('throttle:10,1')
    ->name('public.isolated');
Route::get('/isolated/status', [IsolatedController::class, 'status'])
    ->middleware('throttle:10,1')
    ->name('public.isolated.status');
// General isolation notice (no customer lookup, no payment).
// Throttle is per source IP; isolated customers share their router's NAT IP,
// so these use the generous `isolir-portal` limiter (AppServiceProvider).
Route::get('/isolir', [IsolatedController::class, 'general'])
    ->middleware('throttle:isolir-portal')
    ->name('public.isolir');
// Captive-portal entrypoint: MikroTik dst-nats isolated HTTP to the server, a
// Traefik catch-all redirects it here, and the app forwards to /isolir
// (general) or /isolated (per-customer) by setting.
// See docs/ISOLIR_PORTAL_MIKROTIK_SETUP.md.
Route::get('/isolated/captive', [IsolatedController::class, 'captive'])
    ->middleware('throttle:isolir-portal')
    ->name('public.isolated.captive');
Route::get('/isolated/pay/{uuid}', [PublicInvoiceController::class, 'show'])
    ->middleware(['signed', 'throttle:public-pay'])
    ->name('public.isolated.payment');
Route::get('/pay/{uuid}', [PublicInvoiceController::class, 'show'])
    ->middleware('throttle:public-pay')
    ->name('public.invoice.show');
Route::post('/pay/{uuid}/xendit', [PaymentGatewayController::class, 'payXendit'])
    ->middleware('throttle:public-pay')
    ->name('public.invoice.pay');
// Direct-API online payment (Duitku): list methods, start/reuse a charge, poll status.
Route::get('/pay/{uuid}/online/methods', [PublicPaymentController::class, 'methods'])
    ->middleware('throttle:public-pay-online')
    ->name('public.invoice.online.methods');
Route::post('/pay/{uuid}/online', [PublicPaymentController::class, 'start'])
    ->middleware('throttle:public-pay-online')
    ->name('public.invoice.online.start');
Route::get('/pay/{uuid}/online/status', [PublicPaymentController::class, 'status'])
    ->middleware('throttle:public-pay-status')
    ->name('public.invoice.online.status');
Route::post('/webhooks/payment-gateway/{provider}', [PaymentGatewayController::class, 'webhook'])
    ->middleware('throttle:payment-webhook')
    ->name('webhooks.payment-gateway');
Route::get('/invoices/{invoice}/receipt', [InvoiceController::class, 'publicReceipt'])
    ->middleware(['signed', 'throttle:60,1'])
    ->name('public.invoices.receipt');
Route::get('/api/monitoring/export', [MonitoringController::class, 'export'])
    ->middleware('throttle:api')
    ->name('api.monitoring.export');
Route::post('/api/internal/monitoring/traffic/current', [CustomerTrafficController::class, 'current'])
    ->middleware('throttle:api')
    ->name('api.internal.monitoring.traffic.current');
Route::post('/api/internal/monitoring/traffic/daily', [CustomerTrafficController::class, 'daily'])
    ->middleware('throttle:api')
    ->name('api.internal.monitoring.traffic.daily');
Route::get('/api/attendances/export', [AttendanceController::class, 'export'])
    ->middleware(['attendance-export', 'throttle:60,1'])
    ->name('api.attendances.export');
Route::post('/webhooks/attendance/evolution', [AttendanceController::class, 'evolutionWebhook'])
    ->middleware('throttle:api')
    ->name('webhooks.attendance.evolution');
// Alias: Evolution often configured as /webhooks/evolution/attendance (was 404)
Route::post('/webhooks/evolution/attendance', [AttendanceController::class, 'evolutionWebhook'])
    ->middleware('throttle:api')
    ->name('webhooks.evolution.attendance');
// GenieACS inform → instant upsert + auto-tag (ext/provision calls this)
Route::post('/webhooks/genieacs/device', [GenieAcsWebhookController::class, 'device'])
    ->middleware('throttle:120,1')
    ->name('webhooks.genieacs.device');
Route::post('/wg/push-profile', [RouterController::class, 'pushProfiles'])
    ->middleware('throttle:120,1')
    ->name('routers.profiles.push');
Route::get('/psb-wa/health-public', [PsbDraftController::class, 'publicHealth'])
    ->middleware('throttle:60,1')
    ->name('psb-drafts.health-public');
// Not public: global admin session or X-Health-Token (config ops.health.token); else 404.
// No throttle middleware: it is cache-backed and must still answer when Redis is down.
// Known limitation: the global-admin path authenticates through the session, so
// the route must keep the session/cookie middleware. With SESSION_DRIVER=redis
// and Redis down, StartSession throws before this controller runs (HTTP 500, not
// 503). Excluding the route from the session middleware would break the admin
// path, so monitors should use /up for liveness and the ops:health-alert
// Redis-down WhatsApp alert for Redis; a 5xx here still means "unhealthy".
Route::get('/health/deep', HealthDeepController::class)->name('health.deep');

// Authenticated Routes
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/media/customers/{customer}/ktp', [SensitiveMediaController::class, 'customerKtp'])
        ->middleware('signed')
        ->name('media.customers.ktp');
    Route::get('/media/customers/{customer}/odp', [SensitiveMediaController::class, 'customerOdp'])
        ->middleware('signed')
        ->name('media.customers.odp');
    Route::get('/media/transactions/{transaction}/proof', [SensitiveMediaController::class, 'transactionProof'])
        ->middleware(['admin', 'signed'])
        ->name('media.transactions.proof');
    Route::get('/media/tickets/{ticket}/photo/{index}', [SensitiveMediaController::class, 'ticketPhoto'])
        ->whereNumber('index')
        ->middleware('signed')
        ->name('media.tickets.photo');

    // =====================================================
    // Dashboard - Enhanced with Accounting Widgets
    // =====================================================
    Route::get('/dashboard', [DashboardController::class, 'index'])
        ->name('dashboard');

    // =====================================================
    // Profile Management
    // =====================================================
    Route::get('/profile', [ProfileController::class, 'edit'])
        ->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])
        ->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])
        ->name('profile.destroy');

    Route::middleware('superadmin')->group(function () {
        Route::resource('users', UserManagementController::class)->except(['show']);
        Route::post('/users/{user}/totp-reset', [TotpController::class, 'resetUser'])
            ->middleware('throttle:6,1')
            ->name('users.totp-reset');
        Route::get('/production-control-plane', [ProductionControlPlaneController::class, 'index'])
            ->name('production-control-plane.index');
    });

    // =====================================================
    // Customer Management
    // =====================================================
    Route::post('/api/global-search', GlobalSearchController::class)
        ->middleware('throttle:global-search')
        ->name('api.global-search');
    Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
    Route::middleware('admin')->group(function () {
        // H1b: export berisi NIK/phone/alamat — wajib admin (sebelumnya hanya auth)
        Route::get('/customers/export', [CustomerController::class, 'export'])
            ->middleware('throttle:customer-export')
            ->name('customers.export');
        Route::get('/api/customers/search', [CustomerController::class, 'search'])->name('api.customers.search');
        Route::get('/customers/create', [CustomerController::class, 'create'])->name('customers.create');
        Route::post('/customers', [CustomerController::class, 'store'])->name('customers.store');
        Route::post('/customers/bulk-package', [CustomerController::class, 'bulkUpdatePackage'])
            ->name('customers.bulk-package');
        Route::get('/customers/{customer}/edit', [CustomerController::class, 'edit'])->name('customers.edit');
        Route::match(['put', 'patch'], '/customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');
        Route::delete('/customers/{customer}', [CustomerController::class, 'destroy'])->name('customers.destroy');
        Route::post('/customers/{id}/restore', [CustomerController::class, 'restore'])->name('customers.restore');
        Route::post('/customers/{customer}/isolate', [CustomerController::class, 'isolate'])
            ->middleware('throttle:mikrotik-operations')
            ->name('customers.isolate');
        Route::post('/customers/{customer}/reconnect', [CustomerController::class, 'reconnect'])
            ->middleware('throttle:mikrotik-operations')
            ->name('customers.reconnect');
        Route::post('/customers/{customer}/pending-termination/cancel', [CustomerController::class, 'cancelPendingTermination'])
            ->name('customers.pending-termination.cancel');
    });
    Route::get('/customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');

    // =====================================================
    // Saleskit — calon pelanggan (prospek) per daerah + RT/RW.
    // Teknisi boleh menambah & melihat; edit/hapus dibatasi admin/creator.
    // =====================================================
    Route::get('/saleskit', [SalesLeadController::class, 'index'])->name('saleskit.index');
    Route::post('/saleskit', [SalesLeadController::class, 'store'])->name('saleskit.store');
    Route::post('/saleskit/import', [SalesLeadController::class, 'import'])->name('saleskit.import');
    Route::post('/saleskit/sync-installed', [SalesLeadController::class, 'syncInstalled'])->name('saleskit.sync-installed');
    Route::get('/saleskit/export.csv', [SalesLeadController::class, 'export'])->name('saleskit.export');
    Route::put('/saleskit/{salesLead}', [SalesLeadController::class, 'update'])->name('saleskit.update');
    Route::delete('/saleskit/{salesLead}', [SalesLeadController::class, 'destroy'])->name('saleskit.destroy');

    // =====================================================
    // PSB (grafik per bulan dari scraper + import cadangan + staging apply)
    // =====================================================
    Route::middleware('admin')->group(function () {
        Route::get('/psb-wa/{draft}/media/{kind}', [SensitiveMediaController::class, 'psbDraftPhoto'])
            ->whereIn('kind', ['ktp', 'router'])
            ->middleware('signed')
            ->name('psb-drafts.media');
        Route::get('/psb-imports', [PsbImportController::class, 'index'])->name('psb-imports.index');
        Route::post('/psb-imports/refresh', [PsbImportController::class, 'refresh'])->name('psb-imports.refresh');
        Route::post('/psb-imports/import', [PsbImportController::class, 'import'])->name('psb-imports.import');
        Route::post('/psb-imports/bulk-apply', [PsbImportController::class, 'bulkApply'])->name('psb-imports.bulk-apply');
        Route::post('/psb-imports/{id}/apply', [PsbImportController::class, 'apply'])->name('psb-imports.apply');
        Route::put('/psb-imports/{id}/mapping', [PsbImportController::class, 'updateMapping'])->name('psb-imports.update-mapping');

        // PSB WA bot — UI lives under Aktivasi tab; these routes are API/actions (+ redirect)
        Route::get('/psb-wa', [PsbDraftController::class, 'index'])->name('psb-drafts.index');
        Route::get('/api/psb-wa/panel', [PsbDraftController::class, 'panel'])->name('psb-drafts.panel');
        // Fleet-wide PSB views/exports (PII of every area + legacy Starlite rows
        // without area), bot health (failed jobs, group allowlist) and global bot
        // settings/targets: global admin only. Scoped admins keep the area-filtered
        // panel API and draft actions. The KPI dashboard aggregates every area.
        Route::middleware('global-admin')->group(function () {
            Route::get('/psb-wa/health', [PsbDraftController::class, 'health'])->name('psb-drafts.health');
            Route::get('/api/psb-wa/health', [PsbDraftController::class, 'healthJson'])->name('psb-drafts.health-json');
            // STARLITE KPI + CSV export (read-only — tidak menyentuh alur aktivasi WA/web)
            Route::get('/psb-wa/dashboard', [PsbDraftController::class, 'dashboard'])->name('psb-drafts.dashboard');
            Route::get('/psb-wa/export.csv', [PsbDraftController::class, 'exportCsv'])->name('psb-drafts.export');
            Route::post('/psb-wa/import-starlite', [PsbDraftController::class, 'importStarlite'])->name('psb-drafts.import-starlite');
            Route::post('/psb-wa/settings', [PsbDraftController::class, 'saveSettings'])->name('psb-drafts.settings');
            Route::post('/psb-wa/targets', [PsbDraftController::class, 'updateTargets'])->name('psb-drafts.targets');
        });
        Route::post('/psb-wa/{id}/apply', [PsbDraftController::class, 'apply'])->name('psb-drafts.apply');
        Route::post('/psb-wa/{id}/retry', [PsbDraftController::class, 'retry'])->name('psb-drafts.retry');
        Route::post('/psb-wa/{id}/set-area', [PsbDraftController::class, 'setArea'])->name('psb-drafts.set-area');
        Route::post('/psb-wa/{id}/apply-correction', [PsbDraftController::class, 'applyCorrection'])->name('psb-drafts.apply-correction');
        Route::post('/psb-wa/{id}/cancel-correction', [PsbDraftController::class, 'cancelCorrection'])->name('psb-drafts.cancel-correction');
        Route::post('/psb-wa/{id}/sync-customer-media', [PsbDraftController::class, 'syncCustomerMedia'])->name('psb-drafts.sync-customer-media');

        Route::get('/migrasi', [CustomerMigrationController::class, 'index'])->name('customer-migrations.index');
        Route::get('/migrasi/export.csv', [CustomerMigrationController::class, 'exportCsv'])->name('customer-migrations.export');
        Route::post('/api/migrasi/guided-lookup/preview', [CustomerMigrationController::class, 'guidedLookupPreview'])
            ->middleware(['global-admin', 'throttle:20,1'])
            ->name('customer-migrations.guided-lookup.preview');
        Route::post('/api/migrasi/guided-lookup/approve', [CustomerMigrationController::class, 'approveGuidedLookup'])
            ->middleware(['global-admin', 'throttle:20,1'])
            ->name('customer-migrations.guided-lookup.approve');
        Route::post('/migrasi/import-preview', [CustomerMigrationController::class, 'importPreview'])->name('customer-migrations.import-preview');
        Route::patch('/migrasi/import-preview/bulk-correct', [CustomerMigrationController::class, 'bulkCorrectImportRows'])->name('customer-migrations.import-preview.bulk-correct');
        Route::post('/migrasi/import-preview/apply', [CustomerMigrationController::class, 'applyImportRows'])->name('customer-migrations.import-preview.apply');
        Route::patch('/migrasi/import-preview/{row}', [CustomerMigrationController::class, 'updateImportRow'])->name('customer-migrations.import-preview.update');
        Route::post('/migrasi/{migration}/device-action/prepare', [CustomerMigrationController::class, 'prepareDeviceAction'])->middleware('admin')->name('customer-migrations.device-action.prepare');
        Route::post('/migrasi/{migration}/device-action/execute', [CustomerMigrationController::class, 'executeDeviceAction'])->middleware('admin')->name('customer-migrations.device-action.execute');
        Route::get('/migrasi/{migration}/manual-complete', [CustomerMigrationController::class, 'manualCompletePreview'])->middleware('admin')->name('customer-migrations.manual-complete.preview');
        Route::post('/migrasi/{migration}/manual-complete', [CustomerMigrationController::class, 'manualComplete'])->middleware(['admin', 'throttle:20,1'])->name('customer-migrations.manual-complete');
        Route::post('/migrasi/{migration}/retry', [CustomerMigrationController::class, 'retry'])->name('customer-migrations.retry');
        Route::post('/migrasi/{migration}/cancel', [CustomerMigrationController::class, 'cancel'])->name('customer-migrations.cancel');
    });

    // =====================================================
    // Absensi (WA group attendance)
    // =====================================================
    Route::get('/attendances', [AttendanceController::class, 'index'])->name('attendances.index');
    Route::middleware('admin')->group(function () {
        Route::get('/api/attendances/settings', [AttendanceController::class, 'getSettings'])->name('api.attendances.settings');
        Route::post('/api/attendances/settings', [AttendanceController::class, 'saveSettings'])->name('api.attendances.settings.save');
        Route::get('/api/attendances/groups', [AttendanceController::class, 'fetchGroups'])->name('api.attendances.groups');
    });

    // =====================================================
    // Package Management
    // =====================================================
    Route::middleware('global-admin')->group(function () {
        Route::get('/packages/{package}/customers', [PackageController::class, 'customers'])->name('packages.customers');
        Route::resource('packages', PackageController::class);
        Route::resource('areas', AreaController::class);
        Route::get('/areas-migration', [AreaController::class, 'migration'])->name('areas.migration');
        Route::post('/areas-migration', [AreaController::class, 'migrate'])->name('areas.migration.store');
        Route::get('/olts/{olt}/export', [OltController::class, 'export'])
            ->name('olts.export');
        Route::resource('olts', OltController::class);
        Route::post('/olts/{olt}/test', [OltController::class, 'testConnection'])
            ->name('olts.test');
        Route::get('/olt-c300', [OltC300Controller::class, 'index'])->name('oltc300.index');

        Route::prefix('api/olt-c300')->name('api.olt-c300.')->middleware(['throttle:olt-operations', 'olt-safe-output'])->group(function () {
            Route::post('/detect', [OltC300Controller::class, 'detect'])->name('detect');
            Route::post('/test-connection', [OltC300Controller::class, 'testConnection'])->name('test-connection');
            Route::put('/update-credentials', [OltC300Controller::class, 'updateCredentials'])->name('update-credentials');
            Route::post('/list-uncfg', [OltC300Controller::class, 'listUncfg'])->name('list-uncfg');
            Route::post('/check', [OltC300Controller::class, 'check'])->name('check');
            Route::post('/provision', [OltC300Controller::class, 'provision'])->middleware('olt-provisioning')->name('provision');
            Route::post('/activate', [OltC300Controller::class, 'activate'])->middleware('olt-provisioning')->name('activate');
            Route::post('/reconfigure', [OltC300Controller::class, 'reconfigure'])->middleware('olt-provisioning')->name('reconfigure');
            Route::post('/snmp-test', [OltC300Controller::class, 'snmpTest'])->name('snmp-test');
            Route::post('/rx-power-refresh', [OltC300Controller::class, 'rxPowerRefresh'])->name('rx-power-refresh');
            Route::get('/rx-power-status', [OltC300Controller::class, 'rxPowerStatus'])->name('rx-power-status');
            Route::post('/ocr', [OltC300Controller::class, 'ocr'])->name('ocr');
            Route::post('/auto-pppoe', [OltC300Controller::class, 'autoPppoe'])->name('auto-pppoe');
            Route::post('/provision-fiberhome', [OltC300Controller::class, 'provisionFiberhome'])->middleware('olt-provisioning')->name('provision-fiberhome');
            // DB-only: confirm linking a manual provision to an open migration (no device write).
            Route::post('/link-migration', [OltC300Controller::class, 'linkMigration'])->name('link-migration');
            Route::get('/customer-by-sn', [OltC300Controller::class, 'customerBySn'])->name('customer-by-sn');
            Route::get('/vlan-map', [OltC300Controller::class, 'vlanMapApi'])->name('vlan-map');
            Route::get('/genieacs-tags', [OltC300Controller::class, 'genieacsTags'])->name('genieacs-tags');
        });

        Route::prefix('api/olt-huawei')->name('api.olt-huawei.')->middleware(['throttle:olt-operations', 'olt-safe-output'])->group(function () {
            Route::post('/autofind', [OltHuaweiController::class, 'autofind'])->name('autofind');
            Route::post('/detect', [OltHuaweiController::class, 'detect'])->name('detect');
            Route::post('/provision', [OltHuaweiController::class, 'provision'])->middleware('olt-provisioning')->name('provision');
        });

        Route::get('/api/areas', [OltC300Controller::class, 'apiAreas']);
        Route::get('/api/areas/{area}/packages', [OltC300Controller::class, 'apiAreaPackages']);
        Route::get('/api/routers', [OltC300Controller::class, 'apiRouters']);
        Route::get('/api/odp-odc', [OltC300Controller::class, 'apiOdpOdc']);
        Route::get('/api/routers/{router}/packages', [OltC300Controller::class, 'apiRouterPackages']);
        Route::get('/api/routers/{router}/last-pppoe-ip', [OltC300Controller::class, 'apiLastPppoeIp']);
        Route::post('/api/packages/sync-scraper', [OltC300Controller::class, 'apiSyncPackagesFromScraper']);
        Route::post('/api/ktp-ocr', [OltC300Controller::class, 'ktpOcr']);

        Route::get('/api/olts/{olt}/gpon-snapshot', [OltController::class, 'gponSnapshot'])
            ->name('api.olts.gpon-snapshot');
        Route::post('/api/olts/{olt}/onu/detail', [OltController::class, 'onuDetail'])
            ->name('api.olts.onu.detail');
        Route::post('/api/olts/{olt}/onu/rename', [OltController::class, 'renameOnu'])->middleware('olt-provisioning')
            ->name('api.olts.onu.rename');
        Route::get('/api/olts/{olt}/onu/lookup-rename', [OltController::class, 'lookupOnuForRename'])
            ->name('api.olts.onu.lookup-rename');
        Route::post('/api/olts/{olt}/onu/reboot', [OltController::class, 'rebootOnu'])->middleware('olt-provisioning')
            ->name('api.olts.onu.reboot');
        Route::post('/api/olts/{olt}/onu/admin-state', [OltController::class, 'setOnuAdminState'])->middleware('olt-provisioning')
            ->name('api.olts.onu.admin-state');
        Route::delete('/api/olts/{olt}/onu', [OltController::class, 'deleteOnu'])->middleware('olt-provisioning')
            ->name('api.olts.onu.delete');
        Route::post('/api/olts/{olt}/save-config', [OltController::class, 'saveConfig'])->middleware('olt-provisioning')
            ->name('api.olts.save-config');
        Route::post('/api/olts/{olt}/onu/authorize', [OltController::class, 'authorizeOnu'])->middleware('olt-provisioning')
            ->name('api.olts.onu.authorize');
        Route::get('/api/olts/{olt}/vlan-mappings', [OltVlanMappingController::class, 'index'])
            ->name('api.olts.vlan-mappings.index');
        Route::post('/api/olts/{olt}/vlan-mappings', [OltVlanMappingController::class, 'store'])
            ->name('api.olts.vlan-mappings.store');
        Route::put('/api/olt-vlan-mappings/{mapping}', [OltVlanMappingController::class, 'update'])
            ->name('api.olt-vlan-mappings.update');
        Route::delete('/api/olt-vlan-mappings/{mapping}', [OltVlanMappingController::class, 'destroy'])
            ->name('api.olt-vlan-mappings.destroy');

        // VLAN service per MikroTik (Aktivasi tab) — HTB/pppoe_vlan; bukan map PON OLT
        Route::get('/api/router-vlans', [RouterVlanController::class, 'index'])
            ->name('api.router-vlans.index');
        Route::post('/api/router-vlans', [RouterVlanController::class, 'store'])
            ->name('api.router-vlans.store');
        Route::put('/api/router-vlans/{routerVlan}', [RouterVlanController::class, 'update'])
            ->name('api.router-vlans.update');
        Route::delete('/api/router-vlans/{routerVlan}', [RouterVlanController::class, 'destroy'])
            ->name('api.router-vlans.destroy');
        Route::post('/routers/{router}/test', [RouterController::class, 'testConnection'])
            ->name('routers.test');
        Route::post('/routers/{router}/scan', [RouterController::class, 'scanRouter'])
            ->name('routers.scan');
        Route::post('/routers/{router}/sync', [RouterController::class, 'sync'])
            ->name('routers.sync');
        Route::post('/routers/{router}/vpn', [RouterController::class, 'updateVpn'])
            ->name('routers.vpn.update');
        Route::post('/routers/{router}/radius-sync', [RouterController::class, 'syncRadius'])
            ->name('routers.radius.sync');
        Route::post('/routers/{router}/profiles-sync', [RouterController::class, 'syncProfiles'])
            ->name('routers.profiles.sync');
        Route::post('/routers/{router}/isolation-profile', [RouterController::class, 'storeIsolationProfile'])
            ->name('routers.isolation-profile.store');
        Route::delete('/routers/{router}/isolation-profile', [RouterController::class, 'destroyIsolationProfile'])
            ->name('routers.isolation-profile.destroy');
        Route::post('/routers/sync-all', [RouterController::class, 'syncAll'])
            ->name('routers.sync-all');
        Route::get('/api/routers/{router}/customers', [RouterController::class, 'customers'])->name('api.routers.customers');
        require __DIR__.'/web-router-unlinked.php';
        Route::get('/api/routers/{router}/profiles', [RouterController::class, 'getProfiles'])
            ->name('api.routers.profiles');
        Route::get('/api/routers/{router}/live-stats', [RouterController::class, 'liveStats'])
            ->name('api.routers.live-stats');
        Route::resource('routers', RouterController::class);
        Route::post('/routers/{router}/sync-online', [RouterController::class, 'syncOnlineStatus'])
            ->name('routers.sync-online');
    });

    // =====================================================
    // Invoice Management
    // =====================================================
    Route::get('/invoices', [InvoiceController::class, 'index'])
        ->name('invoices.index');
    Route::middleware('admin')->group(function () {
        // Export berisi NIK (didekripsi), HP, alamat, riwayat bayar seluruh area
        // dan membangun XLSX secara sinkron — wajib admin + throttle, sama seperti
        // customers.export (H1b). Sebelumnya hanya `auth`, jadi teknisi bisa
        // mengunduh seluruh PII pelanggan.
        Route::get('/invoices/export', [InvoiceController::class, 'export'])
            ->middleware('throttle:finance-export')
            ->name('invoices.export');
        Route::get('/invoices/create', [InvoiceController::class, 'create'])
            ->name('invoices.create');
        Route::get('/api/invoices/customer-options', [InvoiceController::class, 'customerOptions'])
            ->name('invoices.customer-options');
        Route::post('/invoices', [InvoiceController::class, 'store'])
            ->name('invoices.store');
        Route::get('/invoices/{invoice}/edit', [InvoiceController::class, 'edit'])
            ->name('invoices.edit');
        Route::put('/invoices/{invoice}', [InvoiceController::class, 'update'])
            ->name('invoices.update');
        Route::post('/invoices/{invoice}/void', [InvoiceController::class, 'void'])
            ->name('invoices.void');
        Route::delete('/invoices/{invoice}', [InvoiceController::class, 'destroy'])
            ->name('invoices.destroy');
    });
    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])
        ->name('invoices.show');
    Route::get('/invoices/{invoice}/download', [InvoiceController::class, 'download'])
        ->name('invoices.download');
    Route::get('/customers/{customer}/invoices', [InvoiceController::class, 'customerInvoices'])
        ->name('customers.invoices');

    // =====================================================
    // Payment Entry
    // =====================================================
    Route::middleware('admin')->group(function () {
        Route::get('/invoices/{invoice}/pay', [PaymentController::class, 'create'])
            ->name('invoices.pay');
        Route::post('/invoices/{invoice}/payments', [PaymentController::class, 'store'])
            ->name('payments.store');
        Route::post('/invoices/{invoice}/payments/{transaction}/reverse', [PaymentController::class, 'reverse'])
            ->middleware('global-admin')
            ->name('payments.reverse');
        Route::post('/payments/bulk-import', [PaymentController::class, 'bulkImport'])
            ->name('payments.bulk-import');
        Route::post('/payments/bulk', [PaymentController::class, 'bulkStore'])
            ->name('payments.bulk');
    });

    // =====================================================
    // Analytics & Reports
    // =====================================================
    Route::middleware('admin')->group(function () {
        Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics.index');
        Route::get('/finance/per-area', [AnalyticsController::class, 'financePerArea'])->name('finance.per-area.index');
        Route::get('/api/analytics/revenue-trend', [AnalyticsController::class, 'revenueTrend'])->name('api.analytics.revenue-trend');
        Route::get('/api/analytics/mrr', [AnalyticsController::class, 'mrr'])->name('api.analytics.mrr');
        Route::get('/api/analytics/collection-rate', [AnalyticsController::class, 'collectionRate'])->name('api.analytics.collection-rate');
        Route::get('/api/analytics/revenue-by-area', [AnalyticsController::class, 'revenueByArea'])->name('api.analytics.revenue-by-area');
        Route::get('/api/analytics/package-performance', [AnalyticsController::class, 'packagePerformance'])->name('api.analytics.package-performance');
        Route::get('/api/analytics/payment-methods', [AnalyticsController::class, 'paymentMethods'])->name('api.analytics.payment-methods');
        Route::get('/api/analytics/outstanding-aging', [AnalyticsController::class, 'outstandingAging'])->name('api.analytics.outstanding-aging');
        Route::get('/api/analytics/customer-growth', [AnalyticsController::class, 'customerGrowth'])->name('api.analytics.customer-growth');

        Route::prefix('/api/finance/per-area')->name('api.finance.per-area.')->group(function () {
            Route::get('/summary', [FinancePerAreaController::class, 'summary'])->name('summary');
            Route::get('/detail', [FinancePerAreaController::class, 'detail'])->name('detail');
            Route::get('/export', FinancePerAreaExportController::class)
                ->middleware('throttle:finance-export')
                ->name('export');
            Route::get('/operations', FinancePerAreaOperationsController::class)->name('operations');
            Route::get('/bulk-wa/preview', [FinanceBulkWaController::class, 'preview'])
                ->middleware('throttle:finance-export')
                ->name('bulk-wa.preview');
            Route::post('/bulk-wa/execute', [FinanceBulkWaController::class, 'execute'])
                ->middleware('throttle:finance-bulk-wa')
                ->name('bulk-wa.execute');
        });
    });

    // =====================================================
    // Radius Suite Cockpit
    // =====================================================
    Route::middleware('admin')->group(function () {
        Route::get('/api/hotspot-vouchers', [HotspotVoucherController::class, 'index'])
            ->name('api.hotspot-vouchers.index');
        Route::post('/api/hotspot-vouchers', [HotspotVoucherController::class, 'store'])
            ->name('api.hotspot-vouchers.store');
        Route::post('/api/hotspot-vouchers/{voucher}/sync', [HotspotVoucherController::class, 'sync'])
            ->name('api.hotspot-vouchers.sync');
        Route::post('/api/hotspot-vouchers/{voucher}/disable', [HotspotVoucherController::class, 'disable'])
            ->name('api.hotspot-vouchers.disable');

        Route::get('/api/payment-gateway/events', [PaymentGatewayController::class, 'index'])
            ->name('api.payment-gateway.events');
        // Online payment reconciliation (admin + superadmin only, NOC excluded in controller; admin area scoped).
        Route::get('/payment-gateway/transactions', [PaymentGatewayTransactionController::class, 'index'])
            ->name('payment-gateway.transactions');
        Route::post('/payment-gateway/transactions/{transaction}/check', [PaymentGatewayTransactionController::class, 'check'])
            ->middleware('throttle:6,1')
            ->name('payment-gateway.transactions.check');
        // Kelebihan bayar (customer credits): superadmin + non-area admin, enforced in the controller.
        Route::get('/customer-credits', [CustomerCreditController::class, 'index'])
            ->name('customer-credits.index');
        Route::post('/customer-credits/{credit}/apply', [CustomerCreditController::class, 'apply'])
            ->middleware('throttle:30,1')
            ->name('customer-credits.apply');
        Route::post('/customer-credits/{credit}/resolve', [CustomerCreditController::class, 'resolve'])
            ->middleware('throttle:30,1')
            ->name('customer-credits.resolve');
        Route::get('/api/payment-gateway/config', [PaymentGatewayController::class, 'config'])
            ->middleware('superadmin')
            ->name('api.payment-gateway.config');
        Route::post('/api/payment-gateway/config', [PaymentGatewayController::class, 'updateConfig'])
            ->middleware('superadmin')
            ->name('api.payment-gateway.config.update');
        Route::post('/api/payment-gateway/kill-switch', [PaymentGatewayController::class, 'updateKillSwitch'])
            ->middleware(['superadmin', 'control-plane-totp'])
            ->name('api.payment-gateway.kill-switch');
        Route::post('/api/payment-gateway/invoices/{invoice}/midtrans', [PaymentGatewayController::class, 'createMidtransSnap'])
            ->name('api.payment-gateway.invoices.midtrans');

        Route::get('/api/support-tickets', [SupportTicketController::class, 'index'])
            ->name('api.support-tickets.index');
        Route::post('/api/support-tickets', [SupportTicketController::class, 'store'])
            ->name('api.support-tickets.store');
        Route::patch('/api/support-tickets/{ticket}', [SupportTicketController::class, 'update'])
            ->name('api.support-tickets.update');

        Route::get('/api/reseller-commissions', [ResellerCommissionController::class, 'index'])
            ->name('api.reseller-commissions.index');
        Route::post('/api/reseller-commissions', [ResellerCommissionController::class, 'store'])
            ->name('api.reseller-commissions.store');
        Route::post('/api/reseller-commissions/{commission}/paid', [ResellerCommissionController::class, 'markPaid'])
            ->name('api.reseller-commissions.paid');

        Route::get('/api/genieacs/devices', [GenieAcsController::class, 'index'])
            ->name('api.genieacs.devices');
        Route::get('/api/genieacs/devices/{device}', [GenieAcsController::class, 'show'])
            ->name('api.genieacs.show');
        Route::post('/api/genieacs/sync', [GenieAcsController::class, 'sync'])
            ->name('api.genieacs.sync');
        Route::post('/api/genieacs/devices/{device}/reboot', [GenieAcsController::class, 'reboot'])
            ->name('api.genieacs.reboot');
        Route::post('/api/genieacs/devices/{device}/parameter', [GenieAcsController::class, 'setParameter'])
            ->name('api.genieacs.parameter');
    });

    // Halaman modul RadiusSuite: API-nya (hotspot-vouchers, support-tickets,
    // reseller-commissions, genieacs) sudah di grup `admin` di atas, dan sidebar
    // menampilkannya hanya untuk admin. Halamannya sendiri sebelumnya cuma `auth`,
    // sehingga teknisi bisa membuka /ticketing dan /kemitraan dan menerima daftar
    // SEMUA user + 500 pelanggan tanpa area scope.
    Route::middleware('admin')->group(function () {
        Route::get('/hotspot-voucher', fn () => Inertia\Inertia::render('Modules/RadiusSuite', [
            'module' => 'hotspot',
            'title' => 'Hotspot Voucher',
            'description' => 'Pusat voucher hotspot berbasis RADIUS untuk generate batch voucher, masa aktif, kuota, profile MikroTik, dan sinkron semua NAS.',
            'routers' => Router::query()->select('id', 'name')->orderBy('name')->get(),
            'packages' => Package::query()->select('id', 'name', 'price', 'mikrotik_profile', 'rate_limit')->orderBy('name')->get(),
        ]))->name('modules.hotspot');

        Route::get('/ticketing', fn () => Inertia\Inertia::render('Modules/RadiusSuite', [
            'module' => 'ticketing',
            'title' => 'Ticketing',
            'description' => 'Tiket pasang baru dan gangguan dengan assignment teknisi, SLA, catatan pekerjaan, foto bukti, dan notifikasi WhatsApp.',
            'customers' => Customer::query()->ebilling()->select('id', 'code', 'name')->orderBy('name')->limit(500)->get(),
            'users' => User::query()->select('id', 'name')->orderBy('name')->get(),
        ]))->name('modules.ticketing');

        Route::get('/kemitraan', fn () => Inertia\Inertia::render('Modules/RadiusSuite', [
            'module' => 'reseller',
            'title' => 'Kemitraan',
            'description' => 'Dashboard mitra/reseller untuk melihat pelanggan binaan, tagihan, komisi, settlement, dan performa area.',
            'users' => User::query()->select('id', 'name')->orderBy('name')->get(),
        ]))->name('modules.reseller');

        Route::get('/genieacs', [GenieAcsController::class, 'page'])->name('modules.genieacs');
    });

    Route::get('/portal-pelanggan', fn () => Inertia\Inertia::render('Modules/RadiusSuite', [
        'module' => 'portal',
        'title' => 'Portal Pelanggan',
        'description' => 'Area pelanggan untuk cek status internet, tagihan, riwayat pembayaran, buka tiket, serta request ganti SSID/password.',
    ]))->name('modules.portal');

    // FAQ / Panduan: konten statis dari resources/js/faq, difilter per peran di frontend.
    Route::get('/faq', fn (Request $request) => Inertia\Inertia::render('Faq/Index', [
        'kategori' => $request->query('kategori'),
    ]))->name('faq.index');
    Route::redirect('/support-center', '/faq')->name('modules.support');

    // =====================================================
    // Broadcast & Campaigns
    // =====================================================
    Route::get('/broadcasts', [WaCampaignController::class, 'index'])->name('broadcasts.index');
    Route::middleware('admin')->group(function () {
        Route::get('/broadcasts/create', [WaCampaignController::class, 'create'])->name('broadcasts.create');
        Route::post('/broadcasts', [WaCampaignController::class, 'store'])->name('broadcasts.store');
        Route::post('/broadcasts/{campaign}/start', [WaCampaignController::class, 'start'])->name('broadcasts.start');
        Route::post('/broadcasts/{campaign}/retry', [WaCampaignController::class, 'retryFailed'])->name('broadcasts.retry');
    });
    Route::get('/broadcasts/{campaign}', [WaCampaignController::class, 'show'])->name('broadcasts.show');

    // =====================================================
    // Ticketing
    // =====================================================
    Route::get('/device-recovery', [DeviceRecoveryController::class, 'index'])->name('device-recovery.index');
    Route::get('/device-recovery/{recoveryCase}', [DeviceRecoveryController::class, 'show'])->name('device-recovery.show');
    Route::get('/device-recovery/{recoveryCase}/items/{item}/photo/{index}', [DeviceRecoveryController::class, 'photo'])
        ->whereNumber('index')
        ->middleware('signed')
        ->name('device-recovery.photo');
    // Owner 2026-10-10: technician "Lapor pengambilan tanpa tiket".
    Route::get('/device-recovery/report/customers', [DeviceRecoveryController::class, 'reportCustomers'])
        ->middleware('throttle:60,1')
        ->name('device-recovery.report-customers');
    Route::post('/device-recovery/report', [DeviceRecoveryController::class, 'report'])
        ->middleware(['device-recovery.writable', 'throttle:10,60'])
        ->name('device-recovery.report');
    Route::post('/tickets/{ticket}/device-recovery', [DeviceRecoveryController::class, 'create'])
        ->middleware(['admin', 'device-recovery.writable'])
        ->name('device-recovery.create');
    Route::post('/device-recovery/{recoveryCase}/items', [DeviceRecoveryController::class, 'addItem'])
        ->middleware(['admin', 'device-recovery.writable'])
        ->name('device-recovery.add-item');
    Route::post('/device-recovery/{recoveryCase}/items/{item}', [DeviceRecoveryController::class, 'updateItem'])
        ->middleware(['admin', 'device-recovery.writable'])
        ->name('device-recovery.update-item');
    Route::post('/device-recovery/{recoveryCase}/assign', [DeviceRecoveryController::class, 'assign'])
        ->middleware(['admin', 'device-recovery.writable'])
        ->name('device-recovery.assign');
    Route::post('/device-recovery/{recoveryCase}/schedule', [DeviceRecoveryController::class, 'schedule'])
        ->middleware(['admin', 'device-recovery.writable'])
        ->name('device-recovery.schedule');
    Route::post('/device-recovery/{recoveryCase}/start', [DeviceRecoveryController::class, 'start'])
        ->middleware('device-recovery.writable')
        ->name('device-recovery.start');
    Route::post('/device-recovery/{recoveryCase}/items/{item}/capture', [DeviceRecoveryController::class, 'capture'])
        ->middleware('device-recovery.writable')
        ->name('device-recovery.capture');
    Route::post('/device-recovery/{recoveryCase}/receive', [DeviceRecoveryController::class, 'receive'])
        ->middleware(['admin', 'device-recovery.writable'])
        ->name('device-recovery.receive');
    Route::post('/device-recovery/{recoveryCase}/quick-record', [DeviceRecoveryController::class, 'quickRecord'])
        ->middleware(['admin', 'device-recovery.writable'])
        ->name('device-recovery.quick-record');
    Route::post('/device-recovery/{recoveryCase}/resolve-exception', [DeviceRecoveryController::class, 'resolveException'])
        ->middleware(['global-admin', 'device-recovery.writable'])
        ->name('device-recovery.resolve-exception');
    Route::post('/device-recovery/{recoveryCase}/keep-customer-active', [DeviceRecoveryController::class, 'keepCustomerActive'])
        ->middleware(['admin', 'device-recovery.writable'])
        ->name('device-recovery.keep-customer-active');
    Route::post('/device-recovery/{recoveryCase}/cancel', [DeviceRecoveryController::class, 'cancel'])
        ->middleware(['admin', 'device-recovery.writable'])
        ->name('device-recovery.cancel');
    Route::get('/tickets', [TicketController::class, 'index'])->name('tickets.index');
    Route::get('/tickets/create', [TicketController::class, 'create'])->name('tickets.create');
    Route::get('/tickets/dismantle-reasons', [DismantleReasonReportController::class, 'index'])
        ->middleware('admin')
        ->name('tickets.dismantle-reasons');
    Route::post('/tickets', [TicketController::class, 'store'])->name('tickets.store');
    // ---- RCA-4 "Cek sekarang" (role gate = diagnosis_panel_roles, flag diagnosis_check_now_enabled; JSON, read-only) ----
    // (route names are tickets.diagnose.* so the Ziggy "tickets.*" filter already exposes them to the frontend)
    Route::prefix('diagnose')->name('tickets.diagnose.')->middleware('throttle:diagnose-now')->group(function () {
        Route::get('/customer/{customer}', [DiagnoseController::class, 'customer'])->whereNumber('customer')->name('customer');
        Route::post('/start', [DiagnoseController::class, 'start'])->name('start');
        Route::post('/{diagnosis}/probe/{probe}', [DiagnoseController::class, 'probe'])->whereNumber('diagnosis')->whereIn('probe', ['router', 'olt', 'cpe'])->name('probe');
        Route::post('/{diagnosis}/finish', [DiagnoseController::class, 'finish'])->whereNumber('diagnosis')->name('finish');
        Route::get('/{diagnosis}', [DiagnoseController::class, 'show'])->whereNumber('diagnosis')->name('show');
    });
    // ---- end RCA-4 ----
    Route::post('/tickets/monitoring-candidates/{event}/draft', [TicketController::class, 'approveMonitoringCandidate'])
        ->middleware('admin')
        ->name('tickets.monitoring-candidates.draft');
    Route::middleware('admin')->group(function () {
        Route::post('/monitoring/tickets/{ticket}/acknowledge', [MonitoringTicketController::class, 'acknowledge'])->name('monitoring.tickets.acknowledge');
        Route::post('/monitoring/tickets/{ticket}/assign', [MonitoringTicketController::class, 'assign'])->name('monitoring.tickets.assign');
        Route::post('/monitoring/tickets/{ticket}/snooze', [MonitoringTicketController::class, 'snooze'])->name('monitoring.tickets.snooze');
        Route::post('/monitoring/tickets/{ticket}/false-positive', [MonitoringTicketController::class, 'falsePositive'])->name('monitoring.tickets.false-positive');
        Route::post('/monitoring/tickets/{ticket}/verify-recovery', [MonitoringTicketController::class, 'verifyRecovery'])->name('monitoring.tickets.verify-recovery');
    });
    Route::get('/tickets/{ticket}', [TicketController::class, 'show'])->name('tickets.show');
    Route::get('/tickets/{ticket}/edit', [TicketController::class, 'edit'])->name('tickets.edit');
    Route::match(['put', 'patch'], '/tickets/{ticket}', [TicketController::class, 'update'])->name('tickets.update');
    Route::post('/tickets/{ticket}/assign', [TicketController::class, 'assign'])
        ->middleware('admin')
        ->name('tickets.assign');
    Route::post('/tickets/{ticket}/customer-lifecycle', [TicketController::class, 'requestCustomerLifecycle'])
        ->middleware('admin')
        ->name('tickets.customer-lifecycle.request');
    Route::post('/tickets/{ticket}/customer-lifecycle/{activity}/approve', [TicketController::class, 'approveCustomerLifecycle'])
        ->middleware('admin')
        ->name('tickets.customer-lifecycle.approve');
    Route::post('/tickets/{ticket}/technician-update', [TicketController::class, 'technicianUpdate'])->name('tickets.technician-update');
    Route::post('/tickets/{ticket}/comment', [TicketController::class, 'comment'])->name('tickets.comment');
    Route::delete('/tickets/{ticket}', [TicketController::class, 'destroy'])
        ->middleware('admin')
        ->name('tickets.destroy');

    // =====================================================
    // Monitoring NOC
    // =====================================================
    Route::get('/monitoring', [MonitoringController::class, 'index'])
        ->middleware('global-admin')
        ->name('monitoring.index');
    Route::get('/monitoring/noc', [MonitoringController::class, 'noc'])
        ->middleware('global-admin')
        ->name('monitoring.noc');
    Route::post('/api/monitoring/routers/refresh', [MonitoringController::class, 'refreshRouters'])
        ->middleware('throttle:monitoring-refresh')
        ->middleware('global-admin')
        ->name('monitoring.routers.refresh');
    Route::post('/api/monitoring/routers/{router}/refresh', [MonitoringController::class, 'refreshRouterStatus'])
        ->middleware('throttle:monitoring-refresh')
        ->middleware('global-admin')
        ->name('monitoring.routers.refresh-one');
    Route::middleware('admin')->group(function () {
        Route::get('/api/monitoring/incidents', [MonitoringController::class, 'incidents'])
            ->middleware('throttle:api')
            ->name('api.monitoring.incidents');
        Route::get('/api/monitoring/connectivity/settings', [MonitoringController::class, 'getConnectivityAlertSettings'])
            ->middleware('global-admin')
            ->name('api.monitoring.connectivity.settings');
        Route::post('/api/monitoring/connectivity/settings', [MonitoringController::class, 'saveConnectivityAlertSettings'])
            ->middleware('global-admin')
            ->name('api.monitoring.connectivity.settings.save');
    });

    // Deteksi Gangguan tab (outage shadow mode): read-only GET JSON, same access as Monitoring.
    Route::middleware(['global-admin', 'throttle:api'])->group(function () {
        Route::get('/api/monitoring/outage-shadow', [OutageShadowController::class, 'index'])
            ->name('api.monitoring.outage-shadow');
        Route::get('/api/monitoring/outage-shadow/diagnosis-precision', [OutageShadowController::class, 'precision'])
            ->name('api.monitoring.outage-shadow.diagnosis-precision');
        Route::get('/api/monitoring/outage-shadow/{signal}/customers', [OutageShadowController::class, 'customers'])
            ->whereNumber('signal')
            ->name('api.monitoring.outage-shadow.customers');
        Route::get('/api/monitoring/outage-shadow/{signal}/history', [OutageShadowController::class, 'history'])
            ->whereNumber('signal')
            ->name('api.monitoring.outage-shadow.history');
        // Manual "Buat tiket" (own controller; the read routes above stay GET-only).
        Route::get('/api/monitoring/outage-shadow/{signal}/ticket-preview', [OutageManualTicketController::class, 'preview'])
            ->whereNumber('signal')
            ->name('api.monitoring.outage-shadow.ticket-preview');
        Route::post('/api/monitoring/outage-shadow/{signal}/ticket', [OutageManualTicketController::class, 'store'])
            ->whereNumber('signal')
            ->name('api.monitoring.outage-shadow.ticket');
    });

    // M-4d: CPE health is DB-backed, not a Grafana widget; registered before {widget}.
    Route::get('/api/monitoring/widgets/cpe', [MonitoringCpeHealthController::class, 'show'])
        ->middleware(['global-admin', 'throttle:monitoring-widgets'])
        ->name('api.monitoring.widgets.cpe');

    Route::get('/api/monitoring/widgets/{widget}', [MonitoringWidgetController::class, 'show'])
        ->where('widget', 'summary|routers|router-traffic|upstream|resources|ping')
        ->middleware(['global-admin', 'throttle:monitoring-widgets'])
        ->name('api.monitoring.widgets.show');

    Route::middleware('global-admin')->group(function () {
        // M-5 soft-remove: the Target NOC page is gone; old links land on Monitoring > Jaringan.
        Route::get('/monitoring-targets', fn () => redirect()->route('monitoring.index', ['tab' => 'jaringan']))
            ->name('monitoring-targets.index');
    });

    Route::middleware('global-admin')->prefix('monitoring/settings')->name('monitoring.settings.')->group(function () {
        Route::get('/', [MonitoringSettingsController::class, 'index'])->name('index');
        Route::post('/test', [MonitoringSettingsController::class, 'testConnection'])
            ->middleware('throttle:monitoring-refresh')
            ->name('test');
        Route::get('/label-values/{source}', [MonitoringSettingsController::class, 'labelValues'])
            ->where('source', 'routerboard_name|router|upstream|edge_interface')
            ->middleware('throttle:monitoring-refresh')
            ->name('label-values');
        Route::post('/router-identities/suggest', [MonitoringSettingsController::class, 'suggest'])
            ->middleware('throttle:monitoring-refresh')
            ->name('router-identities.suggest');
        Route::put('/connection', [MonitoringSettingsController::class, 'updateConnection'])
            ->middleware('control-plane-totp')
            ->name('connection.update');
        Route::put('/upstream-links', [MonitoringSettingsController::class, 'updateUpstreamLinks'])
            ->middleware('control-plane-totp')
            ->name('upstream-links.update');
        Route::put('/router-identities', [MonitoringSettingsController::class, 'updateRouterIdentities'])
            ->middleware('control-plane-totp')
            ->name('router-identities.update');
    });

    // M-4c-2b: router detail page (data comes from the API below)
    Route::get('/monitoring/routers/{router}', [MonitoringRouterController::class, 'show'])
        ->middleware('global-admin')
        ->whereNumber('router')
        ->name('monitoring.routers.show');

    // M-4c-2a: router detail API
    Route::middleware(['global-admin', 'throttle:monitoring-router-detail'])
        ->prefix('api/monitoring/routers/{router}')
        ->whereNumber('router')
        ->name('api.monitoring.routers.')
        ->group(function () {
            Route::get('/overview', [MonitoringRouterController::class, 'overview'])->name('overview');
            Route::get('/pppoe', [MonitoringRouterController::class, 'pppoe'])->name('pppoe');
            Route::get('/pppoe/daily', [MonitoringRouterController::class, 'pppoeDaily'])->name('pppoe-daily');
        });

    // =====================================================
    // Inventaris
    // =====================================================
    Route::get('/inventaris', [InventarisController::class, 'index'])->name('inventaris.index');
    Route::get('/inventaris/export', [InventarisController::class, 'export'])
        ->middleware(['admin', 'throttle:inventory-export'])
        ->name('inventaris.export');
    Route::post('/inventaris/import-preview', [InventarisController::class, 'importPreview'])
        ->middleware('admin')
        ->name('inventaris.import-preview');

    // ODP/ODC asset CRUD (FTTH topology). Admin-level writes.
    Route::post('/odp-odc', [OdpOdcController::class, 'store'])
        ->middleware('admin')->name('odp-odc.store');
    Route::match(['put', 'patch'], '/odp-odc/{odpOdc}', [OdpOdcController::class, 'update'])
        ->middleware('admin')->name('odp-odc.update');
    Route::delete('/odp-odc/{odpOdc}', [OdpOdcController::class, 'destroy'])
        ->middleware('admin')->name('odp-odc.destroy');
    // =====================================================
    // =====================================================
    // Peta / Maps
    // =====================================================
    Route::get('/peta', [MapsController::class, 'peta'])->name('peta.index');
    Route::get('/api/peta/geojson/{area}', [MapsController::class, 'petaGeojson'])->name('api.peta.geojson');
    Route::get('/api/peta/customers/{area}', [MapsController::class, 'petaCustomers'])->name('api.peta.customers');
    Route::get('/api/peta/customer-search', [MapsController::class, 'searchCustomers'])->name('api.peta.customer-search');

    // Settings > Grup WhatsApp: superadmin + admin without area scope (checked in the controller;
    // NOC and area admins get 403). Deliberately NOT behind control-plane-totp (owner decision).
    Route::middleware('admin')->group(function () {
        Route::get('/settings/wa-groups', [WaGroupSettingsController::class, 'index'])->name('settings.wa-groups');
        Route::get('/api/settings/wa-groups/groups', [WaGroupSettingsController::class, 'groups'])
            ->middleware('throttle:12,1')
            ->name('api.settings.wa-groups.groups');
        Route::post('/api/settings/wa-groups/membership', [WaGroupSettingsController::class, 'membership'])
            ->middleware('throttle:20,1')
            ->name('api.settings.wa-groups.membership');
        Route::put('/settings/wa-groups', [WaGroupSettingsController::class, 'update'])
            ->middleware('throttle:10,1')
            ->name('settings.wa-groups.update');
    });

    // Settings System (superadmin only — WA gateway + company)
    // =====================================================
    Route::middleware('superadmin')->group(function () {
        Route::get('/settings', [SettingController::class, 'index'])->name('settings.index');
        Route::get('/settings/payment-gateway', [PaymentGatewaySettingsController::class, 'index'])->name('settings.payment-gateway');
        Route::post('/settings/payment-gateway', [PaymentGatewaySettingsController::class, 'update'])
            ->middleware('control-plane-totp')
            ->name('settings.payment-gateway.update');
        Route::get('/api/settings/payment-gateway/duitku-methods', [PaymentGatewaySettingsController::class, 'duitkuMethods'])
            ->middleware('throttle:10,1')
            ->name('api.settings.payment-gateway.duitku-methods');
        Route::post('/settings', [SettingController::class, 'update'])->name('settings.update');
        Route::post('/api/settings/billing-wa', [SettingController::class, 'saveBillingWa'])->name('api.settings.billing-wa');
        Route::post('/api/settings/billing-wa/live', [SettingController::class, 'setBroadcastLive'])->name('api.settings.billing-wa.live');
        Route::post('/api/settings/billing-wa/billing-live', [SettingController::class, 'setBillingWaLive'])->name('api.settings.billing-wa.billing-live');
        Route::post('/api/settings/billing-enforcement', [SettingController::class, 'saveBillingEnforcement'])->name('api.settings.billing-enforcement');
        Route::post('/api/settings/finance-bulk-wa', [SettingController::class, 'saveFinanceBulkWa'])->name('api.settings.finance-bulk-wa');
        Route::post('/api/settings/isolation-portal', [SettingController::class, 'saveIsolationPortal'])->name('api.settings.isolation-portal');
        Route::get('/api/technician-progress', [TechnicianProgressController::class, 'show'])->name('api.technician-progress.show');
        Route::post('/api/technician-progress/preview', [TechnicianProgressController::class, 'preview'])->name('api.technician-progress.preview');
        Route::get('/api/technician-progress/summary', [TechnicianProgressController::class, 'summary'])->name('api.technician-progress.summary');
        Route::post('/api/technician-progress', [TechnicianProgressController::class, 'save'])->name('api.technician-progress.save');

        // WA Gateway page (pairing / re-pair / status) — superadmin only
        Route::get('/wa-gateway', [SettingController::class, 'waPair'])->name('wa-gateway.pair');

        // WA Gateway API (status, create instance, get QR, disconnect, fetch groups, test)
        Route::prefix('api/wa-gateway')->group(function () {
            Route::get('/status', [SettingController::class, 'waStatus'])->name('api.wa-gateway.status');
            Route::post('/create-instance', [SettingController::class, 'waCreateInstance'])->name('api.wa-gateway.create-instance');
            Route::get('/qr-code', [SettingController::class, 'waQrCode'])->name('api.wa-gateway.qr-code');
            Route::post('/disconnect', [SettingController::class, 'waDisconnect'])->name('api.wa-gateway.disconnect');
            Route::post('/re-pair', [SettingController::class, 'waRePair'])->name('api.wa-gateway.re-pair');
            Route::post('/test', [SettingController::class, 'waTestMessage'])->name('api.wa-gateway.test');
            Route::get('/groups', [SettingController::class, 'waGroups'])->name('api.wa-gateway.groups');
            Route::post('/groups', [SettingController::class, 'waGroups']);
            Route::post('/config', [SettingController::class, 'waSaveConfig'])->name('api.wa-gateway.config');
            Route::post('/connect', [SettingController::class, 'waCreateInstance'])->name('api.wa-gateway.connect');
            Route::get('/ticket-routing/check', [SettingController::class, 'waCheckTicketRouting'])->name('api.wa-gateway.ticket-routing.check');
            Route::post('/ticket-routing', [SettingController::class, 'waSaveTicketRouting'])->name('api.wa-gateway.ticket-routing');
        });

        // Payment Gateway — also restrict to superadmin (Xendit API keys live here)
        // Sidebar "Payment" entry: same page as Settings > Payment Gateway.
        Route::get('/payment-gateway', [PaymentGatewaySettingsController::class, 'index'])->name('modules.payment-gateway');
    });
});

// Auth Routes (Login, Register, etc.)
require __DIR__.'/auth.php';
