<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Upload extends Model
{
    protected $fillable = [
        'upload_id',
        'filename',
        'mime_type',
        'total_size',
        'uploaded_size',
        'checksum',
        'status',
        'chunks_received',
        'total_chunks',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'total_size' => 'integer',
            'uploaded_size' => 'integer',
            'total_chunks' => 'integer',
            'chunks_received' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public function images(): HasMany
    {
        return $this->hasMany(Image::class);
    }

    public static function generateUploadId(): string
    {
        return Str::uuid()->toString();
    }

    public function getReceivedChunks(): array
    {
        return $this->chunks_received ?? [];
    }

    public function markChunkReceived(int $chunkIndex): void
    {
        $chunks = $this->getReceivedChunks();
        if (!in_array($chunkIndex, $chunks)) {
            $chunks[] = $chunkIndex;
            $this->chunks_received = $chunks;
            $this->save();
        }
    }

    public function isComplete(): bool
    {
        return $this->status === 'completed' && $this->uploaded_size >= $this->total_size;
    }
}
