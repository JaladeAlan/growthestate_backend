<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;
use App\Models\Land;
use App\Models\LandImage;
use App\Models\LandPriceHistory;

class LandSeeder extends Seeder
{
    private array $locations = [
        'Ibeju-Lekki', 'Epe', 'Ajah', 'Sangotedo', 
        'Abijo', 'Lakowe', 'Bogije'
    ];

    private array $sizes = [300, 450, 600];
    private array $totalUnits = [300, 450, 600, 750, 1000];

    private array $descriptions = [
        'Prime investment opportunity in rapidly developing area with excellent ROI potential.',
        'Strategic location with proximity to major infrastructure developments and amenities.',
        'Exclusive estate development with world-class facilities and security features.',
        'High-value land in emerging commercial corridor with strong appreciation prospects.',
        'Premium residential plots in gated community with modern infrastructure.',
        'Waterfront property with scenic views and exceptional investment value.',
        'Commercial land in high-traffic area ideal for mixed-use development.',
        'Residential estate plots with government-approved layouts and C of O.',
        'Investment-grade land with verified title documents and immediate allocation.',
        'Luxury estate development in prime location with guaranteed returns.',
    ];

    // Placeholder images for land/real estate
    private array $placeholderImages = [
        'https://images.unsplash.com/photo-1500382017468-9049fed747ef?w=800&h=600&fit=crop',
        'https://images.unsplash.com/photo-1484154218962-a197022b5858?w=800&h=600&fit=crop',
        'https://images.unsplash.com/photo-1513584684374-8bab748fbf90?w=800&h=600&fit=crop',
        'https://images.unsplash.com/photo-1448630360428-65456885c650?w=800&h=600&fit=crop',
        'https://images.unsplash.com/photo-1518780664697-55e3ad937233?w=800&h=600&fit=crop',
        'https://images.unsplash.com/photo-1582407947304-fd86f028f716?w=800&h=600&fit=crop',
        'https://images.unsplash.com/photo-1564013799919-ab600027ffc6?w=800&h=600&fit=crop',
        'https://images.unsplash.com/photo-1605146769289-440113cc3d00?w=800&h=600&fit=crop',
        'https://images.unsplash.com/photo-1512917774080-9991f1c4c750?w=800&h=600&fit=crop',
        'https://images.unsplash.com/photo-1570129477492-45c003edd2be?w=800&h=600&fit=crop',
    ];

