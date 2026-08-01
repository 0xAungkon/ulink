<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proxy_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('link_id')->constrained()->cascadeOnDelete();
            $table->string('visitor_key', 64);
            $table->text('cookies')->nullable();
            $table->timestamps();
            $table->unique(['link_id', 'visitor_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proxy_sessions');
    }
};
