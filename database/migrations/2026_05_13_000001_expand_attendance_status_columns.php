<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('
            ALTER TABLE attendances
            MODIFY status1 SMALLINT UNSIGNED NULL,
            MODIFY status2 SMALLINT UNSIGNED NULL,
            MODIFY status3 SMALLINT UNSIGNED NULL,
            MODIFY status4 SMALLINT UNSIGNED NULL,
            MODIFY status5 SMALLINT UNSIGNED NULL
        ');
    }

    public function down(): void
    {
        DB::statement('
            ALTER TABLE attendances
            MODIFY status1 TINYINT(1) NULL,
            MODIFY status2 TINYINT(1) NULL,
            MODIFY status3 TINYINT(1) NULL,
            MODIFY status4 TINYINT(1) NULL,
            MODIFY status5 TINYINT(1) NULL
        ');
    }
};
