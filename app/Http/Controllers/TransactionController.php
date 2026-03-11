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
    // GET /transactions/user
    public function userTransactions(Request $request)
    {
        $userId = $request->user()->id;

        // ── Land transactions (purchases / sales) ─────────────────────────
        $landTx = Transaction::with('land:id,title')
            ->where('user_id', $userId)
            ->get()
            ->map(fn ($t) => [
                'type'   => ucfirst($t->type),   // "Purchase" | "Sale"
                'land'   => $t->land?->title,
                'units'  => $t->units,
                'amount' => $t->amount_kobo / 100,
                'date'   => $t->transaction_date,
                'status' => $t->status ?? 'Completed',
            ]);

        // ── Deposits ──────────────────────────────────────────────────────
        $deposits = Deposit::where('user_id', $userId)
            ->get()
            ->map(fn ($d) => [
                'type'   => 'Deposit',
                'land'   => null,
                'units'  => null,
                'amount' => $d->amount_kobo / 100,
                'date'   => $d->created_at,
                'status' => ucfirst($d->status),
            ]);

        // ── Withdrawals ───────────────────────────────────────────────────
        $withdrawals = Withdrawal::where('user_id', $userId)
            ->get()
            ->map(fn ($w) => [
                'type'   => 'Withdrawal',
                'land'   => null,
                'units'  => null,
                'amount' => $w->amount_kobo / 100,
                'date'   => $w->created_at,
                'status' => ucfirst($w->status),
            ]);

        // ── Merge, sort descending by date, re-index ──────────────────────
        $all = $landTx
            ->concat($deposits)
            ->concat($withdrawals)
            ->sortByDesc('date')
            ->values();

        return response()->json(['success' => true, 'data' => $all]);
    }
}