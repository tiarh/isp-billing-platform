<?php

namespace Tests\Support\MigrationGolden;

use App\Http\Controllers\OltC300Controller;
use App\Jobs\ExecuteCustomerMigrationWaJob;
use App\Jobs\ProcessCustomerMigrationWaJob;
use App\Models\Area;
use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\DeviceActionLog;
use App\Models\Olt;
use App\Models\OltOnu;
use App\Models\Package;
use App\Models\PsbDraft;
use App\Models\Router;
use App\Models\User;
use App\Services\CustomerMigrationMessageParser;
use App\Services\GenieAcsService;
use App\Services\HuaweiOltOnlyProvisioningService;
use App\Services\HuaweiPsbProvisioningService;
use App\Services\MikrotikService;
use App\Services\OltZteC300Service;
use App\Services\RadiusUserService;
use App\Support\TesseractRunner;
use Carbon\Carbon;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PsbGolden\PsbGoldenGenieAcsService;
use Tests\Support\PsbGolden\PsbGoldenHuaweiOltOnly;
use Tests\Support\PsbGolden\PsbGoldenOltController;
use Tests\Support\PsbGolden\PsbGoldenRadiusUserService;
use Tests\Support\PsbGolden\PsbGoldenRecorder;
use Tests\Support\PsbGolden\PsbGoldenRecordingQueue;

/**
 * Harness of the migration flow goldens (migration refactor M0, tests only).
 *
 * Every scenario drives the REAL container instances of CustomerMigrationService and the
 * PSB facade it calls, through the real jobs, controllers and public methods. Only the
 * edges are faked: Evolution (Http::fake), the MikroTik (one table per router), the OLT
 * controller, the Huawei SSH layer, the ZTE delete, GenieACS, RADIUS and the queue. No
 * migration subclass and no mock of our own services is used, so the same goldens prove
 * every extraction step behaviour-neutral.
 *
 * One ordered timeline per scenario: WA sends, device calls, job dispatches/runs, log lines
 * and the DB state after every step. Ids become ordinals, timestamps become <ts>, hashes
 * become <hash>, every password-shaped value becomes a masked length.
 *
 * Fixtures: tests/Fixtures/migration-flow/<name>.golden.json, written ONLY with the env
 * MIGRATION_FLOW_GOLDEN_WRITE=1 (never in CI). A golden change needs owner OK.
 */
trait MigrationGoldenHarness
{
    protected PsbGoldenRecorder $rec;

    protected PsbGoldenRecordingQueue $queue;

    protected MigrationGoldenMikrotikFake $mt;

    protected PsbGoldenOltController $olt;

    protected PsbGoldenHuaweiOltOnly $huawei;

    protected MigrationGoldenZteC300Service $zte;

    protected PsbGoldenGenieAcsService $acsFake;

    /** @var array<int, int> */
    protected array $migrationOrdinals = [];

    /** @var array<int, int> */
    protected array $customerOrdinals = [];

    /** @var array<int, int> */
    protected array $ledgerOrdinals = [];

    /** @var array<int, int> */
    protected array $userOrdinals = [];

    /** @var array<string, string> storage path => synthetic image name */
    protected array $storedNames = [];

    /** @var array<string, array{name: string, text: string}> */
    protected array $images = [];

    /** @var list<array<string, mixed>> */
    protected array $steps = [];

    /** @var array<string, array{0: string, 1: string, 2: string}> */
    protected array $transitions = [];

    /** @var array<string, Lock> */
    protected array $heldLocks = [];

    /** @var list<object> */
    protected array $ranJobs = [];

    protected int $messageSeq = 0;

    /** @var array<string, string> */
    protected array $lastSections = [];

    /** @var array<string, array<string, array<string, mixed>>> */
    protected array $lastRows = [];

    /** @var array{class: string, when: \Closure, message: string}|null the one failure that is armed right now */
    protected ?array $crashArmed = null;

    /** @var list<string> model classes that already carry the crash listener */
    protected array $crashListening = [];

    protected int $crashFired = 0;

