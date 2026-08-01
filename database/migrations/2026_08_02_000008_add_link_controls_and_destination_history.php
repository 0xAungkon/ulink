<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('links', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->index()->after('delivery_mode');
        });

        Schema::create('link_destinations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('link_id')->constrained()->cascadeOnDelete();
            $table->text('url');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['link_id', 'created_at']);
        });

        DB::table('links')->orderBy('id')->each(function ($link) {
            DB::table('link_destinations')->insert([
                'link_id' => $link->id,
                'url' => $link->destination_url,
                'created_at' => $link->created_at,
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('link_destinations');

        Schema::table('links', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
