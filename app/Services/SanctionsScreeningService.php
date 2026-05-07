<?php

namespace App\Services;

use App\Models\SanctionsEntry;
use App\Models\User;
use App\Models\UserScreening;
use Illuminate\Support\Facades\Log;

class SanctionsScreeningService
{
    /**
     * Minimum similarity score (0–100) to flag a match.
     * 85 = high confidence, reduces false positives.
     * Lower this if you want stricter screening.
     */
    private const FLAG_THRESHOLD  = 85;
    private const BLOCK_THRESHOLD = 95;

    /**
     * Screen a user against all loaded sanctions lists.
     */
   public function screen(User $user, string $trigger = 'manual'): UserScreening
    {
        $namesToCheck = $this->buildNameVariants($user);
        $matches      = [];

        foreach ($namesToCheck as $nameVariant) {
            $normalized = SanctionsEntry::normalizeName($nameVariant);
            $hits       = $this->findCandidates($normalized);

            foreach ($hits as $entry) {
                $score = $this->fuzzyScore($normalized, $entry->full_name_normalized);

                foreach ($entry->aliases_normalized ?? [] as $alias) {
                    $aliasScore = $this->fuzzyScore($normalized, $alias);
                    $score      = max($score, $aliasScore);
                }

                if ($score >= self::FLAG_THRESHOLD) {
                    $matches[] = [
                        'sanctions_entry_id' => $entry->id,
                        'source'             => $entry->source,
                        'matched_name'       => $entry->full_name,
                        'queried_name'       => $nameVariant,
                        'score'              => $score,
                        'is_pep'             => $entry->is_pep,
                        'program'            => $entry->program,
                        'entry_type'         => $entry->entry_type,
                    ];
                }
            }
        }

        $matches = collect($matches)
            ->groupBy('sanctions_entry_id')
            ->map(fn($g) => $g->sortByDesc('score')->first())
            ->values()
            ->toArray();

        $status = $this->resolveStatus($matches);

        // Upsert into any existing unreviewed screening rather than stacking duplicates
        $existing = UserScreening::where('user_id', $user->id)
            ->whereIn('status', ['flagged', 'blocked'])
            ->whereNull('reviewed_at')
            ->first();

        if ($existing) {
            $existing->update([
                'trigger' => $trigger,
                'matches' => $matches,
                'status'  => $status,
            ]);
            $screening = $existing;
        } else {
            $screening = UserScreening::create([
                'user_id' => $user->id,
                'status'  => $status,
                'trigger' => $trigger,
                'matches' => $matches,
            ]);
        }

        $user->update([
            'screening_status' => $user->screening_status === 'blocked' ? 'blocked' : $status,
            'last_screened_at' => now(),
        ]);

        if (in_array($status, ['flagged', 'blocked'])) {
            $this->escalate($user, $screening);
        }

        return $screening;
    }
    /**
     * Build all name variants to check — full name, reversed, with/without middle name.
     */
    private function buildNameVariants(User $user): array
    {
        $kycName = \App\Models\KycVerification::where('user_id', $user->id)
            ->where('status', 'approved')
            ->value('full_name');

        $reversed = $kycName
            ? implode(' ', array_reverse(explode(' ', $kycName)))
            : null;

        $variants = array_values(array_unique(array_filter(
            [$kycName, $user->name, $reversed],
            fn($v) => is_string($v) && trim($v) !== ''
        )));

        // Safety: if we have nothing to screen, throw rather than silently clearing
        if (empty($variants)) {
            throw new \RuntimeException("User {$user->id} has no name to screen.");
        }

        return $variants;
    }

    /**
     * Fetch candidate entries from DB using trigram-style prefix matching.
     * Gets all entries where at least the first word of the normalized name matches.
     */
    private function findCandidates(string $normalizedName): \Illuminate\Support\Collection
    {
        // Extract first meaningful word (usually surname)
        $words = explode(' ', $normalizedName);
        $firstWord = $words[0] ?? '';

        if (strlen($firstWord) < 2) {
            return collect();
        }

        // Escape LIKE wildcards before interpolation
        $firstWord = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $firstWord);

        return SanctionsEntry::where('full_name_normalized', 'like', "%{$firstWord}%")
            ->orWhereJsonContains('aliases_normalized', $normalizedName)
            ->limit(50)
            ->get();
    }

    /**
     * Fuzzy similarity score between two normalized strings (0–100).
     * Uses similar_text + Levenshtein combination for best accuracy.
     */
    private function fuzzyScore(string $a, string $b): int
    {
        if ($a === $b) return 100;
        if (empty($a) || empty($b)) return 0;

        // similar_text percentage
        similar_text($a, $b, $similarPct);

        // Levenshtein distance converted to percentage
        $maxLen = max(strlen($a), strlen($b));
        $lev    = levenshtein($a, $b);
        $levPct = (1 - $lev / $maxLen) * 100;

        // Token sort: sort words, compare — handles "John Smith" vs "Smith John"
        $aTokens = explode(' ', $a);
        $bTokens = explode(' ', $b);
        sort($aTokens);
        sort($bTokens);
        similar_text(implode(' ', $aTokens), implode(' ', $bTokens), $tokenPct);

        // Weighted average — token sort gets highest weight for name matching
        return (int) round(($similarPct * 0.25) + ($levPct * 0.25) + ($tokenPct * 0.50));
    }

    /**
     * Resolve overall status from all matches.
     */
    private function resolveStatus(array $matches): string
    {
        if (empty($matches)) return 'clear';

        foreach ($matches as $match) {
            // PEP hits always go to manual review regardless of score.
            // Only hard sanctions (non-PEP) at >= BLOCK_THRESHOLD are auto-blocked.
            if (! $match['is_pep'] && $match['score'] >= self::BLOCK_THRESHOLD) {
                return 'blocked';
            }
        }

        return 'flagged';
    }

    /**
     * Escalate flagged/blocked users to compliance team.
     */
    private function escalate(User $user, UserScreening $screening): void
    {
        Log::channel('telegram')->warning(
            $screening->status === 'blocked'
                ? '🚨 SANCTIONS MATCH — User blocked'
                : '⚠️ PEP/Sanctions flag — Manual review required',
            [
                'user_id'      => $user->id,
                'name'         => $user->name,
                'email'        => $user->email,
                'status'       => $screening->status,
                'match_count'  => count($screening->matches ?? []),
                'top_score'    => collect($screening->matches)->max('score'),
                'screening_id' => $screening->id,
                'review_url'   => url("/admin/compliance/screenings/{$screening->id}"),
            ]
        );

        if ($screening->status === 'blocked') {
            $user->update(['is_suspended' => true]);

            // Notify user their account has been suspended
            $user->notify(new \App\Notifications\AccountSuspendedNotification());
        } else {
            // Notify user their account is under review (don't reveal why)
            $user->notify(new \App\Notifications\AccountUnderReviewNotification());
        }
    }

}
