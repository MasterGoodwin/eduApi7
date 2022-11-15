<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class UserStat extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('user_stat', function (Blueprint $table) {
            $table->foreignId('user_id');
            $table->foreignId('lesson_id');
            $table->decimal('score', 5, 2);
            $table->integer('right')->default(0);
            $table->integer('total')->default(0);
            $table->unsignedTinyInteger('complete')->default(0)->nullable();
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
        //
    }
}
