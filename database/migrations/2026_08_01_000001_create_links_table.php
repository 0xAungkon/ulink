<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('links', function (Blueprint $table) {
            $table->id();
            $table->string('token_id', 40)->unique();
            $table->string('secret_hash', 64);
            $table->string('slug', 24)->unique();
            $table->text('destination_url');
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('links');
    }
};
