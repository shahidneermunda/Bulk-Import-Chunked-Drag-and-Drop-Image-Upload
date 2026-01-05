<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CsvImportService
{
    /**
     * Required columns for CSV import
     */
    private const REQUIRED_COLUMNS = ['sku', 'name'];

    /**
     * Import products from CSV file
     *
     * @param string $filePath Path to the CSV file
     * @return array Result summary with counts
     */
    public function importFromFile(string $filePath): array
    {
        $result = [
            'total' => 0,
            'imported' => 0,
            'updated' => 0,
            'invalid' => 0,
            'duplicates' => 0,
            'errors' => [],
        ];

        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("CSV file not found: {$filePath}");
        }

        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Could not open CSV file: {$filePath}");
        }

        // Read header row
        $headers = fgetcsv($handle);
        if ($headers === false) {
            fclose($handle);
            throw new \RuntimeException("Could not read CSV headers");
        }

        // Normalize headers (trim, lowercase)
        $headers = array_map(function ($header) {
            return strtolower(trim($header));
        }, $headers);

        // Validate required columns exist
        $missingColumns = array_diff(self::REQUIRED_COLUMNS, $headers);
        if (!empty($missingColumns)) {
            fclose($handle);
            throw new \RuntimeException("Missing required columns: " . implode(', ', $missingColumns));
        }

        $seenSkus = [];
        $rowNumber = 1; // Start at 1 (header is row 0)

        // Process rows in a transaction for atomicity
        DB::beginTransaction();
        try {
            while (($row = fgetcsv($handle)) !== false) {
                $rowNumber++;
                $result['total']++;

                // Map row data to associative array
                $data = [];
                foreach ($headers as $index => $header) {
                    $data[$header] = $row[$index] ?? null;
                }

                // Validate row data
                $validation = $this->validateRow($data, $rowNumber);
                if (!$validation['valid']) {
                    $result['invalid']++;
                    $result['errors'][] = [
                        'row' => $rowNumber,
                        'errors' => $validation['errors'],
                    ];
                    continue; // Skip invalid rows but continue processing
                }

                $sku = trim($data['sku']);

                // Check for duplicates within the same CSV
                if (isset($seenSkus[$sku])) {
                    $result['duplicates']++;
                    $result['errors'][] = [
                        'row' => $rowNumber,
                        'errors' => ["Duplicate SKU '{$sku}' found in CSV (first seen at row {$seenSkus[$sku]})"],
                    ];
                    continue;
                }
                $seenSkus[$sku] = $rowNumber;

                // Upsert product
                $product = Product::where('sku', $sku)->first();

                if ($product) {
                    // Update existing product
                    $product->update([
                        'name' => $data['name'],
                        'description' => $data['description'] ?? null,
                        'price' => isset($data['price']) && $data['price'] !== '' ? (float) $data['price'] : null,
                        'quantity' => isset($data['quantity']) && $data['quantity'] !== '' ? (int) $data['quantity'] : 0,
                    ]);
                    $result['updated']++;
                } else {
                    // Create new product
                    Product::create([
                        'sku' => $sku,
                        'name' => $data['name'],
                        'description' => $data['description'] ?? null,
                        'price' => isset($data['price']) && $data['price'] !== '' ? (float) $data['price'] : null,
                        'quantity' => isset($data['quantity']) && $data['quantity'] !== '' ? (int) $data['quantity'] : 0,
                    ]);
                    $result['imported']++;
                }
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('CSV import failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        } finally {
            fclose($handle);
        }

        return $result;
    }

    /**
     * Validate a single row of CSV data
     *
     * @param array $data Row data
     * @param int $rowNumber Row number for error reporting
     * @return array ['valid' => bool, 'errors' => array]
     */
    private function validateRow(array $data, int $rowNumber): array
    {
        $errors = [];

        // Check required fields
        if (empty(trim($data['sku'] ?? ''))) {
            $errors[] = "SKU is required";
        }

        if (empty(trim($data['name'] ?? ''))) {
            $errors[] = "Name is required";
        }

        // Validate price if provided
        if (isset($data['price']) && $data['price'] !== '') {
            if (!is_numeric($data['price']) || (float) $data['price'] < 0) {
                $errors[] = "Price must be a non-negative number";
            }
        }

        // Validate quantity if provided
        if (isset($data['quantity']) && $data['quantity'] !== '') {
            if (!is_numeric($data['quantity']) || (int) $data['quantity'] < 0) {
                $errors[] = "Quantity must be a non-negative integer";
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }
}

