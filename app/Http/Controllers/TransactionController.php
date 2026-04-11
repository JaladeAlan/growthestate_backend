<?php

namespace App\Http\Controllers;

use App\Models\Deposit;
use App\Models\Transaction;
use App\Models\Withdrawal;
use Illuminate\Http\Request;

/**
 * Routes:
 *   GET /transactions/user
 */
class TransactionController extends Controller
{
    private const PER_PAGE = 20;

    // GET /transactions/user
    public function userTransactions(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        $userId = $user->id;

        // ── Land transactions (purchases / sales) ─────────────────────────
        $landTx = Transaction::with('land:id,title')
            ->where('user_id', $userId)
            ->select(['id', 'type', 'land_id', 'units', 'amount_kobo', 'transaction_date', 'status'])
            ->get()
            ->map(fn (Transaction $t) => [
                'type'   => ucfirst($t->type),
                'land'   => $t->land?->title,
                'units'  => $t->units,
                'amount' => ($t->amount_kobo ?? 0) / 100,       
                'date'   => $t->transaction_date?->toISOString(), 
                'status' => ucfirst($t->status ?? 'Completed'),
            ]);

        // ── Deposits ──────────────────────────────────────────────────────
        $deposits = Deposit::where('user_id', $userId)
            ->select(['id', 'amount_kobo', 'status', 'created_at'])
            ->get()
            ->map(fn (Deposit $d) => [
                'type'   => 'Deposit',
                'land'   => null,
                'units'  => null,
                'amount' => ($d->amount_kobo ?? 0) / 100,
                'date'   => $d->created_at?->toISOString(),
                'status' => ucfirst($d->status ?? 'pending'),
            ]);

        // ── Withdrawals ───────────────────────────────────────────────────
        $withdrawals = Withdrawal::where('user_id', $userId)
            ->select(['id', 'amount_kobo', 'status', 'created_at'])
            ->get()
            ->map(fn (Withdrawal $w) => [
                'type'   => 'Withdrawal',
                'land'   => null,
                'units'  => null,
                'amount' => ($w->amount_kobo ?? 0) / 100,
                'date'   => $w->created_at?->toISOString(),
                'status' => ucfirst($w->status ?? 'pending'),
            ]);

        // ── Merge & sort ──────────────────────────────────────────────────
        $page    = max(1, (int) $request->query('page', 1));
        $perPage = self::PER_PAGE;

        $all = $landTx
            ->concat($deposits)
            ->concat($withdrawals)
            ->sortByDesc('date')  
            ->values()
            ->forPage($page, $perPage);

        return response()->json([
            'success'  => true,
            'data'     => $all->values(),
            'meta'     => [
                'page'     => $page,
                'per_page' => $perPage,
            ],
        ]);
    }
}