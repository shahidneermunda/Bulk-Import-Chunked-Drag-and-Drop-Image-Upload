<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Services\CsvImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CsvImportServiceTest extends TestCase
{
    use RefreshDatabase;

    private CsvImportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CsvImportService();
    }

    /**
     * Test upsert logic: creating a new product
     */
    public function test_import_creates_new_product(): void
    {
        $csvContent = "sku,name,description,price,quantity\nSKU001,Test Product,Test Description,10.50,100";
        $filePath = $this->createTempCsvFile($csvContent);

        $result = $this->service->importFromFile($filePath);

        $this->assertEquals(1, $result['total']);
        $this->assertEquals(1, $result['imported']);
        $this->assertEquals(0, $result['updated']);
        $this->assertEquals(0, $result['invalid']);
        $this->assertEquals(0, $result['duplicates']);

        $product = Product::where('sku', 'SKU001')->first();
        $this->assertNotNull($product);
        $this->assertEquals('Test Product', $product->name);
        $this->assertEquals('Test Description', $product->description);
        $this->assertEquals(10.50, $product->price);
        $this->assertEquals(100, $product->quantity);

        unlink($filePath);
    }

    /**
     * Test upsert logic: updating an existing product
     */
    public function test_import_updates_existing_product(): void
    {
        // Create existing product
        Product::create([
            'sku' => 'SKU001',
            'name' => 'Old Name',
            'description' => 'Old Description',
            'price' => 5.00,
            'quantity' => 50,
        ]);

        $csvContent = "sku,name,description,price,quantity\nSKU001,New Name,New Description,15.75,200";
        $filePath = $this->createTempCsvFile($csvContent);

        $result = $this->service->importFromFile($filePath);

        $this->assertEquals(1, $result['total']);
        $this->assertEquals(0, $result['imported']);
        $this->assertEquals(1, $result['updated']);
        $this->assertEquals(0, $result['invalid']);
        $this->assertEquals(0, $result['duplicates']);

        $product = Product::where('sku', 'SKU001')->first();
        $this->assertNotNull($product);
        $this->assertEquals('New Name', $product->name);
        $this->assertEquals('New Description', $product->description);
        $this->assertEquals(15.75, $product->price);
        $this->assertEquals(200, $product->quantity);

        unlink($filePath);
    }

    /**
     * Test invalid rows are skipped but don't stop import
     */
    public function test_import_skips_invalid_rows(): void
    {
        $csvContent = "sku,name,description,price,quantity\nSKU001,Valid Product,,10.50,100\n,Invalid Product,,,50\nSKU002,Another Valid Product,,20.00,200";
        $filePath = $this->createTempCsvFile($csvContent);

        $result = $this->service->importFromFile($filePath);

        $this->assertEquals(3, $result['total']);
        $this->assertEquals(2, $result['imported']);
        $this->assertEquals(1, $result['invalid']);
        $this->assertGreaterThan(0, count($result['errors']));

        // Verify valid products were imported
        $this->assertNotNull(Product::where('sku', 'SKU001')->first());
        $this->assertNotNull(Product::where('sku', 'SKU002')->first());

        unlink($filePath);
    }

    /**
     * Test duplicate detection within CSV
     */
    public function test_import_detects_duplicates_in_csv(): void
    {
        $csvContent = "sku,name,description,price,quantity\nSKU001,Product 1,,10.50,100\nSKU002,Product 2,,20.00,200\nSKU001,Duplicate Product,,30.00,300";
        $filePath = $this->createTempCsvFile($csvContent);

        $result = $this->service->importFromFile($filePath);

        $this->assertEquals(3, $result['total']);
        $this->assertEquals(2, $result['imported']); // Only SKU001 and SKU002
        $this->assertEquals(1, $result['duplicates']);

        // Verify only first occurrence was imported
        $products = Product::where('sku', 'SKU001')->get();
        $this->assertCount(1, $products);
        $this->assertEquals('Product 1', $products->first()->name);

        unlink($filePath);
    }

    /**
     * Test result summary includes all required fields
     */
    public function test_import_returns_complete_summary(): void
    {
        $csvContent = "sku,name,description,price,quantity\nSKU001,Product 1,,10.50,100";
        $filePath = $this->createTempCsvFile($csvContent);

        $result = $this->service->importFromFile($filePath);

        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('imported', $result);
        $this->assertArrayHasKey('updated', $result);
        $this->assertArrayHasKey('invalid', $result);
        $this->assertArrayHasKey('duplicates', $result);
        $this->assertArrayHasKey('errors', $result);

        unlink($filePath);
    }

    /**
     * Create a temporary CSV file for testing
     */
    private function createTempCsvFile(string $content): string
    {
        $filePath = sys_get_temp_dir() . '/test_import_' . uniqid() . '.csv';
        file_put_contents($filePath, $content);
        return $filePath;
    }
}
