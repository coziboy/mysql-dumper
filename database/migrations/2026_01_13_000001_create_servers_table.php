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
        Schema::create('servers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('host');
            $table->integer('port')->default(3306);
            $table->string('username');
            $table->text('password')->nullable(); // Will be encrypted in the model (optional)
            $table->string('database')->nullable();
            $table->string('charset')->default('utf8mb4');
            $table->string('collation')->default('utf8mb4_unicode_ci');
            $table->boolean('is_default')->default(false);
            $table->text('ssh_host')->nullable();
            $table->integer('ssh_port')->nullable()->default(22);
            $table->string('ssh_username')->nullable();
            $table->text('ssh_password')->nullable(); // Will be encrypted in the model
            $table->text('ssh_key_path')->nullable();
            $table->json('options')->nullable();
            $table->timestamps();

            $table->index('is_default');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('servers');
    }
};
