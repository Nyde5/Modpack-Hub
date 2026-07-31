<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modpackhub_installations', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('server_id');
            $table->enum('source', ['modrinth', 'curseforge', 'url']);
            $table->string('pack_id')->nullable();
            $table->string('version_id')->nullable();
            $table->string('pack_name');
            $table->string('mc_version')->nullable();
            $table->string('loader')->nullable();
            $table->enum('status', [
                'pending', 'backing_up', 'switching_egg', 'installing',
                'restoring_egg', 'completed', 'failed', 'restored',
            ])->default('pending');
            $table->text('error_message')->nullable();
            $table->string('backup_ref')->nullable();
            $table->json('egg_snapshot')->nullable();
            $table->timestamps();

            $table->foreign('server_id')->references('id')->on('servers')->onDelete('cascade');
            $table->index(['server_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modpackhub_installations');
    }
};