    protected function bootMigrationGolden(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-20 03:00:00', 'UTC'));
        mt_srand(20261020);
        $evolutionKey = $this->canaryValue();
        config([
            'filesystems.default' => 'local',
            'services.evolution_api.api_key' => $evolutionKey,
            'services.evolution_api.webhook_secret' => $evolutionKey,
            'services.evolution_api.base_url' => 'http://evolution.test',
            'services.evolution_api.instance_name' => 'ops',
            'services.evolution.url' => 'http://evolution.test',
            'services.evolution.instance' => 'ops',
            'services.evolution.key' => $evolutionKey,
            'services.ocrspace.async_media_enabled' => false,
            'services.ocrspace.paddle_enabled' => false,
            'services.ocrspace.key' => '',
            'locks.wait_cap_seconds' => 0,
            'psb.olt_lock_wait_seconds' => 0,
            'olt-provisioning.dry_run' => false,
        ]);
        Storage::fake('local');
        Cache::flush();

        $this->rec = new PsbGoldenRecorder;
        $this->queue = new PsbGoldenRecordingQueue($this->app, [], Queue::getFacadeRoot());
        $this->queue->rec = $this->rec;
        Queue::swap($this->queue);

        $this->mt = new MigrationGoldenMikrotikFake($this->rec);
        $this->olt = new PsbGoldenOltController($this->rec);
        $this->huawei = new PsbGoldenHuaweiOltOnly($this->mt, $this->rec);
        $this->zte = new MigrationGoldenZteC300Service($this->rec);
        $this->app->instance(MikrotikService::class, $this->mt);
        $this->app->instance(OltC300Controller::class, $this->olt);
        $this->app->instance(HuaweiOltOnlyProvisioningService::class, $this->huawei);
        $this->app->forgetInstance(HuaweiPsbProvisioningService::class);
        $this->app->instance(OltZteC300Service::class, $this->zte);
        $this->acsFake = new PsbGoldenGenieAcsService($this->rec);
        $this->app->instance(GenieAcsService::class, $this->acsFake);
        $this->app->instance(RadiusUserService::class, new PsbGoldenRadiusUserService($this->rec));

        TesseractRunner::fakeRunner(function (array $cmd, int $timeout): array {
            if (($cmd[0] ?? '') !== 'tesseract') {
                return ['text' => null, 'timed_out' => false, 'ms' => 1];
            }
            if (in_array('--list-langs', $cmd, true)) {
                return ['text' => "List of available languages (2):\nind\neng", 'timed_out' => false, 'ms' => 1];
            }
            $bytes = is_file((string) ($cmd[1] ?? '')) ? (string) file_get_contents((string) $cmd[1]) : '';
            $text = (string) (($this->images[sha1($bytes)] ?? [])['text'] ?? '');

            return ['text' => $text === '' ? null : $text, 'timed_out' => false, 'ms' => 100];
        });

        Event::listen(MessageLogged::class, function ($event): void {
            if (str_starts_with((string) $event->message, '[MIGRASI]')) {
                $this->rec->add('log.migrasi', ['level' => $event->level, 'message' => $event->message, 'context' => array_diff_key($event->context, ['exception' => 1])]);
            }
        });

        Http::fake(function (HttpRequest $request) {
            $url = $request->url();
            if (str_contains($url, '/message/sendText/')) {
                $data = $request->data();
                $this->rec->add('wa.send', [
                    'group' => $data['number'] ?? null,
                    'text' => $data['text'] ?? null,
                    'mentions' => $data['mentioned'] ?? [],
                ]);

                return Http::response(['key' => ['id' => 'OUT-FAKE'], 'status' => 'PENDING']);
            }
            $parts = parse_url($url);
            $this->rec->add('http.unexpected', [
                'method' => $request->method(),
                'host' => $parts['host'] ?? '',
                'path' => $parts['path'] ?? '',
            ]);

            return Http::response(['error' => 'unexpected fake'], 404);
        });
    }

    protected function shutdownMigrationGolden(): void
    {
        foreach ($this->heldLocks as $lock) {
            try {
                $lock->release();
            } catch (\Throwable) {
            }
        }
        $this->crashArmed = null;
        TesseractRunner::fakeRunner(null);
        Carbon::setTestNow();
    }

