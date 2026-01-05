<?php

namespace App\Http\Controllers;

use App\Models\Upload;
use App\Models\Image;
use App\Models\Product;
use App\Services\ImageProcessingService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ChunkedUploadController extends Controller
{
    private const CHUNK_SIZE = 1024 * 1024; // 1MB chunks

    public function __construct(
        private ImageProcessingService $imageProcessingService
    ) {}

    /**
     * Initialize a new chunked upload
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function init(Request $request): JsonResponse
    {
        $request->validate([
            'filename' => 'required|string|max:255',
            'total_size' => 'required|integer|min:1',
            'mime_type' => 'required|string',
            'checksum' => 'nullable|string|size:64', // SHA256 hex string
            'total_chunks' => 'required|integer|min:1',
        ]);

        try {
            $uploadId = Upload::generateUploadId();

            $upload = Upload::create([
                'upload_id' => $uploadId,
                'filename' => $request->filename,
                'mime_type' => $request->mime_type,
                'total_size' => $request->total_size,
                'checksum' => $request->checksum,
                'total_chunks' => $request->total_chunks,
                'status' => 'uploading',
                'uploaded_size' => 0,
                'chunks_received' => [],
            ]);

            return response()->json([
                'success' => true,
                'upload_id' => $uploadId,
                'chunk_size' => self::CHUNK_SIZE,
            ]);
        } catch (\Exception $e) {
            Log::error('Upload initialization failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to initialize upload: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Upload a chunk
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function uploadChunk(Request $request): JsonResponse
    {
        $request->validate([
            'upload_id' => 'required|string',
            'chunk_index' => 'required|integer|min:0',
            'chunk' => 'required|file',
        ]);

        try {
            $upload = Upload::where('upload_id', $request->upload_id)->firstOrFail();

            if ($upload->status === 'completed') {
                return response()->json([
                    'success' => true,
                    'message' => 'Upload already completed',
                    'uploaded_size' => $upload->uploaded_size,
                ]);
            }

            $chunkIndex = $request->chunk_index;
            $chunkFile = $request->file('chunk');

            // Check if chunk was already received (idempotent)
            $receivedChunks = $upload->getReceivedChunks();
            if (in_array($chunkIndex, $receivedChunks)) {
                return response()->json([
                    'success' => true,
                    'message' => 'Chunk already received',
                    'uploaded_size' => $upload->uploaded_size,
                ]);
            }

            // Store chunk
            $chunkPath = "chunks/{$upload->upload_id}/chunk_{$chunkIndex}";
            Storage::disk('local')->put($chunkPath, $chunkFile->getContent());

            // Update upload record
            $upload->uploaded_size += $chunkFile->getSize();
            $upload->markChunkReceived($chunkIndex);

            // Check if all chunks are received
            $allChunksReceived = count($upload->getReceivedChunks()) >= $upload->total_chunks;

            return response()->json([
                'success' => true,
                'uploaded_size' => $upload->uploaded_size,
                'chunks_received' => count($upload->getReceivedChunks()),
                'total_chunks' => $upload->total_chunks,
                'complete' => $allChunksReceived,
            ]);
        } catch (\Exception $e) {
            Log::error('Chunk upload failed', [
                'error' => $e->getMessage(),
                'upload_id' => $request->upload_id,
                'chunk_index' => $request->chunk_index,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to upload chunk: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Complete the upload by assembling chunks and processing image
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function complete(Request $request): JsonResponse
    {
        $request->validate([
            'upload_id' => 'required|string',
        ]);

        DB::beginTransaction();
        try {
            $upload = Upload::where('upload_id', $request->upload_id)->firstOrFail();

            if ($upload->status === 'completed') {
                return response()->json([
                    'success' => true,
                    'message' => 'Upload already completed',
                    'image_id' => $upload->images()->first()?->id,
                ]);
            }

            // Verify all chunks are received
            $receivedChunks = $upload->getReceivedChunks();
            if (count($receivedChunks) < $upload->total_chunks) {
                return response()->json([
                    'success' => false,
                    'message' => 'Not all chunks have been received',
                    'chunks_received' => count($receivedChunks),
                    'total_chunks' => $upload->total_chunks,
                ], 400);
            }

            // Assemble chunks into final file
            $finalPath = $this->assembleChunks($upload);

            // Verify file size
            $finalSize = filesize($finalPath);
            if ($finalSize !== $upload->total_size) {
                Storage::disk('local')->delete($finalPath);
                return response()->json([
                    'success' => false,
                    'message' => 'File size mismatch',
                    'expected' => $upload->total_size,
                    'actual' => $finalSize,
                ], 400);
            }

            // Verify checksum if provided
            if ($upload->checksum) {
                $actualChecksum = hash_file('sha256', $finalPath);
                if ($actualChecksum !== $upload->checksum) {
                    Storage::disk('local')->delete($finalPath);
                    return response()->json([
                        'success' => false,
                        'message' => 'Checksum mismatch',
                        'expected' => $upload->checksum,
                        'actual' => $actualChecksum,
                    ], 400);
                }
            }

            // Get image dimensions
            $dimensions = $this->imageProcessingService->getImageDimensions($finalPath);

            // Generate variants
            $variants = $this->imageProcessingService->generateVariants($finalPath, $upload->filename);

            // Move final file to public storage
            $storagePath = "images/{$upload->upload_id}/{$upload->filename}";
            Storage::disk('public')->put($storagePath, file_get_contents($finalPath));

            // Create image record
            $image = Image::create([
                'upload_id' => $upload->id,
                'filename' => $upload->filename,
                'path' => $storagePath,
                'mime_type' => $upload->mime_type,
                'size' => $finalSize,
                'checksum' => $upload->checksum ?? hash_file('sha256', Storage::disk('public')->path($storagePath)),
                'width' => $dimensions['width'],
                'height' => $dimensions['height'],
                'variants' => $variants,
            ]);

            // Update upload status
            $upload->status = 'completed';
            $upload->completed_at = now();
            $upload->save();

            // Clean up chunks
            $this->cleanupChunks($upload);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Upload completed successfully',
                'image_id' => $image->id,
                'path' => $storagePath,
                'variants' => $variants,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Upload completion failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to complete upload: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get upload status
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function status(Request $request): JsonResponse
    {
        $request->validate([
            'upload_id' => 'required|string',
        ]);

        $upload = Upload::where('upload_id', $request->upload_id)->firstOrFail();

        return response()->json([
            'success' => true,
            'upload_id' => $upload->upload_id,
            'status' => $upload->status,
            'uploaded_size' => $upload->uploaded_size,
            'total_size' => $upload->total_size,
            'chunks_received' => count($upload->getReceivedChunks()),
            'total_chunks' => $upload->total_chunks,
            'completed_at' => $upload->completed_at,
        ]);
    }

    /**
     * Attach image to product as primary image (idempotent)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function attachToProduct(Request $request): JsonResponse
    {
        $request->validate([
            'image_id' => 'required|integer|exists:images,id',
            'product_sku' => 'required|string|exists:products,sku',
        ]);

        try {
            $image = Image::findOrFail($request->image_id);
            $product = Product::where('sku', $request->product_sku)->firstOrFail();

            // Idempotent: if already attached, no-op
            if ($product->primary_image_id === $image->id) {
                return response()->json([
                    'success' => true,
                    'message' => 'Image already attached to product',
                ]);
            }

            $product->primary_image_id = $image->id;
            $product->save();

            return response()->json([
                'success' => true,
                'message' => 'Image attached to product successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to attach image to product', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to attach image: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Assemble chunks into final file
     *
     * @param Upload $upload
     * @return string Path to assembled file
     */
    private function assembleChunks(Upload $upload): string
    {
        $finalPath = Storage::disk('local')->path("uploads/{$upload->upload_id}/{$upload->filename}");
        $finalDir = dirname($finalPath);

        if (!is_dir($finalDir)) {
            mkdir($finalDir, 0755, true);
        }

        $finalHandle = fopen($finalPath, 'wb');
        if ($finalHandle === false) {
            throw new \RuntimeException("Could not create final file");
        }

        $receivedChunks = $upload->getReceivedChunks();
        sort($receivedChunks);

        try {
            foreach ($receivedChunks as $chunkIndex) {
                $chunkPath = Storage::disk('local')->path("chunks/{$upload->upload_id}/chunk_{$chunkIndex}");
                
                if (!file_exists($chunkPath)) {
                    throw new \RuntimeException("Chunk {$chunkIndex} not found");
                }

                $chunkContent = file_get_contents($chunkPath);
                fwrite($finalHandle, $chunkContent);
            }
        } finally {
            fclose($finalHandle);
        }

        return $finalPath;
    }

    /**
     * Clean up chunk files
     *
     * @param Upload $upload
     * @return void
     */
    private function cleanupChunks(Upload $upload): void
    {
        $chunkDir = Storage::disk('local')->path("chunks/{$upload->upload_id}");
        if (is_dir($chunkDir)) {
            $files = glob("{$chunkDir}/*");
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($chunkDir);
        }
    }
}
