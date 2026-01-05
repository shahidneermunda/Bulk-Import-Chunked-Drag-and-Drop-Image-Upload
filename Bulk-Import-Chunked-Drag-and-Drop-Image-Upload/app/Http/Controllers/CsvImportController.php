<?php

namespace App\Http\Controllers;

use App\Services\CsvImportService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class CsvImportController extends Controller
{
    public function __construct(
        private CsvImportService $csvImportService
    ) {}

    /**
     * Handle CSV file upload and import
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:10240', // 10MB max
        ]);

        try {
            $file = $request->file('file');
            $filePath = $file->store('csv-imports', 'local');

            $fullPath = Storage::disk('local')->path($filePath);

            $result = $this->csvImportService->importFromFile($fullPath);

            // Clean up uploaded file
            Storage::disk('local')->delete($filePath);

            return response()->json([
                'success' => true,
                'message' => 'CSV import completed',
                'result' => $result,
            ]);
        } catch (\Exception $e) {
            Log::error('CSV import error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'CSV import failed: ' . $e->getMessage(),
            ], 500);
        }
    }
}
