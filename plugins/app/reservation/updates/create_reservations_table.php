<?php namespace App\Reservation\Updates;

use Schema;
use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;

class CreateReservationsTable extends Migration
{
    public function up()
    {
        Schema::create('app_reservation_reservations', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->increments('id');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('app_reservation_reservations');
    }
}
