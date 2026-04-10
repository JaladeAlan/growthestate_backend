<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up(): void
    {
        DB::statement("
            ALTER TABLE support_messages
            DROP CONSTRAINT support_messages_sender_type_check
        ");

        DB::statement("
            ALTER TABLE support_messages
            ADD CONSTRAINT support_messages_sender_type_check
            CHECK (sender_type IN ('user', 'admin', 'guest', 'agent'))
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE support_messages
            DROP CONSTRAINT support_messages_sender_type_check
        ");

        DB::statement("
            ALTER TABLE support_messages
            ADD CONSTRAINT support_messages_sender_type_check
            CHECK (sender_type IN ('user', 'admin', 'guest'))
        ");
    }
};
