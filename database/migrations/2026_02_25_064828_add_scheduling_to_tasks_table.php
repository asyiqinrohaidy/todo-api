<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dateTime('due_date')->nullable()->after('description');
            $table->dateTime('reminder_date')->nullable()->after('due_date');
            $table->enum('priority', ['low', 'medium', 'high'])->default('medium')->after('reminder_date');
            $table->integer('estimated_hours')->nullable()->after('priority');
        });
    }

    public function down()
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['due_date', 'reminder_date', 'priority', 'estimated_hours']);
        });
    }
};