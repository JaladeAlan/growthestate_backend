<?php
// ══════════════════════════════════════════════════════════════
// Broadcasts queue position, agent joined, chat ended etc.
// ══════════════════════════════════════════════════════════════

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LiveChatStatusChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int    $ticketId,
        public string $status,   // 'queued' | 'agent_joined' | 'ended'
        public array  $payload = [],
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("support.ticket.{$this->ticketId}"),
            new PresenceChannel("agent.chat.{$this->ticketId}"),
            // Agents also listen on a global channel for new queue items
            new Channel('agents'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'status.changed';
    }

    public function broadcastWith(): array
    {
        return array_merge(['status' => $this->status], $this->payload);
    }
}
