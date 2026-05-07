<?php

namespace App\Services\Sanctions\Importers;

class EuImporter extends BaseImporter
{
    public function source(): string { return 'eu'; }
    public function url(): string    { return 'https://webgate.ec.europa.eu/fsd/fsf/public/files/xmlFullSanctionsList/content'; }

    public function import(): int
    {
        $xml     = $this->download();
        $doc     = new \SimpleXMLElement($xml);
        $records = [];

        foreach ($doc->sanctionEntity ?? [] as $entry) {
            $logicalId = (string) ($entry['logicalId'] ?? '');
            $typeCode  = strtolower((string) ($entry->subjectType['classificationCode'] ?? ''));
            $entryType = str_contains($typeCode, 'person') ? 'individual' : 'entity';

            $names = [];
            foreach ($entry->nameAlias ?? [] as $alias) {
                $whole = trim((string) ($alias['wholeName'] ?? ''));
                if ($whole) $names[] = $whole;
            }
            if (empty($names)) continue;

            $fullName = $names[0];
            $aliases  = array_slice($names, 1);

            $dob = null;
            foreach ($entry->birthdate ?? [] as $bd) {
                $dobRaw = trim((string) ($bd['birthdate'] ?? ''));
                if ($dobRaw) {
                    try { $dob = \Carbon\Carbon::parse($dobRaw)->toDateString(); } catch (\Exception) {}
                    break;
                }
            }

            $nationality = null;
            foreach ($entry->citizenship ?? [] as $cit) {
                $nationality = (string) ($cit['countryIso2Code'] ?? null) ?: null;
                break;
            }

            $records[] = [
                'source'               => 'eu',
                'source_id'            => $logicalId ?: md5($fullName),
                'entry_type'           => $entryType,
                'full_name'            => $fullName,
                'full_name_normalized' => $this->normalize($fullName),
                'aliases'              => json_encode(array_values($aliases)),
                'aliases_normalized'   => json_encode(array_map([$this, 'normalize'], $aliases)),
                'dob'                  => $dob,
                'nationality'          => $nationality,
                'program'              => 'EU',
                'is_pep'               => false,
                'raw'                  => json_encode(['logicalId' => $logicalId]),
                'created_at'           => now(),
                'updated_at'           => now(),
            ];
        }

        return $this->sync($records);
    }
}