<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PublicDomain extends Model
{
    protected $fillable = ['label', 'base_url', 'is_active', 'is_default'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_default' => 'boolean'];
    }

    public static function normalize(string $value): string
    {
        $value = trim($value);
        if (! preg_match('#^https?://#i', $value)) {
            $value = 'https://'.$value;
        }

        return rtrim($value, '/');
    }
}
