<?php

namespace App\Console\Commands;

use App\Models\Customer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class MigrateKtpPhotos extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ktp:migrate-to-storage {--dry-run : Show what would be done without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate KTP photos from external e-billing URLs to local Laravel storage';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🔄 Starting KTP Photo Migration...');

        // Get customers with external KTP URLs using cursor for memory efficiency
        $customers = Customer::whereNotNull('ktp_photo_url')
            ->where('ktp_photo_url', 'like', 'http%')
            ->cursor();

        $count = Customer::whereNotNull('ktp_photo_url')->where('ktp_photo_url', 'like', 'http%')->count();
        if ($count === 0) {
            $this->info('✅ No external KTP URLs found. All photos already migrated!');

            return 0;
        }

        $this->info("Found {$count} customers with external KTP URLs");

        if ($this->option('dry-run')) {
            $this->warn('🔍 DRY RUN MODE - No changes will be made');
            $this->newLine();
        }

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        $migrated = 0;
        $skipped = 0;
        $failed = [];

        foreach ($customers as $customer) {
            try {
                if ($this->option('dry-run')) {
                    $this->newLine();
                    $this->line("Would migrate: [{$customer->code}] {$customer->name}");
                    $this->line("  From: {$customer->ktp_photo_url}");
                    $filename = basename(parse_url($customer->ktp_photo_url, PHP_URL_PATH));
                    $this->line("  To:   ktp/{$filename}");
                    $migrated++;
                } else {
                    $result = $this->migratePhoto($customer);

                    if ($result === 'migrated') {
                        $migrated++;
                    } elseif ($result === 'skipped') {
                        $skipped++;
                    }
                }
            } catch (\Exception $e) {
                $failed[] = [
                    'customer' => $customer->code,
                    'name' => $customer->name,
                    'url' => $customer->ktp_photo_url,
                    'error' => $e->getMessage(),
                ];
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        // Summary
        $this->info('✅ Migration Complete!');
        $this->newLine();
        $this->table(
            ['Status', 'Count'],
            [
                ['Migrated', $migrated],
                ['Skipped', $skipped],
                ['Failed', count($failed)],
            ]
        );

        if (! empty($failed)) {
            $this->newLine();
            $this->error('❌ Failed Migrations:');
            $this->table(
                ['Customer', 'Name', 'URL', 'Error'],
                array_map(fn ($f) => [
                    $f['customer'],
                    strlen($f['name']) > 30 ? substr($f['name'], 0, 27).'...' : $f['name'],
                    strlen($f['url']) > 50 ? '...'.substr($f['url'], -47) : $f['url'],
                    strlen($f['error']) > 40 ? substr($f['error'], 0, 37).'...' : $f['error'],
                ], $failed)
            );
        }

        if (! $this->option('dry-run') && $migrated > 0) {
            $this->newLine();
            $this->info('📝 Next Steps:');
            $this->line('1. Verify photos are accessible at /storage/ktp/{filename}.jpg');
            $this->line('2. Run: php artisan storage:link (if not already done)');
            $this->line('3. Check customer detail pages in the browser');
        }

        return 0;
    }

    /**
     * Migrate a single customer's KTP photo
     */
    private function migratePhoto(Customer $customer): string
    {
        $url = $customer->ktp_photo_url;

        // Skip if not an external URL
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return 'skipped';
        }

        // Download photo with timeout
        $response = Http::timeout(10)
            ->withOptions(['verify' => false]) // Disable SSL verification for self-signed certs
            ->get($url);

        if (! $response->successful()) {
            throw new \Exception("HTTP {$response->status()}");
        }

        // Extract filename from URL
        $filename = basename(parse_url($url, PHP_URL_PATH));

        // Ensure filename is safe
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '', $filename);

        // Stream response directly to file to save memory
        $path = "ktp/{$filename}";
        Storage::disk(config('filesystems.default'))->put($path, $response->body());

        // Update customer record
        $customer->update([
            'ktp_external_url' => $url, // Backup original URL
            'ktp_photo_url' => $path,   // Update to local path
        ]);

        return 'migrated';
    }
}
