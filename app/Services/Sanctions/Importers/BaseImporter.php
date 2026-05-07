<?php

namespace App\Services\Sanctions\Importers;

use App\Models\SanctionsEntry;
use Illuminate\Support\Facades\Http;

abstract class BaseImporter
{
    abstract public function source(): string;
    abstract public function url(): string;
    abstract public function import(): int;

    protected function download(): string
    {
        $response = Http::timeout(120)
            ->withHeaders(['Accept-Encoding' => 'gzip'])
            ->get($this->url());

        if ($response->failed()) {
            throw new \RuntimeException(
                "Failed to download {$this->source()} list: HTTP {$response->status()}"
            );
        }

        return $response->body();
    }

    protected function normalize(string $name): string
    {
        return SanctionsEntry::normalizeName($name);
    }

    protected function sync(array $records): int
    {
        if (empty($records)) return 0;

        $syncedAt = now()->toDateTimeString();

        foreach ($records as &$record) {
            $record['raw']        = is_string($record['raw']) ? $record['raw'] : json_encode($record['raw']);
            $record['updated_at'] = $syncedAt;
        }
        unset($record);

        $count = 0;
        foreach (array_chunk($records, 500) as $chunk) {
            SanctionsEntry::upsert(
                $chunk,
                ['source', 'source_id'],
                ['full_name', 'full_name_normalized', 'aliases', 'aliases_normalized',
                'dob', 'nationality', 'program', 'is_pep', 'entry_type', 'raw', 'updated_at']
            );
            $count += count($chunk);
        }

        // Use strict less-than on the exact syncedAt timestamp — anything
        // not touched in this run will have an older updated_at
        SanctionsEntry::where('source', $this->source())
            ->where('updated_at', '<', $syncedAt)
            ->delete();

        return $count;
    }

}