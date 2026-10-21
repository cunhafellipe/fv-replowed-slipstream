<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_innovation_states', function (Blueprint $table): void {
            $table->id();
            $table->string('uid', 20);
            $table->string('feature', 50);
            $table->longText('state');
            $table->timestamps();
            $table->unique(['uid', 'feature']);
        });

        Schema::create('market_innovation_scores', function (Blueprint $table): void {
            $table->id();
            $table->string('uid', 20);
            $table->string('feature', 50);
            $table->date('week_start');
            $table->unsignedBigInteger('score')->default(0);
            $table->timestamp('first_earned_at');
            $table->timestamps();
            $table->unique(['uid', 'feature', 'week_start'], 'market_score_player_week_unique');
            $table->index(['feature', 'week_start', 'score'], 'market_score_ranking_index');
        });

        Schema::create('market_innovation_sales', function (Blueprint $table): void {
            $table->id();
            $table->string('uid', 20);
            $table->string('feature', 50);
            $table->string('sale_key', 100);
            $table->date('week_start');
            $table->unsignedBigInteger('coins');
            $table->timestamps();
            $table->unique(['uid', 'feature', 'sale_key'], 'market_sale_idempotency_unique');
        });

        Schema::create('market_innovation_awards', function (Blueprint $table): void {
            $table->id();
            $table->string('uid', 20);
            $table->string('feature', 50);
            $table->date('week_start');
            $table->unsignedInteger('rank');
            $table->string('gift_name', 100);
            $table->timestamps();
            $table->unique(['uid', 'feature', 'week_start'], 'market_award_player_week_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_innovation_awards');
        Schema::dropIfExists('market_innovation_sales');
        Schema::dropIfExists('market_innovation_scores');
        Schema::dropIfExists('market_innovation_states');
    }
};
