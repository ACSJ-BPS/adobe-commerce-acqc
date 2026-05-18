<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppressions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('rule_id', 50);
            $table->text('reason');
            $table->string('suppressed_by_email', 255);
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('suppressed_at');
            $table->date('review_by')->nullable();
            $table->timestamp('dashboard_acked_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'rule_id']);
            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppressions');
    }
};
