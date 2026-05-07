<?php

namespace App\Console\Commands;

use App\Services\Sanctions\Importers\EuImporter;
use App\Services\Sanctions\Importers\OfacImporter;
use App\Services\Sanctions\Importers\UnImporter;
use App\Models\SanctionsEntry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncSanctionsLists extends Command
{
    protected $signature   = 'sanctions:sync {--source=all : ofac|un|eu|all}';
    protected $description = 'Download and sync sanctions lists from OFAC, UN, and EU';

    private array $importers = [
        'ofac' => OfacImporter::class,
        'un'   => UnImporter::class,
        'eu'   => EuImporter::class,
    ];

    public function handle(): int
    {
        $source    = $this->option('source');
        $importers = $source === 'all'
            ? $this->importers
            : [$source => $this->importers[$source] ?? null];

        foreach ($importers as $key => $class) {
            if (! $class) {
                $this->error("Unknown source: {$key}. Valid options: ofac, un, eu, all");
                continue;
            }

            $this->info("Syncing {$key} sanctions list...");
            $start = now();

            try {
                $countBefore = SanctionsEntry::where('source', $key)->count();
                $count       = (new $class())->import();
                $countAfter  = SanctionsEntry::where('source', $key)->count();
                $deleted     = max(0, $countBefore - $countAfter);

                DB::table('sanctions_list_syncs')->insert([
                    'source'           => $key,
                    'status'           => 'success',
                    'records_imported' => $count,
                    'records_deleted'  => $deleted,
                    'synced_at'        => now(),
                ]);

                $elapsed = now()->diffInSeconds($start);
                $this->info("  ✓ {$key}: {$count} imported, {$deleted} delisted, {$elapsed}s");
            } catch (\Throwable $e) {
                DB::table('sanctions_list_syncs')->insert([
                    'source'    => $key,
                    'status'    => 'failed',
                    'error'     => $e->getMessage(),
                    'synced_at' => now(),
                ]);

                $this->error("  {$key} failed: " . $e->getMessage());
                Log::error("Sanctions sync failed for {$key}", ['error' => $e->getMessage()]);
            }
        }

        $total = \App\Models\SanctionsEntry::count();
        $this->info("Total sanctions entries in database: {$total}");
        return self::SUCCESS;
    }
}
