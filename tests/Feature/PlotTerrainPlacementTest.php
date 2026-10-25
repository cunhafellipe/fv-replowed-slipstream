<?php

use App\Models\PlayerMeta;
use App\Models\UserMeta;
use App\Models\UserWorld;
use App\Models\WorldObject;
use App\Support\WorldCurrencyService;

beforeEach(function (): void {
    if (!defined('AMFPHP_ROOTPATH')) {
        define('AMFPHP_ROOTPATH', dirname(__DIR__, 2).'/public/farmville/flashservices/amfphp/');
    }
    foreach (['constants', 'logger', 'general_functions', 'user_resources', 'market_transactions', 'player', 'quest_progress', 'crafting_helper'] as $helper) {
        require_once AMFPHP_ROOTPATH.'Helpers/'.$helper.'.php';
    }
    require_once AMFPHP_ROOTPATH.'Functions/WorldService.php';
    require_once AMFPHP_ROOTPATH.'Functions/EquipmentWorldService.php';

    PlayerMeta::clearCache();
    $this->uid = '900091';
    $this->world = UserWorld::query()->create([
        'uid' => $this->uid, 'type' => 'asia', 'sizeX' => 170, 'sizeY' => 170,
        'objects' => '[]',
        'messageManager' => serialize(['messages' => [], 'allowSendEmails' => true]),
    ]);
    UserMeta::query()->create([
        'uid' => $this->uid, 'firstName' => 'Terrain', 'lastName' => 'Test',
        'gold' => 1000, 'xp' => 0, 'cash' => 0, 'energy' => 10, 'energyMax' => 10,
    ]);
    PlayerMeta::setValue($this->uid, 'currentWorldType', 'asia');
    WorldCurrencyService::initializeForWorld($this->uid, 'asia');
    invalidateWorldCache($this->uid, 'asia');
});

function terrainTestPlot($x, $y, int $id = 63001): object
{
    return (object) [
        'id' => $id, 'className' => 'Plot', 'state' => PLOT_STATE_PLOWED,
        'position' => (object) ['x' => $x, 'y' => $y, 'z' => 0],
        // A client cannot override the authoritative mask/footprint.
        'terrain' => [4], 'sizeX' => 1, 'sizeY' => 1,
    ];
}

it('rejects a new manual plot on blocked terrain without changing resources', function ($x, $y): void {
    $request = (object) ['params' => [ACTION_PLOW, terrainTestPlot($x, $y), [(object) ['energyCost' => 1]]]];
    $result = WorldService::performAction(new Player($this->uid), $request, new MarketTransactions($this->uid));

    expect($result['errorType'] ?? 0)->toBe(1)
        ->and($result['data']['success'])->toBeFalse();
    $this->assertDatabaseMissing('world_objects', ['world_id' => $this->world->id]);
    $this->assertDatabaseHas('usermeta', ['uid' => $this->uid, 'gold' => 1000, 'xp' => 0, 'energy' => 10]);
    expect(WorldCurrencyService::balancesForClient($this->uid)['jade']['total'])->toBe(6000);
})->with([
    'entirely shoreline (scene 19,-25)' => [158, 70],
    'mixed terrace and shoreline' => [136, 40],
    'western Jade Falls cliff (scene -35,20)' => [50, 160],
    'lower Jade Falls cliff (scene -35,10)' => [50, 140],
    'southwest ridge upper (scene -29,6)' => [62, 132],
    'southwest ridge middle (scene -35,0)' => [50, 120],
    'southwest ridge lower (scene -49,-14)' => [22, 92],
    'negative position' => [-1, 40],
    'footprint beyond farm edge' => [168, 40],
    'fractional position must not be truncated' => [136.5, 44],
    'missing position' => [null, 44],
]);

it('rejects a whole vehicle sweep before writing or charging any plot', function (bool $invalidFirst, int $x, int $y): void {
    $plots = [terrainTestPlot(136, 44), terrainTestPlot($x, $y, 63002)];
    if ($invalidFirst) {
        $plots = array_reverse($plots);
    }
    $request = (object) ['params' => [ACTION_PLOW, (object) [], $plots, PLOT_STATE_PLOWED]];
    $result = EquipmentWorldService::onUseEquipment(new Player($this->uid), $request, null);

    expect($result['errorType'] ?? 0)->toBe(1)->and($result['data'])->toBe([null, null]);
    $this->assertDatabaseMissing('world_objects', ['world_id' => $this->world->id]);
    $this->assertDatabaseHas('usermeta', ['uid' => $this->uid, 'gold' => 1000, 'xp' => 0, 'energy' => 10]);
    expect(WorldCurrencyService::balancesForClient($this->uid)['jade']['total'])->toBe(6000);
})->with([
    'shoreline first' => [true, 158, 70],
    'shoreline last' => [false, 158, 70],
    'mixed footprint first' => [true, 136, 40],
    'mixed footprint last' => [false, 136, 40],
    'western cliff first' => [true, 50, 160],
    'western cliff last' => [false, 50, 160],
    'lower cliff first' => [true, 50, 140],
    'lower cliff last' => [false, 50, 140],
    'southwest ridge first' => [true, 50, 120],
    'southwest ridge last' => [false, 50, 120],
]);