    /**
     * Arms ONE failure: the next save of $modelClass for which $when($model) is true throws a
     * RuntimeException($message), and the failure is disarmed. The listener is an ordinary Eloquent
     * saving listener added next to the app's own (never flushEventListeners); it stays registered
     * but does nothing once disarmed, and shutdownMigrationGolden() disarms it. The failure is
     * recorded as a "crash.injected" event so a golden proves it really fired.
     *
     * @param  class-string<Model>  $modelClass
     */
    protected function crashOnce(string $modelClass, \Closure $when, string $message): void
    {
        $this->crashArmed = ['class' => $modelClass, 'when' => $when, 'message' => $message];
        if (in_array($modelClass, $this->crashListening, true)) {
            return;
        }
        $this->crashListening[] = $modelClass;
        $modelClass::saving(function (Model $model): void {
            $armed = $this->crashArmed;
            if ($armed === null || ! $model instanceof $armed['class'] || ! ($armed['when'])($model)) {
                return;
            }
            $this->crashArmed = null;
            $this->crashFired++;
            $this->rec->add('crash.injected', ['model' => class_basename($model), 'message' => $armed['message']]);

            throw new \RuntimeException($armed['message']);
        });
    }

    /** How many armed failures have fired so far; a scenario asserts it so a crash step can never be vacuous. */
    protected function crashesFired(): int
    {
        return $this->crashFired;
    }

    // ───────────────────────── steps ─────────────────────────

    /** @param array<string, mixed> $payload */
    protected function inbound(string $label, array $payload): void
    {
        $this->step($label, $this->triggerFor($payload), function () use ($payload): void {
            $this->runJob(new ProcessCustomerMigrationWaJob($payload));
        });
    }

    /** Runs captured jobs first-in-first-out; $max limits how many run in this step. */
    protected function drainJobs(string $label, ?int $max = null, ?string $class = null): void
    {
        $this->step($label, 'jobs', function () use ($max, $class): void {
            $ran = 0;
            while ($ran < ($max ?? 20)) {
                $index = null;
                foreach ($this->queue->pending as $i => $candidate) {
                    if ($class === null || $candidate instanceof $class) {
                        $index = $i;
                        break;
                    }
                }
                if ($index === null) {
                    break;
                }
                $job = $this->queue->pending[$index];
                array_splice($this->queue->pending, $index, 1);
                $this->ranJobs[] = $job;
                $this->runJob($job);
                $ran++;
            }
        });
    }

    protected function runJob(object $job): void
    {
        $this->rec->add('job.run', ['class' => get_class($job)]);
        if (method_exists($job, 'withFakeQueueInteractions')) {
            $job->withFakeQueueInteractions();
        }
        try {
            app()->call([$job, 'handle']);
        } catch (\Throwable $e) {
            $this->rec->add('job.exception', ['class' => get_class($job), 'exception' => class_basename($e), 'message' => $e->getMessage()]);
        }
        $fake = $job->job ?? null;
        if ($fake !== null && method_exists($fake, 'isReleased') && $fake->isReleased()) {
            $this->rec->add('job.released', ['class' => get_class($job)]);
        }
        if ($job instanceof ShouldBeUnique && method_exists($job, 'uniqueId')) {
            Cache::lock('laravel_unique_job:'.get_class($job).':'.$job->uniqueId())->forceRelease();
        }
    }

