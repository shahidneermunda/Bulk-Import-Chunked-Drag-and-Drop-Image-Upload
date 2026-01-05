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
        Schema::create('images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('upload_id')->constrained('uploads')->cascadeOnDelete();
            $table->string('filename'); // Original filename
            $table->string('path'); // Storage path
            $table->string('mime_type');
            $table->bigInteger('size'); // File size in bytes
            $table->string('checksum'); // SHA256 checksum
            $table->integer('width')->nullable();
            $table->integer('height')->nullable();
            $table->json('variants')->nullable(); // JSON with variant paths: {256: path, 512: path, 1024: path}
            $table->timestamps();
            
            $table->index('upload_id');
            $table->index('checksum');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('images');
    }
};