it('accepts a new plot wholly on terrace for either plowing path', function (bool $vehicle): void {
    $plot = terrainTestPlot(136, 44);
    $player = new Player($this->uid);
    if ($vehicle) {
        $result = EquipmentWorldService::onUseEquipment($player, (object) ['params' => [ACTION_PLOW, (object) [], [$plot], PLOT_STATE_PLOWED]], null);
    } else {
        $result = WorldService::performAction($player, (object) ['params' => [ACTION_PLOW, $plot, []]], new MarketTransactions($this->uid));
    }
    expect($result['errorType'] ?? 0)->toBe(0);
    $this->assertDatabaseHas('world_objects', [
        'world_id' => $this->world->id, 'position_x' => 136, 'position_y' => 44,
        'state' => PLOT_STATE_PLOWED, 'deleted' => false,
    ]);
    $this->assertDatabaseHas('usermeta', ['uid' => $this->uid, 'gold' => 1000, 'xp' => 1]);
    expect(WorldCurrencyService::balancesForClient($this->uid)['jade']['total'])->toBe(5985);
})->with([true, false]);

it('preserves existing shoreline plots and idempotent replows', function (bool $vehicle): void {
    WorldObject::query()->create([
        'world_id' => $this->world->id, 'object_id' => 1, 'class_name' => 'Plot',
        'position_x' => 158, 'position_y' => 70, 'position_z' => 0,
        'state' => PLOT_STATE_FALLOW, 'deleted' => false,
    ]);
    invalidateWorldCache($this->uid, 'asia');
    $plot = terrainTestPlot(158, 70, 1);
    for ($i = 0; $i < 2; $i++) {
        $player = new Player($this->uid);
        if ($vehicle) {
            $result = EquipmentWorldService::onUseEquipment($player, (object) ['params' => [ACTION_PLOW, (object) [], [$plot], PLOT_STATE_PLOWED]], null);
        } else {
            $result = WorldService::performAction($player, (object) ['params' => [ACTION_PLOW, $plot, []]], new MarketTransactions($this->uid));
        }
        expect($result['errorType'] ?? 0)->toBe(0);
        invalidateWorldCache($this->uid, 'asia');
    }
    $this->assertDatabaseHas('world_objects', [
        'world_id' => $this->world->id, 'object_id' => 1, 'state' => PLOT_STATE_PLOWED, 'deleted' => false,
    ]);
    $this->assertDatabaseHas('usermeta', ['uid' => $this->uid, 'gold' => 1000, 'xp' => 1]);
    expect(WorldCurrencyService::balancesForClient($this->uid)['jade']['total'])->toBe(5985);
})->with([true, false]);

it('checks every terrain cell in the full footprint including odd origins', function (): void {
    $world = ['sizeX' => 12, 'sizeY' => 12, 'terrain' => array_fill(0, 36, 4)];
    // Starting at (1,1), the 4x4 footprint reaches tile (2,2), not just
    // the 2x2 set starting at tile (0,0).
    $world['terrain'][2 * 6 + 2] = 3;
    expect(getNewPlotTerrainError($world, 1, 1))->toBe('non_plowable_terrain')
        ->and(getNewPlotTerrainError($world, 0, 0))->toBeNull();
});

it('allows supported terrains but rejects shoreline dead and unknown cells', function (int $code, bool $allowed): void {
    $world = ['sizeX' => 12, 'sizeY' => 12, 'terrain' => array_fill(0, 36, $code)];
    expect(getNewPlotTerrainError($world, 4, 4) === null)->toBe($allowed);
})->with([
    [1, true], [2, true], [4, true], [5, true], [6, true], [7, true],
    [8, true], [9, true], [10, true], [12, false],
    [3, false], [254, false], [0, false], [99, false],
]);

it('preserves ordinary farms without terrain and rejects incomplete mapped footprints', function (): void {
    expect(getNewPlotTerrainError(['sizeX' => 12, 'sizeY' => 12], 4, 8))->toBeNull()
        ->and(getNewPlotTerrainError(['sizeX' => 12, 'sizeY' => 12, 'terrain' => [4]], 0, 0))->toBe('non_plowable_terrain');
});
