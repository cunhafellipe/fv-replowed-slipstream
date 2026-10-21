<?php

use App\Models\Item;
use App\Models\UserMeta;
use App\Models\UserWorld;
use App\Models\WorldObject;
use App\Support\ConstructionMaterials;
use App\Support\StorageConfig;

beforeEach(function (): void {
    if (!defined('AMFPHP_ROOTPATH')) {
        define('AMFPHP_ROOTPATH', dirname(__DIR__, 2) . '/public/farmville/flashservices/amfphp/');
    }
    require_once AMFPHP_ROOTPATH . 'Helpers/general_functions.php';

    foreach (['brick' => 'K1', 'nail' => 'K4', 'woodenboard' => 'K5'] as $name => $code) {
        Item::query()->create([
            'name' => $name,
            'code' => $code,
            'data' => serialize(['name' => $name, 'code' => $code, 'cash' => 2]),
        ]);
    }
    Item::query()->create([
        'name' => 'turkeyroost_finished',
        'code' => 'TRF',
        'data' => serialize(['name' => 'turkeyroost_finished', 'className' => 'TurkeyRoostBuilding']),
    ]);
    Item::query()->create([
        'name' => 'turkeyroost',
        'code' => 'TRC',
        'data' => serialize([
            'name' => 'turkeyroost',
            'className' => 'ConstructionBuilding',
            'finishedName' => 'turkeyroost_finished',
            'matsNeeded' => 30,
            'defaultItem' => (object) ['name' => 'brick', 'amount' => '1'],
            'storageType' => (object) ['itemClass' => 'turkeyroost_constructable'],
            'smartSystemTuning' => 'smart_buildable',
        ]),
    ]);
    Item::clearCache();
    config()->set('experiments.fv_dynamic_part_requirements_refactor', 1);
    config()->set('experiments.fv_smartquest', 4);
    config()->set('experiments.fv_smartbuildable', 3);
});

it('matches the Flash smart-buildable 12-of-each Turkey Roost requirement', function (): void {
    expect(StorageConfig::constructionRequirements('turkeyroost_constructable'))
        ->toBe(['woodenboard' => 10, 'nail' => 10, 'brick' => 10]);

    $item = getItemByName('turkeyroost', 'db');
    $contents = [
        ['itemCode' => 'K1', 'numItem' => 12],
        ['itemCode' => 'K4', 'numItem' => 12],
        ['itemCode' => 'K5', 'numItem' => 12],
    ];

    expect(ConstructionMaterials::status($item, $contents))
        ->toMatchArray(['complete' => true, 'cashCost' => 0]);

    $contents[1]['numItem'] = 11;
    expect(ConstructionMaterials::status($item, $contents))
        ->toMatchArray(['complete' => false, 'cashCost' => 2,
            'remaining' => ['woodenboard' => 0, 'nail' => 1, 'brick' => 0]]);
});

it('uses the shared material status for the explicit transform action', function (): void {
    require_once AMFPHP_ROOTPATH . 'Helpers/user_resources.php';
    require_once AMFPHP_ROOTPATH . 'Functions/WorldService.php';

    $building = new WorldObject([
        'item_name' => 'turkeyroost',
        'class_name' => 'ConstructionBuilding',
        'contents' => [
            ['itemCode' => 'K1', 'numItem' => 12],
            ['itemCode' => 'K4', 'numItem' => 12],
            ['itemCode' => 'K5', 'numItem' => 12],
        ],
    ]);
    $completion = (new ReflectionMethod(WorldService::class, 'constructionCompletionData'))
        ->invoke(null, $building);

    expect($completion['cashCost'])->toBe(0)
        ->and($completion['finishedName'])->toBe('turkeyroost_finished');
});

it('repairs a full saved roost exactly once without accepting an incomplete one', function (): void {
    require_once AMFPHP_ROOTPATH . 'Helpers/player.php';

    $uid = '990067733';
    $world = UserWorld::query()->create([
        'uid' => $uid,
        'type' => 'farm',
        'sizeX' => 12,
        'sizeY' => 12,
        'objects' => '[]',
        'messageManager' => serialize(['messages' => [], 'allowSendEmails' => true]),
    ]);
    UserMeta::query()->create([
        'uid' => $uid,
        'firstName' => 'Test',
        'lastName' => 'Farmer',
        'gold' => 0,
        'cash' => 0,
        'xp' => 0,
    ]);
    $roost = WorldObject::query()->create([
        'world_id' => $world->id,
        'object_id' => 194,
        'class_name' => 'ConstructionBuilding',
        'item_name' => 'turkeyroost',
        'state' => 'construction',
        'position_x' => 1,
        'position_y' => 1,
        'position_z' => 0,
        'deleted' => false,
        'contents' => [
            ['itemCode' => 'K1', 'numItem' => 12],
            ['itemCode' => 'K4', 'numItem' => 11],
            ['itemCode' => 'K5', 'numItem' => 12],
        ],
    ]);
    $player = new Player($uid);

    expect($player->repairCompletedConstruction('farm', 194))->toBeFalse();
    $roost->update(['contents' => [
        ['itemCode' => 'K1', 'numItem' => 12],
        ['itemCode' => 'K4', 'numItem' => 12],
        ['itemCode' => 'K5', 'numItem' => 12],
    ]]);

    expect($player->repairCompletedConstruction('farm', 194))->toBeTrue()
        ->and($roost->fresh()->item_name)->toBe('turkeyroost_finished')
        ->and($roost->fresh()->class_name)->toBe('TurkeyRoostBuilding')
        ->and($roost->fresh()->state)->toBe('built')
        ->and($player->repairCompletedConstruction('farm', 194))->toBeFalse();
});
