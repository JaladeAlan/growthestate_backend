<?php

namespace App\Http\Controllers;

use App\Models\Waitlist;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WaitlistController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────────
    // PUBLIC
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * POST /waitlist
     *
     * Joins the waitlist. If a valid referral_code is supplied:
     *   - the new entrant gets a position bonus (moves up by REFERRAL_BOOST spots)
     *   - the referrer's referral_count is incremented and their position improves
     *
     * Returns:
     *   position      — queue position of the new entrant
     *   referral_code — their own shareable code
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name'          => 'required|string|max:120',
            'email'         => 'required|email|max:200',
            'budget'        => 'nullable|in:5k_50k,50k_500k,500k_plus',
            'city'          => 'nullable|in:ogun,oyo,abuja,other',
            'referral_code' => 'nullable|string|max:12',
        ]);

        // Already on the list — return their existing entry silently
        $existing = Waitlist::where('email', $data['email'])->first();
        if ($existing) {
            return response()->json([
                'success' => true,
                'data'    => [
                    'position'      => $existing->position,
                    'referral_code' => $existing->referral_code,
                ],
            ]);
        }

        return DB::transaction(function () use ($data) {

            // ── Resolve referrer ──────────────────────────────────────────
            $referrer = null;
            if (! empty($data['referral_code'])) {
                $referrer = Waitlist::where('referral_code', $data['referral_code'])
                    ->lockForUpdate()
                    ->first();
            }

            // ── Assign position ───────────────────────────────────────────
            // Base position = current count + 1
            $basePosition = Waitlist::count() + 1;

            // Referred users jump ahead by REFERRAL_BOOST spots
            $boost    = $referrer ? (int) config('waitlist.referral_boost', 3) : 0;
            $position = max(1, $basePosition - $boost);

            // Push everyone at or below this position down by 1
            if ($boost > 0) {
                Waitlist::where('position', '>=', $position)
                    ->increment('position');
            }

            // ── Create entry ──────────────────────────────────────────────
            $entry = Waitlist::create([
                'name'             => $data['name'],
                'email'            => $data['email'],
                'budget'           => $data['budget'] ?? null,
                'city'             => $data['city']   ?? null,
                'position'         => $position,
                'referral_code'    => $this->generateUniqueCode(),
                'referred_by_code' => $referrer?->referral_code,
                'referral_count'   => 0,
            ]);

            // ── Reward referrer ───────────────────────────────────────────
            if ($referrer) {
                $referrer->increment('referral_count');

                // Move referrer up by REFERRER_BOOST spots for each referral
                $referrerBoost = (int) config('waitlist.referrer_boost', 5);
                $newPos        = max(1, $referrer->position - $referrerBoost);

                // Shift anyone between newPos and referrer's old position down
                if ($newPos < $referrer->position) {
                    Waitlist::where('position', '>=', $newPos)
                        ->where('id', '!=', $referrer->id)
                        ->increment('position');

                    $referrer->update(['position' => $newPos]);
                }
            }

            return response()->json([
                'success' => true,
                'data'    => [
                    'position'      => $entry->position,
                    'referral_code' => $entry->referral_code,
                ],
            ], 201);
        });
    }

   public function check(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $entry = Waitlist::where('email', $request->email)->first();

        if (!$entry) {
            return response()->json([
                'success' => false,
                'message' => "We couldn't find that email on the waitlist.",
            ], 404);
        }

        $referralsCount = Waitlist::where('referred_by_code', $entry->referral_code)->count();

        return response()->json([
            'success' => true,
            'data' => [
                'name'            => $entry->name,
                'position'        => $entry->position,
                'referral_code'   => $entry->referral_code,
                'referrals_count' => $referralsCount,
                'invited'         => $entry->invited,
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ADMIN
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * GET /admin/waitlist
     * Paginated list sorted by position.
     */
    public function index(Request $request)
    {
        $entries = Waitlist::orderBy('position')
            ->paginate($request->integer('per_page', 50));

        return response()->json(['success' => true, 'data' => $entries]);
    }

    /**
     * GET /admin/waitlist/stats
     * Quick summary for the admin dashboard.
     */
    public function stats()
    {
        return response()->json([
            'success' => true,
            'data'    => [
                'total'          => Waitlist::count(),
                'invited'        => Waitlist::where('invited', true)->count(),
                'with_referrals' => Waitlist::where('referral_count', '>', 0)->count(),
                'by_city'        => Waitlist::selectRaw('city, count(*) as count')
                    ->groupBy('city')
                    ->pluck('count', 'city'),
                'by_budget'      => Waitlist::selectRaw('budget, count(*) as count')
                    ->groupBy('budget')
                    ->pluck('count', 'budget'),
            ],
        ]);
    }

    /**
     * POST /admin/waitlist/{id}/invite
     * Mark a single entry as invited.
     */
    public function invite(Waitlist $waitlist)
    {
        $waitlist->update([
            'invited'    => true,
            'invited_at' => now(),
        ]);

        // TODO: dispatch SendWaitlistInviteEmail job here

        return response()->json(['success' => true, 'message' => 'Marked as invited.']);
    }

    /**
     * DELETE /admin/waitlist/{id}
     * Remove an entry and close the gap in positions.
     */
    public function destroy(Waitlist $waitlist)
    {
        $pos = $waitlist->position;
        $waitlist->delete();

        // Close the gap
        Waitlist::where('position', '>', $pos)->decrement('position');

        return response()->json(['success' => true, 'message' => 'Entry removed.']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE
    // ─────────────────────────────────────────────────────────────────────────

    private function generateUniqueCode(): string
    {
        do {
            // 8 uppercase alphanumeric characters — short enough to share verbally
            $code = strtoupper(Str::random(8));
        } while (Waitlist::where('referral_code', $code)->exists());

        return $code;
    }
}