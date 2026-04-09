<?php

use Illuminate\Support\Facades\Broadcast;

// ── User's private ticket channel ────────────────────────────────────────────
// Only the ticket owner can subscribe
Broadcast::channel('support.ticket.{ticketId}', function ($user, $ticketId) {
    return \App\Models\SupportTicket::where('id', $ticketId)
        ->where('user_id', $user->id)
        ->exists();
});

// ── Agent presence channel ────────────────────────────────────────────────────
// Only admins/agents can join — returns user info for presence awareness
Broadcast::channel('agent.chat.{ticketId}', function ($user, $ticketId) {
    if (! $user->hasRole('admin')) {
        return false;
    }

    return [
        'id'   => $user->id,
        'name' => $user->name,
        'role' => 'agent',
    ];
});

// ── Global agents channel ─────────────────────────────────────────────────────
// All agents listen here for new queue events
Broadcast::channel('agents', function ($user) {
    return $user->hasRole('admin');
});
