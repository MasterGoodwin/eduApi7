<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUserAttemptsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('user_attempts', function (Blueprint $table) {
            $table->foreignId('user_id');
            $table->foreignId('lesson_id');
            $table->unsignedInteger('current_count')->default(0);
            $table->unsignedInteger('total_count')->default(0);
            $table->timestamp('updated_at');
            $table->timestamp('created_at');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('user_attempts');
    }
}
