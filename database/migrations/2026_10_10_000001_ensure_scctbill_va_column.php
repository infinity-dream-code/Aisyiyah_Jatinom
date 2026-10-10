<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = 'DATA_MYSQL';

        if (!Schema::connection($connection)->hasTable('scctbill')) {
            return;
        }

        if (
            Schema::connection($connection)->hasColumn('scctbill', 'va')
            || Schema::connection($connection)->hasColumn('scctbill', 'VA')
        ) {
            return;
        }

        Schema::connection($connection)->table('scctbill', function (Blueprint $table) {
            $table->string('va', 32)->nullable();
        });
    }

    public function down(): void
    {
        $connection = 'DATA_MYSQL';

        if (!Schema::connection($connection)->hasTable('scctbill')) {
            return;
        }

        if (Schema::connection($connection)->hasColumn('scctbill', 'va')) {
            Schema::connection($connection)->table('scctbill', function (Blueprint $table) {
                $table->dropColumn('va');
            });
        }
    }
};
