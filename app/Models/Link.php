<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Link extends Model
{
    protected $fillable = ['token_id', 'secret_hash', 'slug', 'destination_url', 'public_base_url', 'expires_at'];

    protected $hidden = ['secret_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    public function visits(): HasMany
    {
        return $this->hasMany(LinkVisit::class);
    }

    public function publicUrl(): string
    {
        return rtrim($this->public_base_url ?: url('/'), '/').'/'.$this->slug;
    }
}
