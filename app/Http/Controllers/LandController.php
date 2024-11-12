<?php

namespace App\Http\Controllers;

use App\Models\Land;
use App\Models\LandImage;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class LandController extends Controller
{
    public function index()
    {
        return response()->json(Land::with('images')->get(), 200);
    }

    public function show($id)
    {
        $land = Land::with('images')->find($id);
        
        if (!$land) {
            return response()->json([
                'error' => [
                    'message' => 'Land not found',
                    'code' => 'LAND_NOT_FOUND'
                ]
            ], 404);
        }

        return response()->json($land, 200);
    }

    public function store(Request $request)
    {
        // Check if the user is authenticated and is an admin
        if (!auth()->user() || !auth()->user()->is_admin) {
            return response()->json([
                'error' => [
                    'message' => 'Unauthorized action',
                    'code' => 'UNAUTHORIZED'
                ]
            ], 403);
        }

        // Validate incoming request data, including images
        $validatedData = $request->validate([
            'title' => 'required|string|max:255',
            'location' => 'required|string|max:255',
            'size' => 'required|numeric',
            'price_per_unit' => 'required|numeric',
            'total_units' => 'required|integer|min:1',
            'description' => 'nullable|string',
            'images.*' => 'nullable|image|mimes:jpg,jpeg,png|max:2048', // Validation for multiple images
        ]);

        // Create the Land entry
        $land = Land::create([
            'title' => $validatedData['title'],
            'location' => $validatedData['location'],
            'size' => $validatedData['size'],
            'price_per_unit' => $validatedData['price_per_unit'],
            'total_units' => $validatedData['total_units'],
            'available_units' => $validatedData['total_units'],
            'is_available' => true,
            'description' => $validatedData['description'],
        ]);

        // Handle image uploads if provided
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $image) {
                // Store each image and get the public path
                $imagePath = $image->store('land_images', 'public');

                // Save each image path in the LandImage table
                $land->images()->create([
                    'image_path' => $imagePath
                ]);
            }
        }

        // Load the images and prepend the full URL to each image
        $landWithImages = $land->load('images');
        foreach ($landWithImages->images as $image) {
            // Prepend the public URL to each image path
            $image->image_path = Storage::url($image->image_path);
        }

        // Return response with full image URLs
        return response()->json([
            'message' => 'Land created successfully',
            'land' => $landWithImages
        ], 201);
    }


    public function buy(Request $request, $id)
    {
        $land = Land::find($id);

        if (!$land) {
            return response()->json([
                'error' => [
                    'message' => 'Land not found',
                    'code' => 'LAND_NOT_FOUND'
                ]
            ], 404);
        }

        // Validate incoming request data for purchase
        $validatedData = $request->validate([
            'units' => 'required|integer|min:1',
            'buyer_id' => 'required|integer',
        ]);

        // Check if land is available for purchase
        if (!$land->is_available) {
            return response()->json([
                'error' => [
                    'message' => 'Land is no longer available for purchase',
                    'code' => 'LAND_NOT_AVAILABLE'
                ]
            ], 400);
        }

        $purchase_units = $validatedData['units'];

        // Check if enough units are available
        if ($land->available_units < $purchase_units) {
            return response()->json([
                'error' => [
                    'message' => 'Not enough units available',
                    'code' => 'INSUFFICIENT_UNITS'
                ]
            ], 400);
        }

        // Deduct the purchased units from available units
        $land->available_units -= $purchase_units;

        // If no units are left, mark the land as not available
        if ($land->available_units <= 0) {
            $land->is_available = false;
        }

        $land->save();

        // Create a transaction record
        Transaction::create([
            'land_id' => $land->id,
            'user_id' => $validatedData['buyer_id'],
            'percentage' => $purchase_units,
            'price' => $purchase_units * $land->price_per_unit,
        ]);

        return response()->json(['message' => 'Purchase successful'], 200);
    }
}