    protected function step(string $label, string $trigger, \Closure $action): void
    {
        $this->indexRows();
        $before = $this->statusMap();
        $action();
        $this->indexRows();
        $after = $this->statusMap();
        $events = $this->rec->drain();

        if ($trigger === 'jobs') {
            $classes = [];
            foreach ($events as $e) {
                if (($e['type'] ?? '') === 'job.run') {
                    $classes[class_basename((string) $e['class'])] = true;
                }
            }
            ksort($classes);
            $trigger = 'job:'.implode('+', array_keys($classes));
        }

        $changes = [];
        foreach ($after as $ordinal => $status) {
            $old = $before[$ordinal] ?? null;
            if ($old !== $status) {
                $changes[] = ['migration' => $ordinal, 'from' => $old ?? '(none)', 'to' => $status];
                $this->transitions[($old ?? '(none)').'>'.$status.'>'.$trigger] = [$old ?? '(none)', $status, $trigger];
            }
        }

        // Row sections show a row in full the first time and then only the keys that changed (the timeline
        // only its new entries); the other sections are written only when they differ from the previous step.
        $db = [];
        foreach ($this->snapshot() as $section => $view) {
            if (in_array($section, ['migrations', 'customers', 'ledgers', 'olt_onus'], true)) {
                $db[$section] = $this->diffRows($section, $view);

                continue;
            }
            $encoded = json_encode($view);
            $db[$section] = $encoded === ($this->lastSections[$section] ?? null) ? 'unchanged' : $view;
            $this->lastSections[$section] = $encoded;
        }
        $this->steps[] = [
            'label' => $label,
            'trigger' => $trigger,
            'events' => array_map(fn (array $e): array => $this->renderEvent($e), $events),
            'status_changes' => $changes,
            'db' => count(array_filter($db, fn ($v): bool => $v !== 'unchanged')) === 0 ? 'unchanged' : $db,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>|string
     */
    protected function diffRows(string $section, array $rows): array|string
    {
        $previous = $this->lastRows[$section] ?? [];
        $current = [];
        $out = [];
        foreach ($rows as $row) {
            $key = (string) ($row['ord'] ?? (($row['olt'] ?? '').'|'.($row['onu_ref'] ?? '')));
            $current[$key] = $row;
            $before = $previous[$key] ?? null;
            $changed = [];
            foreach ($row as $field => $value) {
                if ($field === 'ord') {
                    continue;
                }
                if ($field === 'meta' && is_array($value)) {
                    $metaChanged = [];
                    $beforeMeta = is_array($before['meta'] ?? null) ? $before['meta'] : [];
                    foreach ($value as $metaKey => $metaValue) {
                        if (! array_key_exists($metaKey, $beforeMeta)) {
                            $metaChanged[$metaKey] = $metaValue;
                        } elseif (json_encode($beforeMeta[$metaKey]) !== json_encode($metaValue)) {
                            $old = $beforeMeta[$metaKey];
                            $appendOnly = $metaKey === 'timeline' && is_array($metaValue) && is_array($old)
                                && json_encode(array_slice($metaValue, 0, count($old))) === json_encode($old);
                            $metaChanged[$metaKey] = $appendOnly ? ['added' => array_slice($metaValue, count($old))] : $metaValue;
                        }
                    }
                    foreach (array_keys(array_diff_key($beforeMeta, $value)) as $metaKey) {
                        $metaChanged[$metaKey] = '<removed>';
                    }
                    if ($metaChanged !== []) {
                        $changed['meta'] = $metaChanged;
                    }

                    continue;
                }
                if ($before === null || json_encode($before[$field] ?? null) !== json_encode($value)) {
                    $changed[$field] = $value;
                }
            }
            if ($changed !== []) {
                $out[] = ['row' => $key] + ($before === null ? ['new' => true] : []) + $changed;
            }
        }
        foreach (array_keys(array_diff_key($previous, $current)) as $goneKey) {
            $out[] = ['row' => (string) $goneKey, 'removed' => true];
        }
        $this->lastRows[$section] = $current;

        return $out === [] ? 'unchanged' : $out;
    }

    /** The kind of WA message as the parser reads it: the "trigger" of the transition table. */
    protected function triggerFor(array $payload): string
    {
        $p = (new CustomerMigrationMessageParser)->parse((string) ($payload['text'] ?? ''));
        if (! ($p['is_migrasi'] ?? false)) {
            return 'ignored';
        }
        foreach ([
            'is_help' => 'cmd:help', 'is_status' => 'cmd:status', 'is_ktp' => 'cmd:ktp', 'is_phone_update' => 'cmd:hp',
            'is_sn_update' => 'cmd:sn', 'is_eksekusi' => 'cmd:eksekusi', 'is_konfirmasi' => 'cmd:konfirmasi', 'is_pilih' => 'cmd:pilih',
            'is_cek' => 'cmd:cek', 'is_batal' => 'cmd:batal', 'is_lanjut' => 'cmd:lanjut',
        ] as $flag => $trigger) {
            if (! empty($p[$flag])) {
                return $trigger;
            }
        }

        return 'intake';
    }

    protected function nextMessageId(): string
    {
        return sprintf('FAKE-MIG-%03d', ++$this->messageSeq);
    }

    /** A public service call made directly (a command, a linker, a job hook); its result is recorded. */
    protected function callPublic(string $label, string $trigger, \Closure $fn): void
    {
        $this->step($label, $trigger, function () use ($fn): void {
            try {
                $result = $fn();
            } catch (\Throwable $e) {
                $result = ['exception' => class_basename($e), 'message' => $e->getMessage()];
            }
            $this->rec->add('call.result', ['result' => $result instanceof CustomerMigration ? ['migration_id' => (int) $result->id, 'status' => $result->status] : $result]);
        });
    }

    /**
     * Fails when a migration lock the scenario took (or the code under test took) is still held.
     *
     * @param  list<string>  $extraKeys  more lock keys to probe (an OLT or PON lock), after the migration keys
     */
    protected function probeLocksFree(array $extraKeys = []): void
    {
        $this->assertSame([], array_keys($this->heldLocks), 'the scenario forgot to release a lock it held');
        $this->step('locks-free probe: no migration lock is left held', 'probe', function () use ($extraKeys): void {
            $keys = ['customer-migration-wa-live-active'];
            foreach (CustomerMigration::query()->orderBy('id')->pluck('id') as $id) {
                array_push($keys, 'customer-migration-device-'.$id, 'customer-migration-'.$id, 'customer-migration-review-'.$id);
            }
            array_push($keys, ...$extraKeys);
            $busy = [];
            foreach ($keys as $key) {
                $probe = Cache::lock($key, 5);
                if ($probe->get()) {
                    $probe->release();
                } else {
                    $busy[] = $key;
                }
            }
            $this->rec->add('locks.probe', ['checked' => count($keys), 'busy' => $busy]);
            $this->assertSame([], $busy, 'migration locks left held');
        });
    }

    protected function hold(string $key): void
    {
        $lock = Cache::lock($key, 600);
        $this->assertTrue($lock->get(), 'fixture lock '.$key);
        $this->heldLocks[$key] = $lock;
    }

    protected function release(string $key): void
    {
        $this->heldLocks[$key]->release();
        unset($this->heldLocks[$key]);
    }

    /** Real id of the n-th migration (1-based) in creation order. */
    protected function migrationId(int $ordinal): int
    {
        $this->indexRows();
        $id = array_search($ordinal, $this->migrationOrdinals, true);
        $this->assertNotFalse($id, 'no migration with ordinal '.$ordinal);

        return (int) $id;
    }

    protected function indexRows(): void
    {
        foreach (CustomerMigration::query()->orderBy('id')->pluck('id') as $id) {
            $this->migrationOrdinals[(int) $id] ??= count($this->migrationOrdinals) + 1;
        }
        foreach (Customer::query()->orderBy('id')->pluck('id') as $id) {
            $this->customerOrdinals[(int) $id] ??= count($this->customerOrdinals) + 1;
        }
        foreach (DeviceActionLog::query()->where('target_type', 'customer_migration')->orderBy('id')->pluck('id') as $id) {
            $this->ledgerOrdinals[(int) $id] ??= count($this->ledgerOrdinals) + 1;
        }
        foreach (User::query()->orderBy('id')->pluck('id') as $id) {
            $this->userOrdinals[(int) $id] ??= count($this->userOrdinals) + 1;
        }
    }

    /** @return array<int, string> */
    protected function statusMap(): array
    {
        $out = [];
        foreach (CustomerMigration::query()->orderBy('id')->get(['id', 'status']) as $m) {
            $out[$this->migrationOrdinals[(int) $m->id]] = (string) $m->status;
        }

        return $out;
    }

    // ───────────────────────── rendering and scrubbing ─────────────────────────

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    protected function renderEvent(array $event): array
    {
        if (($event['type'] ?? '') === 'job.dispatch') {
            $job = $event['job'];
            $event['unique'] = (is_object($job) && method_exists($job, 'uniqueId')) ? $job->uniqueId() : null;
            $event['args'] = $job instanceof ExecuteCustomerMigrationWaJob
                ? ['migrationId' => $job->migrationId, 'payload' => $job->payload]
                : ($job instanceof ProcessCustomerMigrationWaJob ? ['payload' => $job->payload] : []);
            unset($event['job']);
        }

        return $this->scrub($event);
    }

    protected function scrub(mixed $value, string $parent = ''): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $k2 = is_string($k) ? $this->scrubString($k) : $k;
                $out[$k2] = $this->scrubKeyed((string) $k, $v, $parent);
            }
            if (! array_is_list($out)) {
                ksort($out);
            }

            return $out;
        }
        if (is_string($value)) {
            return $this->scrubString($value);
        }

