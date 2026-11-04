<?php

namespace Tests\Support\PsbGolden;

use Illuminate\Support\Testing\Fakes\QueueFake;

/**
 * Queue fake that remembers the global push order (the stock fake groups jobs by
 * class). The golden test runs the captured jobs first-in-first-out.
 */
final class PsbGoldenRecordingQueue extends QueueFake
{
    /** @var list<object> jobs waiting to run, in push order */
    public array $pending = [];

    public ?PsbGoldenRecorder $rec = null;

    public function push($job, $data = '', $queue = null)
    {
        parent::push($job, $data, $queue);
        if (is_object($job)) {
            $this->pending[] = $job;
            $this->rec?->add('job.dispatch', [
                'class' => get_class($job),
                'queue' => $job->queue ?? $queue,
                'delay' => self::delayBucket($job->delay ?? null),
                'job' => $job,
            ]);
        }

        return null;
    }

    /**
     * Delays carry random_int jitter (0-15 s on a 60-240 s base), which cannot be
     * seeded, so a delay is recorded as a 60 s bucket.
     */
    public static function delayBucket(mixed $delay): string
    {
        if ($delay === null) {
            return 'none';
        }
        if ($delay instanceof \DateTimeInterface) {
            $delay = max(0, $delay->getTimestamp() - now()->getTimestamp());
        }
        if ($delay instanceof \DateInterval) {
            $delay = (int) (new \DateTimeImmutable('@0'))->add($delay)->format('U');
        }
        $seconds = (int) $delay;
        if ($seconds < 60) {
            return '<60s';
        }
        $low = intdiv($seconds, 60) * 60;

        return $low.'-'.($low + 59).'s';
    }
}
