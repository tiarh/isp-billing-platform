<?php

namespace App\Services\Wa;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Measure-only timer for the Evolution webhook (plan #7, phase 0).
 *
 * It records how long one webhook request takes, per routing branch, and how often the same
 * WhatsApp message id arrives twice. It never changes routing, never skips processing and
 * never throws: every public method swallows \Throwable.
 *
 * Privacy: only a short hash of the message id and a coarse group role label are stored or
 * logged. Never a JID, phone number, push name or message text.
 */
final class WaWebhookTimer
{
    public const STATS_PREFIX = 'wa_webhook:stats:';

    public const SEEN_PREFIX = 'wa_webhook:seen:';

    /** Upper bounds (ms) of the latency buckets; the last bucket is open ended. */
    public const BUCKET_LABELS = ['<250ms', '<1s', '<5s', '<30s', '>=30s'];

    private const BRANCH_ROLE = [
        'psb_ktp_media' => 'psb',
        'psb_ktp_text' => 'psb',
        'migration_cmd' => 'psb',
        'psb_recap' => 'psb',
        'recap_topic' => 'psb',
        'migration_photo' => 'psb',
        'psb' => 'psb',
        'report_pppoe' => 'report',
        'report_recap' => 'report',
        'report_topic' => 'report',
        'unlisted_trigger' => 'unlisted',
        'technician' => 'technician',
        'attendance' => 'attendance',
        'none' => 'none',
    ];

    private int $startNs;

    private string $branch = 'none';

    /** @var array<string, int> accumulated nanoseconds per sub-timing */
    private array $subNs = [];

    /** @var array<string, int> */
    private array $open = [];

    private ?string $midHash = null;

    private int $messages = 0;

    private int $repeats = 0;

    private int $repeatAgeMax = 0;

    private bool $hasProxyHeader = false;

    private bool $finished = false;

    public static function start(Request $request): self
    {
        $timer = new self;
        try {
            foreach (['x-forwarded-for', 'x-real-ip', 'cf-connecting-ip', 'via', 'forwarded'] as $header) {
                if ($request->headers->has($header)) {
                    $timer->hasProxyHeader = true;
                    break;
                }
            }
        } catch (\Throwable) {
            // measurement only
        }

        return $timer;
    }

    private function __construct()
    {
        $this->startNs = hrtime(true);
    }

    /** First label wins, so a message that falls through to a later step keeps its main branch. */
    public function branch(string $label): void
    {
        try {
            if ($this->branch === 'none' && isset(self::BRANCH_ROLE[$label])) {
                $this->branch = $label;
            }
        } catch (\Throwable) {
        }
    }

    /** "#psb ktp #ID": with a photo or text only. */
    public function branchKtp(array $msg): void
    {
        try {
            $content = $msg['message'] ?? [];
            foreach (['ephemeralMessage', 'viewOnceMessage', 'viewOnceMessageV2'] as $wrapper) {
                if (! empty($content[$wrapper]['message']) && is_array($content[$wrapper]['message'])) {
                    $content = $content[$wrapper]['message'];
                }
            }
            $hasMedia = is_array($content) && (isset($content['imageMessage']) || isset($content['documentMessage']) || isset($content['documentWithCaptionMessage']));
            $this->branch($hasMedia ? 'psb_ktp_media' : 'psb_ktp_text');
        } catch (\Throwable) {
        }
    }

    /** Called once per message item: remembers the id hash and counts repeats. Never skips anything. */
    public function message(array $msg): void
    {
        try {
            $this->messages++;
            $id = $msg['key']['id'] ?? null;
            if (! is_string($id) || $id === '') {
                return;
            }
            $hash = sha1($id);
            $this->midHash ??= substr($hash, 0, 12);

            $now = time();
            if (! Cache::add(self::SEEN_PREFIX.$hash, $now, now()->addHours(6))) {
                $this->repeats++;
                $first = Cache::get(self::SEEN_PREFIX.$hash);
                if (is_numeric($first)) {
                    $this->repeatAgeMax = max($this->repeatAgeMax, $now - (int) $first);
                }
            }
        } catch (\Throwable) {
        }
    }

    public function begin(string $name): void
    {
        try {
            $this->open[$name] = hrtime(true);
        } catch (\Throwable) {
        }
    }

    public function end(string $name): void
    {
        try {
            if (isset($this->open[$name])) {
                $this->add($name, hrtime(true) - $this->open[$name]);
                unset($this->open[$name]);
            }
        } catch (\Throwable) {
        }
    }

    /** Add nanoseconds to a sub-timing (e.g. media_ms). */
    public function add(string $name, int $ns): void
    {
        try {
            $this->subNs[$name] = ($this->subNs[$name] ?? 0) + max(0, $ns);
        } catch (\Throwable) {
        }
    }

    /** @param  mixed  $response  the controller response (status read defensively) */
    public function finishResponse(mixed $response): void
    {
        $status = 200;
        try {
            if (is_object($response) && method_exists($response, 'getStatusCode')) {
                $status = (int) $response->getStatusCode();
            }
        } catch (\Throwable) {
        }
        $this->finish($status);
    }

    public function finish(int $status): void
    {
        if ($this->finished) {
            return;
        }
        $this->finished = true;

        try {
            $record = $this->record($status);
        } catch (\Throwable) {
            return;
        }

        try {
            $this->log($record);
        } catch (\Throwable) {
        }
        try {
            $this->store($record);
        } catch (\Throwable) {
        }
    }

    /** @return array<string, mixed> */
    private function record(int $status): array
    {
        $totalMs = (int) round((hrtime(true) - $this->startNs) / 1_000_000);
        $ms = fn (string $name): int => (int) round(($this->subNs[$name] ?? 0) / 1_000_000);

        return [
            'branch' => $this->branch,
            'role' => self::BRANCH_ROLE[$this->branch] ?? 'none',
            'status' => $status,
            'total_ms' => $totalMs,
            'chat_ms' => $ms('chat_ms'),
            'psb_branch_ms' => $ms('psb_branch_ms'),
            'technician_ms' => $ms('technician_ms'),
            'attendance_ms' => $ms('attendance_ms'),
            'media_ms' => $ms('media_ms'),
            'has_proxy_header' => $this->hasProxyHeader,
            'messages' => $this->messages,
            'repeats' => $this->repeats,
            'repeat_age_s' => $this->repeatAgeMax,
            'mid_hash' => $this->midHash,
        ];
    }

    /** @param  array<string, mixed>  $record */
    private function log(array $record): void
    {
        $threshold = (int) config('wa_webhook.log_ms', 300);
        if ($record['total_ms'] < $threshold) {
            return;
        }
        // Whitelisted keys only: no JID, phone, push name or text can get in here.
        Log::info('[WA-WEBHOOK] timing', [
            'branch' => $record['branch'],
            'role' => $record['role'],
            'status' => $record['status'],
            'total_ms' => $record['total_ms'],
            'chat_ms' => $record['chat_ms'],
            'psb_branch_ms' => $record['psb_branch_ms'],
            'technician_ms' => $record['technician_ms'],
            'attendance_ms' => $record['attendance_ms'],
            'media_ms' => $record['media_ms'],
            'has_proxy_header' => $record['has_proxy_header'],
            'messages' => $record['messages'],
            'repeat' => $record['repeats'] > 0,
            'repeat_age_s' => $record['repeat_age_s'],
            'mid_hash' => $record['mid_hash'],
        ]);
    }

    public static function bucketIndex(int $ms): int
    {
        return match (true) {
            $ms < 250 => 0,
            $ms < 1000 => 1,
            $ms < 5000 => 2,
            $ms < 30000 => 3,
            default => 4,
        };
    }

    public static function statsKey(?\DateTimeInterface $at = null): string
    {
        return self::STATS_PREFIX.($at ?? now())->format('YmdH');
    }

    /** @param  array<string, mixed>  $record */
    private function store(array $record): void
    {
        $key = self::statsKey();
        Cache::lock($key.':lock', 3)->block(1, function () use ($key, $record): void {
            $stats = Cache::get($key);
            if (! is_array($stats)) {
                $stats = ['branches' => [], 'status' => [], 'repeats' => 0, 'repeat_age_max_s' => 0, 'proxy' => 0, 'requests' => 0];
            }

            $b = $stats['branches'][$record['branch']] ?? [
                'count' => 0, 'sum_ms' => 0, 'max_ms' => 0,
                'buckets' => [0, 0, 0, 0, 0],
                'media_sum_ms' => 0, 'media_max_ms' => 0,
            ];
            $b['count']++;
            $b['sum_ms'] += $record['total_ms'];
            $b['max_ms'] = max($b['max_ms'], $record['total_ms']);
            $b['buckets'][self::bucketIndex($record['total_ms'])]++;
            $b['media_sum_ms'] += $record['media_ms'];
            $b['media_max_ms'] = max($b['media_max_ms'], $record['media_ms']);
            $stats['branches'][$record['branch']] = $b;

            $status = (string) $record['status'];
            $stats['status'][$status] = ($stats['status'][$status] ?? 0) + 1;
            $stats['requests']++;
            $stats['repeats'] += $record['repeats'];
            $stats['repeat_age_max_s'] = max($stats['repeat_age_max_s'], $record['repeat_age_s']);
            $stats['proxy'] += $record['has_proxy_header'] ? 1 : 0;

            Cache::put($key, $stats, now()->addDays(8));
        });
    }
}
