<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('super_crop_unlocks', function (Blueprint $table): void {
            $table->id();
            $table->string('uid', 20);
            $table->string('crop_code', 100);
            $table->unsignedInteger('cash_paid')->default(0);
            $table->timestamps();
            $table->unique(['uid', 'crop_code']);
        });

        Schema::create('super_crop_certifications', function (Blueprint $table): void {
            $table->id();
            $table->string('owner_uid', 20);
            $table->string('crop_code', 100);
            $table->string('neighbor_uid', 20);
            $table->string('status', 16)->default('pending');
            $table->timestamps();
            $table->unique(['owner_uid', 'crop_code', 'neighbor_uid'], 'super_crop_certification_unique');
            $table->index(['neighbor_uid', 'status'], 'super_crop_neighbor_inbox_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('super_crop_certifications');
        Schema::dropIfExists('super_crop_unlocks');
    }
};
