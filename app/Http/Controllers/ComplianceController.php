<?php
// ══════════════════════════════════════════════════════
// app/Http/Controllers/ComplianceController.php
// Endpoints for compliance-related actions
// ══════════════════════════════════════════════════════

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Jobs\ScreenUserJob;
use App\Models\User;
use App\Models\UserScreening;
use App\Models\SanctionsEntry;
use App\Services\SanctionsScreeningService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ComplianceController extends Controller
{
    /**
     * GET /api/admin/compliance/screenings
     * List all flagged/blocked screenings for review.
     */
    public function index(Request $request)
    {
        $screenings = UserScreening::with('user:id,name,email,screening_status')
            ->whereIn('status', ['flagged', 'blocked'])
            ->whereNull('reviewed_at')
            ->latest()
            ->paginate(20);

        return response()->json(['success' => true, 'data' => $screenings]);
    }

    /**
     * GET /api/admin/compliance/screenings/{screening}
     * View a single screening with full match details.
     */
    public function show(UserScreening $screening)
    {
        return response()->json([
            'success' => true,
            'data'    => $screening->load('user'),
        ]);
    }

    /**
     * POST /api/admin/compliance/screenings/{screening}/clear
     * Mark a false-positive as clear after manual review.
     */
    public function clear(Request $request, UserScreening $screening)
    {
        $request->validate(['notes' => 'required|string|min:10']);

        DB::transaction(function () use ($request, $screening) {
            // Lock the row for the duration of the transaction
            $screening = UserScreening::lockForUpdate()->findOrFail($screening->id);

            if ($screening->reviewed_at !== null) {
                abort(409, 'This screening has already been reviewed.');
            }

            $screening->update([
                'status'      => 'clear',
                'reviewed_by' => $request->user()->name,
                'reviewed_at' => now(),
                'notes'       => $request->notes,
            ]);

            $screening->user->update(['screening_status' => 'clear']);
        });

        return response()->json(['success' => true, 'message' => 'User cleared after manual review.']);
    }

    /**
     * POST /api/admin/compliance/screenings/{screening}/block
     * Confirm a match and block the user.
     */
    public function block(Request $request, UserScreening $screening)
    {
        $request->validate(['notes' => 'required|string|min:10']);

        DB::transaction(function () use ($request, $screening) {
            // Lock the row for the duration of the transaction
            $screening = UserScreening::lockForUpdate()->findOrFail($screening->id);

            $screening->update([
                'status'      => 'blocked',
                'reviewed_by' => $request->user()->name,
                'reviewed_at' => now(),
                'notes'       => $request->notes,
            ]);

        $screening->user->update([
            'screening_status' => 'blocked',
            'is_suspended'     => true,
        ]);

            return response()->json(['success' => true, 'message' => 'User blocked.']);
        });
    }

    /**
     * POST /api/admin/compliance/users/{user}/rescreen
     * Manually trigger a re-screen for a specific user.
     */
    public function rescreen(User $user)
    {
        ScreenUserJob::dispatch($user, 'manual')->onQueue('default');

        return response()->json(['success' => true, 'message' => 'Re-screening queued.']);
    }

    /**
     * GET /api/admin/compliance/stats
     * Summary stats for the compliance dashboard.
     */
    public function stats()
    {
        return response()->json([
            'success' => true,
            'data'    => [
                'total_screened'      => UserScreening::count(),
                'pending_review'      => UserScreening::whereIn('status', ['flagged', 'blocked'])->whereNull('reviewed_at')->count(),
                'blocked_users'       => User::where('screening_status', 'blocked')->count(),
                'flagged_users'       => User::where('screening_status', 'flagged')->count(),
                'clear_users'         => User::where('screening_status', 'clear')->count(),
                'never_screened'      => User::whereNull('last_screened_at')->count(),
                'sanctions_entries'   => SanctionsEntry::count(),
                'last_sync'           => \DB::table('sanctions_list_syncs')->latest('synced_at')->first(),
            ],
        ]);
    }
}
