<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            // Which agent has claimed this ticket
            $table->foreignId('agent_id')
                  ->nullable()
                  ->after('user_id')
                  ->constrained('users')
                  ->nullOnDelete();

            // 'live' = real-time chat active, null = async ticket
            $table->string('chat_mode')->nullable()->after('priority');

            // When the agent joined the chat
            $table->timestamp('agent_joined_at')->nullable()->after('resolved_at');
        });
    }

    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropForeign(['agent_id']);
            $table->dropColumn(['agent_id', 'chat_mode', 'agent_joined_at']);
        });
    }
};