    public function run(): void
    {
       if (Land::exists()) {
        $this->command->info('Land data already seeded. Skipping.');
        return;
        }
        // Copy images from database/seeders/images/lands to storage/app/public/seed/lands
        $this->copySeederImagesToStorage();

        // Get all seed images from storage
        $images = collect(Storage::files('public/seed/lands'))
            ->map(fn($path) => str_replace('public/', '', $path))
            ->values();

        $useLocalImages = $images->isNotEmpty();

        if ($useLocalImages) {
            $this->command->info("Found {$images->count()} local seed images");
        } else {
            $this->command->warn('No local seed images found. Using placeholder URLs.');
        }

        for ($i = 1; $i <= 10; $i++) {
            DB::transaction(function () use ($images, $useLocalImages, $i) {
                
                // Generate Lagos-like random coordinates
                $lat = $this->randomFloat(6, 6.40, 6.65);
                $lng = $this->randomFloat(6, 3.20, 3.65);

                // Pick random values from arrays
                $totalUnits = $this->totalUnits[array_rand($this->totalUnits)];
                $size = $this->sizes[array_rand($this->sizes)];
                $location = $this->locations[array_rand($this->locations)];
                $description = $this->descriptions[$i - 1];

                $land = Land::create([
                    'title' => "Premium Estate Plot $i",
                    'location' => "$location, Lagos",
                    'size' => $size,
                    'total_units' => $totalUnits,
                    'available_units' => $totalUnits,
                    'description' => $description,
                    'lat' => $lat,
                    'lng' => $lng,
                    'is_available' => true,
                ]);

                // Create Polygon
                $offset = 0.002;
                $wkt = "POLYGON((
                    " . ($lng - $offset) . " " . ($lat - $offset) . ",
                    " . ($lng + $offset) . " " . ($lat - $offset) . ",
                    " . ($lng + $offset) . " " . ($lat + $offset) . ",
                    " . ($lng - $offset) . " " . ($lat + $offset) . ",
                    " . ($lng - $offset) . " " . ($lat - $offset) . "
                ))";

                DB::statement(
                    "UPDATE lands SET coordinates = ST_GeomFromText(?, 4326) WHERE id = ?",
                    [$wkt, $land->id]
                );

                // Price in kobo (₦300k - ₦800k = 30M - 80M kobo)
                $pricePerUnit = $this->randomInt(300_000, 800_000);
                
                LandPriceHistory::create([
                    'land_id' => $land->id,
                    'price_per_unit_kobo' => $pricePerUnit,
                    'price_date' => now()->toDateString(),
                ]);

                // Attach images (local or placeholder)
                if ($useLocalImages) {
                    // Use local images
                    $imageCount = min(3, $images->count());
                    $selectedImages = $images->random($imageCount);

                    foreach ($selectedImages as $img) {
                        $land->images()->create([
                            'image_path' => $img
                        ]);
                    }
                } else {
                    // Use placeholder URLs
                    $imageCount = 3;
                    $startIndex = ($i - 1) * 3;
                    
                    for ($j = 0; $j < $imageCount; $j++) {
                        $placeholderIndex = ($startIndex + $j) % count($this->placeholderImages);
                        $land->images()->create([
                            'image_path' => $this->placeholderImages[$placeholderIndex]
                        ]);
                    }
                }
            });
        }

        $this->command->info('Successfully seeded 10 land records');
    }

    /**
     * Clear existing land data before seeding
     */
    private function clearExistingData(): void
    {
        $this->command->info('Clearing existing land data...');
        
        // Delete in correct order to respect foreign key constraints
        LandImage::query()->delete();
        LandPriceHistory::query()->delete();
        
        // If you have other related tables, delete them here
        // DB::table('purchases')->delete();
        // DB::table('transactions')->delete();
        // DB::table('user_land')->delete();
        // DB::table('portfolio_land_snapshots')->delete();
        
        Land::query()->delete();
        
        $this->command->info('Existing land data cleared successfully');
    }

    /**
     * Copy images from database/seeders/images/lands to storage/app/public/seed/lands
     */
    private function copySeederImagesToStorage(): void
    {
        $sourceDir = database_path('seeders/images/lands');
        $targetDir = storage_path('app/public/seed/lands');

        if (!File::exists($sourceDir) || !File::isDirectory($sourceDir)) {
            $this->command->info("Source directory not found: {$sourceDir} - will use placeholder URLs");
            return;
        }

        // Ensure target directory exists
        if (!File::exists($targetDir)) {
            File::makeDirectory($targetDir, 0775, true);
            $this->command->info("Created target directory: {$targetDir}");
        }

        // Get all image files from source
        $sourceFiles = File::files($sourceDir);
        
        if (empty($sourceFiles)) {
            $this->command->info("No images found in: {$sourceDir} - will use placeholder URLs");
            return;
        }

        $copiedCount = 0;
        foreach ($sourceFiles as $file) {
            // Only copy image files
            $extension = strtolower($file->getExtension());
            if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                $targetPath = $targetDir . '/' . $file->getFilename();
                
                // Copy file (overwrite if exists to ensure fresh copies)
                File::copy($file->getPathname(), $targetPath);
                $copiedCount++;
            }
        }

        $this->command->info("Copied {$copiedCount} images from database/seeders/images/lands to storage");
    }

    // Helper methods to replace Faker
    private function randomFloat(int $decimals, float $min, float $max): float
    {
        $scale = pow(10, $decimals);
        return mt_rand($min * $scale, $max * $scale) / $scale;
    }

    private function randomInt(int $min, int $max): int
    {
        return mt_rand($min, $max);
    }
}
