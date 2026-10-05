<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('paymobs', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('logo');
            $table->enum('type', ['live', 'test']);
            $table->string('callback');
            $table->string('api_key', 1000);
            $table->string('iframe_id');
            $table->string('integration_id');
            $table->string('Hmac');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('paymobs');
    }
};
