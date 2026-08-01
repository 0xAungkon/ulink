<?php

use App\Models\PublicDomain;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $baseUrl = PublicDomain::normalize((string) config('app.url'));
        $domain = PublicDomain::where('base_url', $baseUrl)->first();
        $hasDefault = PublicDomain::where('is_default', true)->exists();

        if ($domain) {
            $domain->update([
                'label' => $domain->label ?: 'Main domain',
                'is_active' => true,
                'is_default' => $domain->is_default || ! $hasDefault,
            ]);

            return;
        }

        PublicDomain::create([
            'label' => 'Main domain',
            'base_url' => $baseUrl,
            'is_active' => true,
            'is_default' => ! $hasDefault,
        ]);
    }

    public function down(): void
    {
        // This is user-editable configuration, so rollback does not delete it.
    }
};
