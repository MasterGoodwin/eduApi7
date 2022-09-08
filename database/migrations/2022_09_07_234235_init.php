<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class Init extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Роли
        Schema::create('user_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->foreignId('role_id');
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });

        Schema::create('group_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->foreignId('group_id');
        });

        Schema::create('groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });

        \Illuminate\Support\Facades\DB::table('groups')->insert([
            'name' => 'Сотрудники МСМ',
        ]);


        // Уроки
        Schema::create('lessons', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->longText('content')->nullable();
            $table->text('comments')->nullable();
            $table->unsignedInteger('type')->default(1);
            $table->timestamp('start')->nullable();
            $table->timestamp('end')->nullable();
            $table->timestamps();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });

        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('category_id')->nullable();
            $table->timestamps();
        });

        Schema::create('course_lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id');
            $table->foreignId('lesson_id');
            $table->unsignedInteger('order')->nullable();
        });

        Schema::create('course_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id');
            $table->foreignId('group_id');
        });

        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id');
            $table->unsignedInteger('type')->default(1)->comment('1 - один вариант, 2 - несколько вариантов, 3 - свободная форма ответа');
            $table->unsignedInteger('order')->nullable();
            $table->text('question');
            $table->timestamps();
        });

        Schema::create('answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id');
            $table->unsignedInteger('order')->nullable();
            $table->text('answer');
            $table->text('comment')->nullable();
            $table->boolean('right')->default(0);
        });

        Schema::create('user_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->foreignId('question_id');
            $table->foreignId('answer_id')->nullable();
            $table->text('answer')->nullable();
            $table->boolean('right')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedTinyInteger('status')->after('id')->default(0);
            $table->foreignId('cityId')->after('id')->nullable();
        });

        Schema::table('courses', function (Blueprint $table) {
            $table->unsignedInteger('type')->default(1);
        });
        Schema::create('lesson_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id');
            $table->foreignId('group_id');
        });
        Schema::create('docs', function (Blueprint $table) {
            $table->id();
            $table->integer('lesson_id');
            $table->string('name');
            $table->string('src_name');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->string('cid')->after('id')->nullable();
        });
        Schema::table('lessons', function (Blueprint $table) {
            $table->unsignedInteger('result_type')->default(1);
        });

        \Illuminate\Support\Facades\DB::table('roles')->insert(['id' => 1, 'name' => 'Ученик',]);
        \Illuminate\Support\Facades\DB::table('roles')->insert(['id' => 2, 'name' => 'Преподаватель',]);
        \Illuminate\Support\Facades\DB::table('roles')->insert(['id' => 3, 'name' => 'Куратор',]);
        \Illuminate\Support\Facades\DB::table('roles')->insert(['id' => 9, 'name' => 'Администратор',]);


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
