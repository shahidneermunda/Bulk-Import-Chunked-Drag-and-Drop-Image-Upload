<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\JsonResponse;

class ProductController extends Controller
{
    /**
     * Get all products with their primary images
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        $products = Product::with('primaryImage')->get()->map(function ($product) {
            return [
                'id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'description' => $product->description,
                'price' => $product->price,
                'quantity' => $product->quantity,
                'primary_image' => $product->primaryImage ? [
                    'id' => $product->primaryImage->id,
                    'path' => $product->primaryImage->path,
                    'variants' => $product->primaryImage->variants,
                ] : null,
            ];
        });

        return response()->json($products);
    }
}
