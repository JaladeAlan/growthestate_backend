<?php

namespace App\Services\Sanctions\Importers;

class OfacImporter extends BaseImporter
{
    public function source(): string { return 'ofac'; }
    public function url(): string    { return 'https://ofac.treasury.gov/downloads/sdn.xml'; }

    public function import(): int
    {
        $xml     = $this->download();
        $doc     = new \SimpleXMLElement($xml);
        $records = [];

        foreach ($doc->sdnEntry as $entry) {
            $type = (string) $entry->sdnType;
            if (! in_array($type, ['Individual', 'Entity', 'Vessel', 'Aircraft'])) continue;

            $firstName = trim((string) ($entry->firstName ?? ''));
            $lastName  = trim((string) ($entry->lastName  ?? ''));
            $fullName  = trim("$firstName $lastName") ?: trim((string) ($entry->title ?? ''));
            if (empty($fullName)) continue;

            $aliases = [];
            foreach ($entry->akaList->aka ?? [] as $aka) {
                $ak = trim(((string)($aka->firstName ?? '')) . ' ' . ((string)($aka->lastName ?? '')));
                if ($ak) $aliases[] = $ak;
            }

            $dob = null;
            foreach ($entry->dateOfBirthList->dateOfBirthItem ?? [] as $dobItem) {
                $dobRaw = trim((string) ($dobItem->dateOfBirth ?? ''));
                if ($dobRaw) {
                    try { $dob = \Carbon\Carbon::parse($dobRaw)->toDateString(); } catch (\Exception) {}
                    break;
                }
            }

            $records[] = [
                'source'               => 'ofac',
                'source_id'            => (string) $entry->uid,
                'entry_type'           => strtolower($type),
                'full_name'            => $fullName,
                'full_name_normalized' => $this->normalize($fullName),
                'aliases'              => json_encode(array_values($aliases)),
                'aliases_normalized'   => json_encode(array_map([$this, 'normalize'], $aliases)),
                'dob'                  => $dob,
                'nationality'          => null,
                'program'              => (string) ($entry->programList->program ?? ''),
                'is_pep'               => false,
                'raw'                  => json_encode(['uid' => (string) $entry->uid, 'type' => $type]),
                'created_at'           => now(),
                'updated_at'           => now(),
            ];
        }

        return $this->sync($records);
    }
}
