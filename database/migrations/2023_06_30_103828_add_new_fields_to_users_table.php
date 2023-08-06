<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('lname')->after('name');
            $table->string('country')->after('lname');
            $table->string('mobile')->after('country');
            $table->string('dob')->after('mobile');
            $table->string('gender')->after('dob');
            $table->string('address')->after('gender')->nullable();
        });
    }
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('lname');
            $table->dropColumn('country');
            $table->dropColumn('mobile');
            $table->dropColumn('dob');
            $table->dropColumn('gender');
            $table->dropColumn('address');
        });
    }
};
