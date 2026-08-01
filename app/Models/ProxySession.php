<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProxySession extends Model
{
    protected $fillable = ['link_id', 'visitor_key', 'cookies'];

    protected function casts(): array
    {
        return ['cookies' => 'encrypted:array'];
    }

    public function link(): BelongsTo
    {
        return $this->belongsTo(Link::class);
    }
}
