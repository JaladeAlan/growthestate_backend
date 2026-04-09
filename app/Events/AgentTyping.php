<?php
// ══════════════════════════════════════════════════════════════
// Broadcasts typing indicator to either party
// ══════════════════════════════════════════════════════════════

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AgentTyping implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int    $ticketId,
        public string $sender,    // 'user' | 'agent'
        public bool   $isTyping,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("support.ticket.{$this->ticketId}"),
            new PresenceChannel("agent.chat.{$this->ticketId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'typing';
    }

    public function broadcastWith(): array
    {
        return [
            'sender'    => $this->sender,
            'is_typing' => $this->isTyping,
        ];
    }
}
