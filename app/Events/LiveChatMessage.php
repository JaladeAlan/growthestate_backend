<?php
// ══════════════════════════════════════════════════════════════
// Broadcasts a new chat message to both user and agent
// ══════════════════════════════════════════════════════════════

namespace App\Events;

use App\Models\SupportMessage;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LiveChatMessage implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int            $ticketId,
        public SupportMessage $message,
        public string         $sender,     // 'user' | 'agent'
    ) {}

    public function broadcastOn(): array
    {
        return [
            // User listens on their private channel
            new PrivateChannel("support.ticket.{$this->ticketId}"),
            // Agent listens on the presence channel
            new PresenceChannel("agent.chat.{$this->ticketId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    public function broadcastWith(): array
    {
        return [
            'id'          => $this->message->id,
            'ticket_id'   => $this->ticketId,
            'body'        => $this->message->body,
            'sender_type' => $this->message->sender_type,
            'sender_id'   => $this->message->sender_id,
            'has_attachment' => (bool) $this->message->attachment_path,
            'created_at'  => $this->message->created_at,
        ];
    }
}
