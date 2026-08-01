<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LinkVisit extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'ip_address', 'country', 'region', 'city', 'browser', 'device',
        'successful', 'failure_reason', 'created_at',
    ];

    protected function casts(): array
    {
        return ['successful' => 'boolean', 'created_at' => 'datetime'];
    }

    public function link(): BelongsTo
    {
        return $this->belongsTo(Link::class);
    }
}
