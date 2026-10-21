<?php

use App\Support\WorldScoreConfig;

it('retains the original Harvest Valley expansion metadata', function (): void {
    $settings = json_decode(
        file_get_contents(public_path('props/gameSettings.json')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    $expansions = $settings['settings']['expansions']['expansion'];
    $farmfest = collect($expansions)->firstWhere('@name', 'farmfest');

    expect($farmfest)->not->toBeNull()
        ->and($farmfest['@prefix'])->toBe('xfs')
        ->and($farmfest['@score'])->toBe('farmfestPoints')
        ->and($farmfest['startingTileSet'])->toBe('farmfest_theme')
        ->and($farmfest['startingFarm'])->toBe('/config/npc_farms/farmfestStart.farm')
        ->and(WorldScoreConfig::scoreUnitForWorld('farmfest'))->toBe('farmfestPoints');
});

it('retains original Farmfest crops and score levels in the effective AMF archives', function (): void {
    $items = gzuncompress(file_get_contents(public_path('farmville/xml/gz/v855038/items_opt.amf')));
    $scores = gzuncompress(file_get_contents(public_path('farmville/xml/gz/v855038/worldScore.amf.gz')));

    expect(is_string($items))->toBeTrue()
        ->and(str_contains($items, '2gfn'))->toBeTrue()
        ->and(str_contains($items, '2ha7'))->toBeTrue()
        ->and(str_contains($items, '2hb4'))->toBeTrue()
        ->and(is_string($scores))->toBeTrue()
        ->and(str_contains($scores, 'farmfestPoints'))->toBeTrue();
});
