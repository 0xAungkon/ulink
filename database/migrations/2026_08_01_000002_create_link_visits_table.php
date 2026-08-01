<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('link_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('link_id')->constrained()->cascadeOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->string('country', 100)->nullable();
            $table->string('region', 100)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('browser', 100)->nullable();
            $table->string('device', 100)->nullable();
            $table->boolean('successful')->default(true)->index();
            $table->string('failure_reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('link_visits');
    }
};
