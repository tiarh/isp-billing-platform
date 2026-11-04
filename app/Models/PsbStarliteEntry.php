<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PsbStarliteEntry extends Model
{
    protected $table = 'psb_starlite_entries';

    protected $fillable = [
        'installed_on',
        'rw',
        'full_name',
        'pppoe_user',
        'sn',
        'service_type',
        'install_status',
        'tikor',
        'odp',
        'kendala',
        'pic',
        'source',
        'source_key',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'installed_on' => 'date',
            'meta' => 'array',
        ];
    }

    public function isSuccess(): bool
    {
        $s = strtolower(trim((string) $this->install_status));

        return in_array($s, ['success', 'sukses', 'ok', 'done', 'selesai'], true);
    }

    public function isPending(): bool
    {
        $s = strtolower(trim((string) $this->install_status));

        return in_array($s, ['pending', 'menunggu', 'progress', 'proses'], true);
    }
}
