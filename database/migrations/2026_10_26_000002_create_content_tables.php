<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Single JSON documents served as-is: app_config, onboarding_questionnaire, support_resources_au ...
        Schema::create('content_documents', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->longText('body');
            $table->string('content_version', 64)->nullable();
            $table->string('etag', 64);
            $table->timestamps();
        });

        // One row per program; `body` is the full program JSON (phases, lessons, videos, activity, quiz).
        Schema::create('programs', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('title');
            $table->string('short')->nullable();
            $table->string('status', 32)->default('active'); // active | coming_soon | hidden
            $table->unsignedInteger('display_order')->default(0);
            $table->string('cover_image')->nullable();
            $table->string('content_version', 64)->nullable();
            $table->longText('body');
            $table->string('etag', 64);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('programs');
        Schema::dropIfExists('content_documents');
    }
};
