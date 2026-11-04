<?php

namespace App\Console\Commands;

use App\Models\Area;
use App\Models\User;
use Illuminate\Console\Command;

class AssignAdminAreas extends Command
{
    protected $signature = 'users:assign-admin-areas {email} {areas* : Area codes or IDs}';

    protected $description = 'Mark a user as admin and assign the areas they can access';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();
        if (! $user) {
            $this->error('User not found.');

            return self::FAILURE;
        }

        $tokens = collect($this->argument('areas'))->map(fn ($area) => (string) $area);
        $areas = Area::query()
            ->whereIn('code', $tokens)
            ->orWhereIn('id', $tokens->filter(fn ($area) => ctype_digit($area))->map(fn ($area) => (int) $area))
            ->get();

        $missing = $tokens
            ->reject(fn ($token) => $areas->contains('code', $token) || $areas->contains('id', (int) $token))
            ->values();

        if ($missing->isNotEmpty()) {
            $this->error('Unknown area(s): '.$missing->implode(', '));

            return self::FAILURE;
        }

        $user->forceFill(['role' => 'admin'])->save();
        $user->areas()->sync($areas->pluck('id'));

        $this->info("Assigned {$user->email} to ".$areas->pluck('name')->implode(', ').'.');

        return self::SUCCESS;
    }
}
