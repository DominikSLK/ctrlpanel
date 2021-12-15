<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePaymentsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id');
            $table->foreignId('user_id')->references('id')->on('users');
            $table->string('payment_id')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('type')->nullable();;
            $table->string('status')->nullable();;
            $table->string('amount')->nullable();;
            $table->string('price')->nullable();;
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
        Schema::dropIfExists('payments');
    }
}
