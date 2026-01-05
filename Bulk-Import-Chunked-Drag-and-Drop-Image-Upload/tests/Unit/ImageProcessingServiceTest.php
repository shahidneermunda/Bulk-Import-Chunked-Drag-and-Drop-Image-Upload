<?php

namespace Tests\Unit;

use App\Services\ImageProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImageProcessingServiceTest extends TestCase
{
    use RefreshDatabase;

    private ImageProcessingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ImageProcessingService();
        Storage::fake('public');
    }

    /**
     * Test image variant generation maintains aspect ratio
     */
    public function test_generate_variants_maintains_aspect_ratio(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension is not loaded');
        }

        // Create a test image (100x200 - portrait, 1:2 aspect ratio)
        $originalPath = $this->createTestImage(100, 200);

        try {
            $variants = $this->service->generateVariants($originalPath, 'test_image.jpg');

            $this->assertArrayHasKey('256', $variants);
            $this->assertArrayHasKey('512', $variants);
            $this->assertArrayHasKey('1024', $variants);

            // Verify variants exist in storage
            foreach ($variants as $size => $path) {
                $this->assertTrue(Storage::disk('public')->exists($path));
            }

            // Verify aspect ratio is maintained (approximately)
            // For 100x200 image (1:2 ratio), 256px variant should be ~128x256
            $variant256Path = Storage::disk('public')->path($variants['256']);
            $dimensions256 = $this->service->getImageDimensions($variant256Path);
            
            // Aspect ratio should be approximately 1:2 (0.5)
            $aspectRatio256 = $dimensions256['width'] / $dimensions256['height'];
            $this->assertGreaterThan(0.4, $aspectRatio256);
            $this->assertLessThan(0.6, $aspectRatio256);

            // Cleanup
            foreach ($variants as $path) {
                Storage::disk('public')->delete($path);
            }
        } finally {
            unlink($originalPath);
        }
    }

    /**
     * Test variant generation for landscape image
     */
    public function test_generate_variants_for_landscape_image(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension is not loaded');
        }

        // Create a test image (200x100 - landscape, 2:1 aspect ratio)
        $originalPath = $this->createTestImage(200, 100);

        try {
            $variants = $this->service->generateVariants($originalPath, 'landscape_test.jpg');

            $this->assertCount(3, $variants);

            // Verify 512px variant maintains aspect ratio
            $variant512Path = Storage::disk('public')->path($variants['512']);
            $dimensions512 = $this->service->getImageDimensions($variant512Path);
            
            // Aspect ratio should be approximately 2:1 (2.0)
            $aspectRatio512 = $dimensions512['width'] / $dimensions512['height'];
            $this->assertGreaterThan(1.5, $aspectRatio512);
            $this->assertLessThan(2.5, $aspectRatio512);

            // Cleanup
            foreach ($variants as $path) {
                Storage::disk('public')->delete($path);
            }
        } finally {
            unlink($originalPath);
        }
    }

    /**
     * Test getImageDimensions returns correct dimensions
     */
    public function test_get_image_dimensions(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension is not loaded');
        }

        $originalPath = $this->createTestImage(150, 300);

        try {
            $dimensions = $this->service->getImageDimensions($originalPath);

            $this->assertEquals(150, $dimensions['width']);
            $this->assertEquals(300, $dimensions['height']);
        } finally {
            unlink($originalPath);
        }
    }

    /**
     * Create a test image file
     */
    private function createTestImage(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        $bgColor = imagecolorallocate($image, 255, 255, 255);
        imagefill($image, 0, 0, $bgColor);

        $filePath = sys_get_temp_dir() . '/test_image_' . uniqid() . '.jpg';
        imagejpeg($image, $filePath, 90);
        imagedestroy($image);

        return $filePath;
    }
}
