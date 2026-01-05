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
        Schema::create('uploads', function (Blueprint $table) {
            $table->id();
            $table->string('upload_id')->unique(); // Unique identifier for the upload session
            $table->string('filename');
            $table->string('mime_type');
            $table->bigInteger('total_size'); // Total file size in bytes
            $table->bigInteger('uploaded_size')->default(0); // Bytes uploaded so far
            $table->string('checksum')->nullable(); // Expected checksum (SHA256)
            $table->string('status')->default('pending'); // pending, uploading, completed, failed
            $table->text('chunks_received')->nullable(); // JSON array of received chunk indices
            $table->integer('total_chunks')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            
            $table->index('upload_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('uploads');
    }
};
