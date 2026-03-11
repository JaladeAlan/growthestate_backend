<?php

namespace App\Http\Controllers;

use App\Models\SupportMessage;
use App\Models\SupportTicket;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Storage;

class AdminSupportController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────────
    // LIST  GET /admin/support/tickets
    // ─────────────────────────────────────────────────────────────────────────
    public function index(Request $request)
    {
        $request->validate([
            'status'   => ['sometimes', Rule::in(['open', 'waiting', 'closed'])],
            'priority' => ['sometimes', Rule::in(['low', 'normal', 'high'])],
            'category' => ['sometimes', Rule::in(['account', 'payment', 'kyc', 'investment', 'withdrawal', 'other'])],
            'search'   => 'sometimes|string|max:100',
            'per_page' => 'sometimes|integer|min:0|max:100',
        ]);

        $perPage = (int) $request->input('per_page', 20);

        $query = SupportTicket::with(['user:id,name,email', 'latestMessage'])
            ->when($request->status,   fn ($q) => $q->where('status', $request->status))
            ->when($request->priority, fn ($q) => $q->where('priority', $request->priority))
            ->when($request->category, fn ($q) => $q->where('category', $request->category))
            ->when($request->search,   fn ($q) => $q->where(function ($q2) use ($request) {
                $q2->where('reference', 'like', "%{$request->search}%")
                   ->orWhere('subject',     'like', "%{$request->search}%")
                   ->orWhere('guest_email', 'like', "%{$request->search}%");
            }))
            ->orderByRaw("CASE status WHEN 'open' THEN 0 WHEN 'waiting' THEN 1 ELSE 2 END")
            ->orderByDesc('updated_at');

        $tickets = $perPage === 0 ? $query->get() : $query->paginate($perPage);

        return response()->json(['success' => true, 'data' => $tickets]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SHOW  GET /admin/support/tickets/{ticket}
    // ─────────────────────────────────────────────────────────────────────────
    public function show(SupportTicket $ticket)
    {
        return response()->json([
            'success' => true,
            'data'    => $ticket->load(['user:id,name,email', 'messages']),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // REPLY  POST /admin/support/tickets/{ticket}/reply
    // ─────────────────────────────────────────────────────────────────────────
    public function reply(Request $request, SupportTicket $ticket)
    {
        if ($ticket->status === 'closed') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot reply to a closed ticket. Reopen it first by changing its status.',
            ], 422);
        }

        $request->validate([
            'message'    => 'required|string|max:5000',
            'attachment' => 'nullable|file|max:10240|mimes:jpg,jpeg,png,pdf,mp4,webm',
        ]);

        $attachmentPath = null;
        if ($request->hasFile('attachment')) {
            $attachmentPath = $request->file('attachment')
                ->store('support-attachments', 'public');
        }

        $admin = $request->user();

        $msg = SupportMessage::create([
            'ticket_id'       => $ticket->id,
            'sender_type'     => 'admin',
            'sender_id'       => $admin->id,
            'body'            => $request->message,
            'attachment_path' => $attachmentPath,
        ]);

        $ticket->update(['status' => 'waiting']);
        $ticket->touch();

        Log::info('Admin replied to support ticket', [
            'ticket_id'   => $ticket->id,
            'reference'   => $ticket->reference,
            'by_admin_id' => $admin->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Reply sent.',
            'data'    => $msg,
        ], 201);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // UPDATE STATUS / PRIORITY  PATCH /admin/support/tickets/{ticket}/status
    // ─────────────────────────────────────────────────────────────────────────
    public function updateStatus(Request $request, SupportTicket $ticket)
    {
        $request->validate([
            'status'   => ['sometimes', Rule::in(['open', 'waiting', 'closed'])],
            'priority' => ['sometimes', Rule::in(['low', 'normal', 'high'])],
        ]);

        if (! $request->hasAny(['status', 'priority'])) {
            return response()->json([
                'success' => false,
                'message' => 'Provide at least one of: status, priority.',
            ], 422);
        }

        $changes = $request->only('status', 'priority');
        $ticket->update($changes);

        Log::info('Support ticket updated', [
            'ticket_id'   => $ticket->id,
            'reference'   => $ticket->reference,
            'changes'     => $changes,
            'by_admin_id' => $request->user()->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Ticket updated.',
            'data'    => $ticket->only('id', 'reference', 'status', 'priority', 'updated_at'),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DELETE  DELETE /admin/support/tickets/{ticket}
    // ─────────────────────────────────────────────────────────────────────────
    public function destroy(Request $request, SupportTicket $ticket)
    {
        if ($ticket->status !== 'closed') {
            return response()->json([
                'success' => false,
                'message' => 'Only closed tickets can be deleted. Close the ticket first.',
            ], 422);
        }

        $reference = $ticket->reference;

        $ticket->messages()->delete();
        $ticket->delete();

        Log::info('Support ticket deleted', [
            'reference'   => $reference,
            'by_admin_id' => $request->user()->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => "Ticket {$reference} deleted.",
        ]);
    }

    public function attachment(SupportMessage $message)
    {
        if (!$message->attachment_path) {
            return response()->json(['message' => 'No attachment on this message.'], 404);
        }
        
        if (!Storage::disk('r2')->exists($message->attachment_path)) {
            return response()->json(['message' => 'File not found.'], 404);
        }
        return Storage::disk('r2')->response($message->attachment_path);
    }
}