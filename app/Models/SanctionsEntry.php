<?php

namespace App\Models;
 
use Illuminate\Database\Eloquent\Model;
 
class SanctionsEntry extends Model
{
    protected $fillable = [
        'source', 'source_id', 'entry_type', 'full_name',
        'full_name_normalized', 'aliases', 'aliases_normalized',
        'dob', 'nationality', 'program', 'is_pep', 'raw',
    ];
 
    protected $casts = [
        'aliases'            => 'array',
        'aliases_normalized' => 'array',
        'is_pep'             => 'boolean',
        'raw'                => 'array',
        'dob'                => 'date',
    ];
 
    /**
     * Normalize a name for matching:
     * lowercase, remove diacritics, collapse spaces, remove punctuation.
     */
    public static function normalizeName(string $name): string
    {
        // Transliterate accented characters
        $name = transliterator_transliterate('Any-Latin; Latin-ASCII', $name) ?? $name;
        $name = strtolower($name);
        $name = preg_replace('/[^a-z0-9\s]/', '', $name);
        $name = preg_replace('/\s+/', ' ', trim($name));
        return $name;
    }
}