        return $value;
    }

    protected function scrubKeyed(string $key, mixed $value, string $parent = ''): mixed
    {
        // A bare "id" is read by the block it sits in (customer snapshot, migration summary, acting admin).
        if ($key === 'id' && is_int($value)) {
            if (in_array($parent, ['customer_before', 'customer_after', 'before', 'after', 'customer', 'before_snapshot', 'proposed_after_snapshot'], true) && isset($this->customerOrdinals[$value])) {
                return '<customer'.$this->customerOrdinals[$value].'>';
            }
            if ($parent === 'ledgers' && isset($this->ledgerOrdinals[$value])) {
                return '<ledger'.$this->ledgerOrdinals[$value].'>';
            }
            if ($parent === 'migration' && isset($this->migrationOrdinals[$value])) {
                return '<migration'.$this->migrationOrdinals[$value].'>';
            }
            if ($parent === 'actor' && isset($this->userOrdinals[$value])) {
                return '<user'.$this->userOrdinals[$value].'>';
            }
            // Row ids of other tables (import rows, PSB drafts, ONU snapshots) shift with whatever ran before.
            if (! in_array($parent, ['olt', 'router', 'source_router', 'target_router'], true)) {
                return '<id>';
            }
        }
        if ($parent === 'actor' && $key === 'name') {
            return '<actor name>';
        }
        if ((str_ends_with($key, '_at') || $key === 'at') && (is_string($value) || $value === null || $value instanceof \DateTimeInterface)) {
            return $value === null ? null : '<ts>';
        }
        if (in_array($key, ['token', 'ocr_ms', 'per_image_ms', 'size'], true) || str_ends_with($key, '_ms') || str_starts_with($key, 'duration')) {
            return '<'.$key.'>';
        }
        if (preg_match('/(^|_)(password|passwd|pass|secret|api_?key|token)$/i', $key) === 1 && (is_string($value) || is_int($value)) && ! str_starts_with((string) $value, '<pw') && ! in_array($value, ['live', 'missing'], true)) {
            return '<pw len='.strlen((string) $value).'>';
        }
        if (is_int($value) || (is_string($value) && ctype_digit($value) && $value !== '')) {
            $n = (int) $value;
            if (preg_match('/(^|_)migration_?id$/i', $key) === 1 || $key === 'migrationId' || $key === 'record_id') {
                return isset($this->migrationOrdinals[$n]) ? '<migration'.$this->migrationOrdinals[$n].'>' : $value;
            }
            if (preg_match('/(^|_)customer_id$/', $key) === 1) {
                return isset($this->customerOrdinals[$n]) ? '<customer'.$this->customerOrdinals[$n].'>' : $value;
            }
            if (preg_match('/ledger_id$/', $key) === 1 || $key === 'ledger_superseded_id') {
                return isset($this->ledgerOrdinals[$n]) ? '<ledger'.$this->ledgerOrdinals[$n].'>' : $value;
            }
            if (in_array($key, ['requested_by', 'actor_id', 'blocked_by', 'user_id', 'causer_id'], true)) {
                return isset($this->userOrdinals[$n]) ? '<user'.$this->userOrdinals[$n].'>' : $value;
            }
            if ($key === 'target_id') {
                return isset($this->migrationOrdinals[$n]) ? '<migration'.$this->migrationOrdinals[$n].'>' : $value;
            }
            if (in_array($key, ['package_id'], true)) {
                return 'package:'.(Package::query()->whereKey($n)->value('code') ?? '?');
            }
            if (in_array($key, ['area_id'], true)) {
                return 'area:'.(Area::query()->whereKey($n)->value('code') ?? '?');
            }
        }
        if ($key === 'previous_order' && is_array($value)) {
            return array_map(fn ($id): string => isset($this->customerOrdinals[(int) $id]) ? '<customer'.$this->customerOrdinals[(int) $id].'>' : (string) $id, $value);
        }

        // A list item reads the block of its list (traces.ledgers[0] is read as "ledgers").
        return $this->scrub($value, ctype_digit($key) ? $parent : $key);
    }

    protected function scrubString(string $value): string
    {
        if (isset($this->storedNames[$value])) {
            return '<file:'.$this->storedNames[$value].'>';
        }
        // The public URL of such a file (a customer KTP link after the sync) carries the same random file name.
        if (preg_match('#^/storage/(migrasi/\d{4}/\d{2}/[A-Za-z0-9_-]+\.[a-z]+)$#', $value, $url) === 1) {
            return $this->scrubString($url[1]);
        }
        if (preg_match('#^migrasi/\d{4}/\d{2}/[A-Za-z0-9_-]+\.[a-z]+$#', $value)) {
            $disk = Storage::disk('local');
            if ($disk->exists($value)) {
                $image = $this->images[sha1((string) $disk->get($value))] ?? null;

                return '<file:'.($image['name'] ?? 'unknown').'>';
            }
        }
        // A key such as password_action next to a user name is read as a credential pair by secret scanners.
        $value = (string) preg_replace('/\bpassword_/i', 'pw_', $value);
        // Passwords in activation texts: the golden keeps that the line is there and its length, never the value.
        $value = (string) preg_replace_callback('/((?:password|pass)(?:\s+PPPoE)?\s*:\s*)([^\s\\\\]+)/i', fn (array $m): string => $m[1].(str_starts_with($m[2], '<pw') ? $m[2] : '<pw len='.strlen($m[2]).'>'), $value);
        $value = (string) preg_replace('/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})?/', '<ts>', $value);
        $value = (string) preg_replace('/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/', '<uuid>', $value);
        $value = (string) preg_replace('/\b(?=[0-9]*[a-f])[0-9a-f]{16,64}\b/', '<hash>', $value);
        $value = (string) preg_replace('/\bMIG-[0-9A-F]{12}\b/', 'MIG-<ref>', $value);
        $value = (string) preg_replace_callback('/\bledger #(\d+)/', fn (array $m): string => isset($this->ledgerOrdinals[(int) $m[1]]) ? 'ledger #<ledger'.$this->ledgerOrdinals[(int) $m[1]].'>' : $m[0], $value);
        $value = (string) preg_replace_callback('/\(ID (\d+)\)/', fn (array $m): string => isset($this->customerOrdinals[(int) $m[1]]) ? '(ID <customer'.$this->customerOrdinals[(int) $m[1]].'>)' : $m[0], $value);
        $value = (string) preg_replace_callback('/\b(migration-device|migration-wa-live|wa-live-migration|customer-migration-device|customer-migration-review|customer-migration)([:-])(\d+)\b/', fn (array $m): string => isset($this->migrationOrdinals[(int) $m[3]]) ? $m[1].$m[2].'<migration'.$this->migrationOrdinals[(int) $m[3]].'>' : $m[0], $value);
        $value = (string) preg_replace_callback('/\bretry:(\d+)\b/', fn (array $m): string => isset($this->ledgerOrdinals[(int) $m[1]]) ? 'retry:<ledger'.$this->ledgerOrdinals[(int) $m[1]].'>' : $m[0], $value);

        return (string) preg_replace_callback('/#(\d+)\b/', fn (array $m): string => isset($this->migrationOrdinals[(int) $m[1]]) ? '#<migration'.$this->migrationOrdinals[(int) $m[1]].'>' : $m[0], $value);
    }

    // ───────────────────────── snapshot ─────────────────────────

    /** @return array<string, mixed> */
    protected function snapshot(): array
    {
        $routers = Router::query()->pluck('name', 'id')->all();
        $olts = Olt::query()->pluck('code', 'id')->all();
        $packages = Package::query()->pluck('code', 'id')->all();

        $migrations = [];
        foreach (CustomerMigration::query()->orderBy('id')->get() as $m) {
            $migrations[] = $this->scrub([
                'ord' => $this->migrationOrdinals[(int) $m->id],
                'status' => $m->status,
                'source' => $m->source,
                'last_error' => $m->last_error,
                'sender' => $m->sender_jid,
                'group' => $m->group_jid,
                'pppoe_user' => $m->pppoe_user,
                'full_name' => $m->full_name,
                'phone' => $m->phone,
                'customer' => $m->customer_id ? 'customer'.($this->customerOrdinals[(int) $m->customer_id] ?? '?') : null,
                'old_router' => $routers[$m->old_router_id] ?? null,
                'router' => $routers[$m->router_id] ?? null,
                'old_olt' => $olts[$m->old_olt_id] ?? null,
                'new_olt' => $olts[$m->new_olt_id] ?? null,
                'old_sn' => $m->old_sn,
                'new_sn' => $m->new_sn,
                'old_onu_ref' => $m->old_onu_ref,
                'new_onu_ref' => $m->new_onu_ref,
                'new_pon_port' => $m->new_pon_port,
                'vlan' => $m->vlan,
                'tr069_vlan' => $m->tr069_vlan,
                'ktp_photo' => $m->ktp_photo_path,
                'olt_at' => $m->olt_at !== null ? '<ts>' : null,
                'synced_at' => $m->synced_at !== null ? '<ts>' : null,
                'old_onu_deleted_at' => $m->old_onu_deleted_at !== null ? '<ts>' : null,
                'meta' => $this->cleanMeta(is_array($m->meta) ? $m->meta : [], $routers, $olts),
            ]);
        }

        $customers = [];
        foreach (Customer::query()->orderBy('id')->get() as $c) {
            $customers[] = $this->scrub([
                'ord' => $this->customerOrdinals[(int) $c->id],
                'name' => $c->name,
                'phone' => $c->phone,
                'pppoe_user' => $c->pppoe_user,
                'password_set' => (string) $c->pppoe_password !== '',
                'status' => (string) $c->status,
                'router' => $routers[$c->router_id] ?? null,
                'olt' => $olts[$c->olt_id] ?? null,
                'package' => $packages[$c->package_id] ?? null,
                'profile' => $c->mikrotik_profile,
                'onu_serial' => $c->onu_serial,
                'olt_port_label' => $c->olt_port_label,
                'odp_name' => $c->odp_name,
                'local' => $c->pppoe_local_address,
                'remote' => $c->pppoe_remote_address,
                'sync' => $c->mikrotik_sync_status,
                'address' => $c->address,
                'ktp_photo' => $c->ktp_photo_url,
            ]);
        }

        $ledgers = [];
        foreach (DeviceActionLog::query()->where('target_type', 'customer_migration')->orderBy('id')->get() as $l) {
            $ledgers[] = $this->scrub([
                'ord' => $this->ledgerOrdinals[(int) $l->id],
                'migration' => $this->migrationOrdinals[(int) $l->target_id] ?? $l->target_id,
                'action' => $l->action,
                'status' => $l->status,
                'idempotency_key' => $l->idempotency_key,
                'payload' => $l->payload,
                'result' => $l->result,
                'rollback_hint' => $l->rollback_hint,
                'executed' => $l->executed_at !== null,
                'verified' => $l->verified_at !== null,
            ]);
        }

        $onus = [];
        foreach (OltOnu::query()->orderBy('olt_id')->orderBy('onu_ref')->get() as $o) {
            $onus[] = $this->scrub(['olt' => $olts[$o->olt_id] ?? null, 'onu_ref' => $o->onu_ref, 'serial' => $o->serial_number, 'name' => $o->onu_name, 'state' => $o->state]);
        }

        return [
            'migrations' => $migrations,
            'customers' => $customers,
            'ledgers' => $ledgers,
            'olt_onus' => $onus,
            'mikrotik' => $this->scrub($this->mt->tablesForSnapshot($routers)),
            'psb_drafts' => PsbDraft::query()->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<int, string>  $routers
     * @param  array<int, string>  $olts
     * @return array<string, mixed>
     */
    protected function cleanMeta(array $meta, array $routers, array $olts): array
    {
        foreach (['source_router_id', 'target_router_id'] as $key) {
            if (isset($meta['router_mapping'][$key])) {
                $meta['router_mapping'][$key] = $routers[(int) $meta['router_mapping'][$key]] ?? $meta['router_mapping'][$key];
            }
        }
        if (isset($meta['device_action_preflight']) && is_array($meta['device_action_preflight'])) {
            unset($meta['device_action_preflight']['checked_at']);
        }

        return $meta;
    }

    // ───────────────────────── golden compare ─────────────────────────

    protected function goldenPath(string $name): string
    {
        return dirname(__DIR__, 2).'/Fixtures/migration-flow/'.$name.'.golden.json';
    }

    protected function compareToGolden(string $name, string $recorded): void
    {
        $path = $this->goldenPath($name);
        if (getenv('MIGRATION_FLOW_GOLDEN_WRITE') === '1') {
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0775, true);
            }
            file_put_contents($path, $recorded);
            $this->addToAssertionCount(1);

            return;
        }
        $this->assertFileExists($path, "No golden for {$name}. Record it on main with MIGRATION_FLOW_GOLDEN_WRITE=1 (needs owner OK).");
        $this->assertSame(str_replace("\r\n", "\n", (string) file_get_contents($path)), $recorded, "Migration flow behaviour changed for scenario {$name}");
    }

    /** @param array<string, mixed> $data */
    protected function encode(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }

    /** @return array<string, mixed> */
    protected function recordedScenario(string $name): array
    {
        return ['scenario' => $name, 'steps' => $this->steps, 'transitions' => array_values($this->transitions)];
    }
}
