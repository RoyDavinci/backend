<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('replies', function (Blueprint $table) {
            $table->id();
            // $table->foreignId('dispute_id')->constrained()->onDelete('cascade'); // Foreign key to disputes table
            // $table->foreignId('user_id')->constrained()->onDelete('set null');
            $table->foreignId('dispute_id')->constrained()->onDelete('cascade'); // Foreign key to disputes table
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null'); // Foreign key to users ta
            $table->string('email'); // Store the email of the user
            $table->string('group'); // Store the group of the user
            $table->text('reply'); // The actual reply content
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('replies');
    }
};
