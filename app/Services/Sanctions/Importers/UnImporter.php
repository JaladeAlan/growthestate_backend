<?php

namespace App\Services\Sanctions\Importers;

class UnImporter extends BaseImporter
{
    public function source(): string { return 'un'; }
    public function url(): string    { return 'https://scsanctions.un.org/resources/xml/en/consolidated.xml'; }

    public function import(): int
    {
        $xml     = $this->download();
        $doc     = new \SimpleXMLElement($xml);
        $records = [];

        foreach ($doc->INDIVIDUALS->INDIVIDUAL ?? [] as $entry) {
            $parts    = array_filter([
                (string) ($entry->FIRST_NAME  ?? ''),
                (string) ($entry->SECOND_NAME ?? ''),
                (string) ($entry->THIRD_NAME  ?? ''),
                (string) ($entry->FOURTH_NAME ?? ''),
            ]);
            $fullName = trim(implode(' ', $parts));
            if (empty($fullName)) continue;

            $aliases = [];
            foreach ($entry->INDIVIDUAL_ALIAS ?? [] as $alias) {
                $ak = trim((string) ($alias->ALIAS_NAME ?? ''));
                if ($ak) $aliases[] = $ak;
            }

            $dob = null;
            $dobRaw = (string) ($entry->INDIVIDUAL_DATE_OF_BIRTH->DATE ?? '');
            if ($dobRaw) {
                try { $dob = \Carbon\Carbon::parse($dobRaw)->toDateString(); } catch (\Exception) {}
            }

            $records[] = [
                'source'               => 'un',
                'source_id'            => (string) $entry->DATAID,
                'entry_type'           => 'individual',
                'full_name'            => $fullName,
                'full_name_normalized' => $this->normalize($fullName),
                'aliases'              => json_encode(array_values($aliases)),
                'aliases_normalized'   => json_encode(array_map([$this, 'normalize'], $aliases)),
                'dob'                  => $dob,
                'nationality'          => (string) ($entry->NATIONALITY->VALUE ?? null) ?: null,
                'program'              => (string) ($entry->UN_LIST_TYPE ?? ''),
                'is_pep'               => false,
                'raw'                  => json_encode(['dataid' => (string) $entry->DATAID]),
                'created_at'           => now(),
                'updated_at'           => now(),
            ];
        }

        foreach ($doc->ENTITIES->ENTITY ?? [] as $entry) {
            $fullName = trim((string) ($entry->FIRST_NAME ?? ''));
            if (empty($fullName)) continue;

            $aliases = [];
            foreach ($entry->ENTITY_ALIAS ?? [] as $alias) {
                $ak = trim((string) ($alias->ALIAS_NAME ?? ''));
                if ($ak) $aliases[] = $ak;
            }

            $records[] = [
                'source'               => 'un',
                'source_id'            => 'E-' . (string) $entry->DATAID,
                'entry_type'           => 'entity',
                'full_name'            => $fullName,
                'full_name_normalized' => $this->normalize($fullName),
                'aliases'              => json_encode(array_values($aliases)),
                'aliases_normalized'   => json_encode(array_map([$this, 'normalize'], $aliases)),
                'dob'                  => null,
                'nationality'          => null,
                'program'              => (string) ($entry->UN_LIST_TYPE ?? ''),
                'is_pep'               => false,
                'raw'                  => json_encode(['dataid' => (string) $entry->DATAID]),
                'created_at'           => now(),
                'updated_at'           => now(),
            ];
        }

        return $this->sync($records);
    }
}
