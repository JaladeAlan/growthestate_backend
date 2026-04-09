<?php

namespace App\Http\Controllers;

use App\Events\AgentTyping;
use App\Events\LiveChatMessage;
use App\Events\LiveChatStatusChanged;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class LiveChatController extends Controller
{
    // ═══════════════════════════════════════════════════════════════════════════
    // USER: REQUEST LIVE AGENT  —  POST /api/support/live-chat/request
    // ═══════════════════════════════════════════════════════════════════════════

    public function request(Request $request): JsonResponse
    {
        $user = $request->user();

        // Find existing open ticket or create a new one
        $ticket = SupportTicket::where('user_id', $user->id)
            ->whereIn('status', ['open', 'waiting'])
            ->latest()
            ->first();

        if (! $ticket) {
            $request->validate([
                'subject'  => 'required|string|max:150',
                'category' => 'required|in:account,payment,kyc,investment,withdrawal,other',
                'message'  => 'required|string|max:3000',
            ]);

            $ticket = SupportTicket::create([
                'user_id'   => $user->id,
                'reference' => $this->generateReference(),
                'subject'   => $request->subject,
                'category'  => $request->category,
                'status'    => 'open',
                'priority'  => 'normal',
                'chat_mode' => 'live',
            ]);

            SupportMessage::create([
                'ticket_id'   => $ticket->id,
                'sender_type' => 'user',
                'sender_id'   => $user->id,
                'body'        => $request->message,
            ]);
        } else {
            // Upgrade existing ticket to live chat
            $ticket->update(['chat_mode' => 'live']);
        }

        // Add to the agent queue
        $this->addToQueue($ticket->id);

        // Broadcast to all available agents
        broadcast(new LiveChatStatusChanged(
            ticketId: $ticket->id,
            status:   'queued',
            payload:  [
                'reference' => $ticket->reference,
                'subject'   => $ticket->subject,
                'user'      => ['id' => $user->id, 'name' => $user->name],
                'queue_pos' => $this->getQueuePosition($ticket->id),
            ]
        ))->toOthers();

        return response()->json([
            'success'   => true,
            'ticket_id' => $ticket->id,
            'reference' => $ticket->reference,
            'queue_pos' => $this->getQueuePosition($ticket->id),
            'message'   => 'You have been added to the queue. An agent will join shortly.',
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // USER: SEND LIVE MESSAGE  —  POST /api/support/live-chat/{ticket}/message
    // ═══════════════════════════════════════════════════════════════════════════

    public function sendMessage(Request $request, SupportTicket $ticket): JsonResponse
    {
        if ($ticket->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $request->validate([
            'body'       => 'required|string|max:2000',
            'attachment' => 'nullable|file|max:5120|mimes:jpg,jpeg,png,pdf',
        ]);

        $attachmentPath = null;
        if ($request->hasFile('attachment')) {
            $attachmentPath = $request->file('attachment')->store('support-attachments', 'private');
        }

        $message = SupportMessage::create([
            'ticket_id'       => $ticket->id,
            'sender_type'     => 'user',
            'sender_id'       => $request->user()->id,
            'body'            => $request->body,
            'attachment_path' => $attachmentPath,
        ]);

        // Broadcast to the agent on the ticket's private channel
        broadcast(new LiveChatMessage(
            ticketId: $ticket->id,
            message:  $message,
            sender:   'user',
        ))->toOthers();

        return response()->json(['success' => true, 'data' => $message], 201);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // USER: TYPING INDICATOR  —  POST /api/support/live-chat/{ticket}/typing
    // ═══════════════════════════════════════════════════════════════════════════

    public function typing(Request $request, SupportTicket $ticket): JsonResponse
    {
        if ($ticket->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        broadcast(new AgentTyping(
            ticketId: $ticket->id,
            sender:   'user',
            isTyping: $request->boolean('is_typing'),
        ))->toOthers();

        return response()->json(['success' => true]);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // AGENT: CLAIM TICKET  —  POST /api/admin/live-chat/{ticket}/claim
    // ═══════════════════════════════════════════════════════════════════════════

    public function agentClaim(Request $request, SupportTicket $ticket): JsonResponse
    {
        if ($ticket->agent_id) {
            return response()->json(['message' => 'This ticket is already claimed.'], 409);
        }

        $ticket->update([
            'agent_id'  => $request->user()->id,
            'status'    => 'waiting',
            'chat_mode' => 'live',
        ]);

        $this->removeFromQueue($ticket->id);

        // Notify the user that an agent has joined
        broadcast(new LiveChatStatusChanged(
            ticketId: $ticket->id,
            status:   'agent_joined',
            payload:  [
                'agent_name' => $request->user()->name,
                'message'    => $request->user()->name . ' has joined the chat.',
            ]
        ))->toOthers();

        return response()->json([
            'success' => true,
            'message' => 'Ticket claimed successfully.',
            'data'    => $ticket->load('messages', 'user'),
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // AGENT: SEND MESSAGE  —  POST /api/admin/live-chat/{ticket}/message
    // ═══════════════════════════════════════════════════════════════════════════

    public function agentMessage(Request $request, SupportTicket $ticket): JsonResponse
    {
        $request->validate([
            'body' => 'required|string|max:2000',
        ]);

        $message = SupportMessage::create([
            'ticket_id'   => $ticket->id,
            'sender_type' => 'agent',
            'sender_id'   => $request->user()->id,
            'body'        => $request->body,
        ]);

        broadcast(new LiveChatMessage(
            ticketId: $ticket->id,
            message:  $message,
            sender:   'agent',
        ))->toOthers();

        return response()->json(['success' => true, 'data' => $message], 201);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // AGENT: TYPING  —  POST /api/admin/live-chat/{ticket}/typing
    // ═══════════════════════════════════════════════════════════════════════════

    public function agentTyping(Request $request, SupportTicket $ticket): JsonResponse
    {
        broadcast(new AgentTyping(
            ticketId: $ticket->id,
            sender:   'agent',
            isTyping: $request->boolean('is_typing'),
        ))->toOthers();

        return response()->json(['success' => true]);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // AGENT: END CHAT  —  POST /api/admin/live-chat/{ticket}/end
    // ═══════════════════════════════════════════════════════════════════════════

    public function agentEnd(Request $request, SupportTicket $ticket): JsonResponse
    {
        $ticket->update([
            'status'      => 'resolved',
            'chat_mode'   => null,
            'resolved_at' => now(),
        ]);

        broadcast(new LiveChatStatusChanged(
            ticketId: $ticket->id,
            status:   'ended',
            payload:  ['message' => 'The agent has ended the chat. Ticket is now resolved.']
        ))->toOthers();

        return response()->json(['success' => true, 'message' => 'Chat ended and ticket resolved.']);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // AGENT: QUEUE  —  GET /api/admin/live-chat/queue
    // ═══════════════════════════════════════════════════════════════════════════

    public function agentQueue(): JsonResponse
    {
        $queue = $this->getQueue();

        $tickets = SupportTicket::whereIn('id', $queue)
            ->with('user:id,name,email')
            ->get()
            ->sortBy(fn ($t) => array_search($t->id, $queue))
            ->values();

        return response()->json(['success' => true, 'data' => $tickets]);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // PRIVATE HELPERS
    // ═══════════════════════════════════════════════════════════════════════════

    private function getQueue(): array
    {
        return Cache::get('live_chat_queue', []);
    }

    private function addToQueue(int $ticketId): void
    {
        $queue = $this->getQueue();
        if (! in_array($ticketId, $queue)) {
            $queue[] = $ticketId;
            Cache::put('live_chat_queue', $queue, now()->addHours(4));
        }
    }

    private function removeFromQueue(int $ticketId): void
    {
        $queue = array_values(array_filter($this->getQueue(), fn ($id) => $id !== $ticketId));
        Cache::put('live_chat_queue', $queue, now()->addHours(4));
    }

    private function getQueuePosition(int $ticketId): int
    {
        $pos = array_search($ticketId, $this->getQueue());
        return $pos !== false ? $pos + 1 : 1;
    }

    private function generateReference(): string
    {
        do {
            $ref = 'TKT-' . strtoupper(\Illuminate\Support\Str::random(8));
        } while (SupportTicket::where('reference', $ref)->exists());

        return $ref;
    }
}
