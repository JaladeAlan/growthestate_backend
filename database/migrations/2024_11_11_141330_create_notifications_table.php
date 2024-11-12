<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateNotificationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // Link to users table
            $table->string('type');             // Type of notification (e.g., 'deposit', 'purchase')
            $table->string('title');            // Title of the notification
            $table->text('message');            // Main content of the notification
            $table->boolean('is_read')->default(false); // Read status
            $table->timestamp('created_at')->useCurrent(); // Timestamp for when it was created
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('notifications');
    }
}
