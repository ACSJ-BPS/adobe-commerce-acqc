<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scans', function (Blueprint $table) {
            $table->unsignedSmallInteger('total_rules_evaluated')->default(0)->after('info_count');
            $table->unsignedSmallInteger('violated_rules_count')->default(0)->after('total_rules_evaluated');
        });
    }

    public function down(): void
    {
        Schema::table('scans', function (Blueprint $table) {
            $table->dropColumn(['total_rules_evaluated', 'violated_rules_count']);
        });
    }
};
