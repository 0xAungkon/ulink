<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_domains', function (Blueprint $table) {
            $table->id();
            $table->string('label')->nullable();
            $table->string('base_url')->unique();
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_default')->default(false)->index();
            $table->timestamps();
        });

        Schema::table('links', function (Blueprint $table) {
            $table->string('public_base_url')->nullable()->after('destination_url');
        });
    }

    public function down(): void
    {
        Schema::table('links', function (Blueprint $table) {
            $table->dropColumn('public_base_url');
        });

        Schema::dropIfExists('public_domains');
    }
};
