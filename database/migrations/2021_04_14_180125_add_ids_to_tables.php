<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddIdsToTables extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('migrations', function (Blueprint $table) {
            // Add the id column to the migrations table if it doesn't yet have one
            if (! Schema::hasColumn('migrations', 'id')) {
                $table->increments('id');
            }
        });

        Schema::table('password_resets', function (Blueprint $table) {
            // The id column is now added in the original create migration
            // Keeping this empty for backwards compatibility
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('migrations', function (Blueprint $table) {
            if (Schema::hasColumn('migrations', 'id')) {
                $table->dropColumn('id');
            }
        });

        Schema::table('password_resets', function (Blueprint $table) {
            // The id column is managed in the original create migration
            // Keeping this empty for backwards compatibility
        });
    }

    private function notUsingSqlite()
    {
        return Schema::connection($this->getConnection())->getConnection()->getDriverName() !== 'sqlite';
    }
}
