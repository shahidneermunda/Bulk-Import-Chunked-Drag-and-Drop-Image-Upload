<?php

namespace App\Services;

use App\Models\Image;
use Illuminate\Support\Facades\Storage;
use Exception;

class ImageProcessingService
{
    private array $variantSizes = [256, 512, 1024];

    /**
     * Generate image variants (256px, 512px, 1024px) maintaining aspect ratio
     *
     * @param string $originalPath Path to the original image
     * @param string $filename Original filename
     * @return array Array of variant paths ['256' => path, '512' => path, '1024' => path]
     */
    public function generateVariants(string $originalPath, string $filename): array
    {
        if (!file_exists($originalPath)) {
            throw new Exception("Original image not found: {$originalPath}");
        }

        if (!extension_loaded('gd')) {
            throw new Exception("GD extension is not loaded");
        }

        $variants = [];
        $pathInfo = pathinfo($filename);
        $baseName = $pathInfo['filename'];
        $extension = strtolower($pathInfo['extension'] ?? 'jpg');

        try {
            // Get original image info
            $imageInfo = getimagesize($originalPath);
            if ($imageInfo === false) {
                throw new Exception("Could not read image information");
            }

            $originalWidth = $imageInfo[0];
            $originalHeight = $imageInfo[1];
            $mimeType = $imageInfo['mime'];
            $aspectRatio = $originalWidth / $originalHeight;

            // Load original image based on type
            $sourceImage = $this->createImageFromFile($originalPath, $mimeType);
            if ($sourceImage === false) {
                throw new Exception("Could not create image from file");
            }

            foreach ($this->variantSizes as $size) {
                // Calculate dimensions maintaining aspect ratio
                if ($originalWidth > $originalHeight) {
                    // Landscape or square
                    $width = $size;
                    $height = (int) round($size / $aspectRatio);
                } else {
                    // Portrait
                    $width = (int) round($size * $aspectRatio);
                    $height = $size;
                }

                // Create resized image
                $variantImage = imagecreatetruecolor($width, $height);
                
                // Preserve transparency for PNG
                if ($mimeType === 'image/png') {
                    imagealphablending($variantImage, false);
                    imagesavealpha($variantImage, true);
                    $transparent = imagecolorallocatealpha($variantImage, 255, 255, 255, 127);
                    imagefilledrectangle($variantImage, 0, 0, $width, $height, $transparent);
                }

                // Resize image maintaining aspect ratio
                imagecopyresampled(
                    $variantImage,
                    $sourceImage,
                    0, 0, 0, 0,
                    $width, $height,
                    $originalWidth, $originalHeight
                );

                // Generate variant filename
                $variantFilename = "{$baseName}_{$size}w.{$extension}";
                $variantPath = "images/variants/{$variantFilename}";

                // Save variant
                $this->saveImage($variantImage, $variantPath, $mimeType);
                imagedestroy($variantImage);

                $variants[(string) $size] = $variantPath;
            }

            imagedestroy($sourceImage);

            return $variants;
        } catch (Exception $e) {
            // Clean up any created variants on error
            foreach ($variants as $variantPath) {
                Storage::disk('public')->delete($variantPath);
            }
            throw new Exception("Failed to generate image variants: " . $e->getMessage());
        }
    }

    /**
     * Create image resource from file
     *
     * @param string $filePath
     * @param string $mimeType
     * @return resource|false
     */
    private function createImageFromFile(string $filePath, string $mimeType)
    {
        switch ($mimeType) {
            case 'image/jpeg':
            case 'image/jpg':
                return imagecreatefromjpeg($filePath);
            case 'image/png':
                return imagecreatefrompng($filePath);
            case 'image/gif':
                return imagecreatefromgif($filePath);
            case 'image/webp':
                return imagecreatefromwebp($filePath);
            default:
                return false;
        }
    }

    /**
     * Save image to storage
     *
     * @param resource $imageResource
     * @param string $path
     * @param string $mimeType
     * @return void
     */
    private function saveImage($imageResource, string $path, string $mimeType): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'img_');
        
        switch ($mimeType) {
            case 'image/jpeg':
            case 'image/jpg':
                imagejpeg($imageResource, $tempFile, 90);
                break;
            case 'image/png':
                imagepng($imageResource, $tempFile, 9);
                break;
            case 'image/gif':
                imagegif($imageResource, $tempFile);
                break;
            case 'image/webp':
                imagewebp($imageResource, $tempFile, 90);
                break;
            default:
                throw new Exception("Unsupported image type: {$mimeType}");
        }

        Storage::disk('public')->put($path, file_get_contents($tempFile));
        unlink($tempFile);
    }

    /**
     * Get image dimensions
     *
     * @param string $imagePath Path to the image
     * @return array ['width' => int, 'height' => int]
     */
    public function getImageDimensions(string $imagePath): array
    {
        $imageInfo = getimagesize($imagePath);
        if ($imageInfo === false) {
            throw new Exception("Failed to get image dimensions");
        }

        return [
            'width' => $imageInfo[0],
            'height' => $imageInfo[1],
        ];
    }
}

