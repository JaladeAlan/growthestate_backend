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

    // Local land photos are read from database/seeders/images/lands and
    // uploaded to the r2 disk under 'seed/lands/' (see copySeederImagesToR2()).
    // Drop real .jpg/.png/.webp files in that folder before seeding — if
    // it's empty, land records are seeded with no images rather than
    // falling back to external placeholder URLs.

    public function run(): void
    {
       if (Land::exists()) {
        $this->command->info('Land data already seeded. Skipping.');
        return;
        }
        // Upload real land photos (database/seeders/images/lands) to R2
        $images = $this->uploadSeederImagesToR2();
        $useLocalImages = $images->isNotEmpty();

        if ($useLocalImages) {
            $this->command->info("Uploaded {$images->count()} seed images to R2");
        } else {
            $this->command->warn(
                'No local seed images found in database/seeders/images/lands — ' .
                'land records will be seeded without images.'
            );
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

                // Attach local seed images, if any were uploaded to R2
                if ($useLocalImages) {
                    $imageCount = min(3, $images->count());
                    $selectedImages = $images->random($imageCount);

                    foreach ($selectedImages as $img) {
                        $land->images()->create([
                            'image_path' => $img
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
     * Upload images from database/seeders/images/lands directly to the r2
     * disk (under seed/lands/), so LandImage::getImageUrlAttribute() — which
     * resolves any non-URL image_path against the r2 disk — produces working
     * URLs. Returns the R2 paths that were successfully uploaded.
     */
    private function uploadSeederImagesToR2()
    {
        $sourceDir = database_path('seeders/images/lands');

        if (!File::exists($sourceDir) || !File::isDirectory($sourceDir)) {
            $this->command->info("Source directory not found: {$sourceDir}");
            return collect();
        }

        $sourceFiles = File::files($sourceDir);

        if (empty($sourceFiles)) {
            $this->command->info("No images found in: {$sourceDir}");
            return collect();
        }

        $uploaded = collect();

        foreach ($sourceFiles as $file) {
            $extension = strtolower($file->getExtension());
            if (!in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                continue;
            }

            $r2Path = 'seed/lands/' . $file->getFilename();

            try {
                Storage::disk('r2')->put(
                    $r2Path,
                    File::get($file->getPathname()),
                    'public'
                );
                $uploaded->push($r2Path);
            } catch (\Throwable $e) {
                $this->command->error(
                    "Failed to upload {$file->getFilename()} to R2 — check AWS_* / R2 " .
                    "credentials in .env. Error: {$e->getMessage()}"
                );
            }
        }

        return $uploaded;
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
