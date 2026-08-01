<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('link_visits', function (Blueprint $table) {
            $table->text('user_agent')->nullable();
            $table->string('operating_system', 100)->nullable();
            $table->string('request_method', 12)->nullable();
            $table->text('request_path')->nullable();
            $table->text('referrer')->nullable();
            $table->string('accept_language', 255)->nullable();
            $table->text('accept_header')->nullable();
            $table->json('client_hints')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('link_visits', function (Blueprint $table) {
            $table->dropColumn([
                'user_agent',
                'operating_system',
                'request_method',
                'request_path',
                'referrer',
                'accept_language',
                'accept_header',
                'client_hints',
            ]);
        });
    }
};
