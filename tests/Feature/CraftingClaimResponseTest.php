<?php

use App\Models\CraftingInventory;
use App\Models\CraftingQueue;
use App\Models\User;

beforeEach(function (): void {
    if (! defined('AMFPHP_ROOTPATH')) {
        define('AMFPHP_ROOTPATH', dirname(__DIR__, 2).'/public/farmville/flashservices/amfphp/');
    }

    require_once AMFPHP_ROOTPATH.'Helpers/constants.php';
    require_once AMFPHP_ROOTPATH.'Helpers/logger.php';
    require_once AMFPHP_ROOTPATH.'Helpers/general_functions.php';
    require_once AMFPHP_ROOTPATH.'Functions/CraftingService.php';
});

it('identifies each claimed Pub slot by its original server start timestamp', function (): void {
    $uid = User::factory()->create()->uid;
    $now = time();
    $firstStart = $now - 3600;
    $secondStart = $now - 3500;
    $futureStart = $now - 60;

    CraftingQueue::addRecipe($uid, 'o2kt', 'pub', 0, $firstStart, $now - 10);
    CraftingQueue::addRecipe($uid, 'o2kt', 'pub', 1, $secondStart, $now - 5);
    CraftingQueue::addRecipe($uid, 'o2kt', 'pub', 2, $futureStart, $now + 3600);

    $player = new class($uid) {
        public function __construct(private string $uid) {}

        public function getUid(): string
        {
            return $this->uid;
        }
    };
    $request = (object) ['params' => ['o2kt', 0]];

    $response = CraftingService::onClaimFinishedRecipes($player, $request, null);

    expect($response['data'])->toHaveCount(2);
    $this->assertEqualsCanonicalizing(
        [$firstStart, $secondStart],
        array_column($response['data'], 'start_ts')
    );
    foreach ($response['data'] as $claimed) {
        expect($claimed['recipeId'])->toBe('o2kt')
            ->and($claimed['start_ts'])->toBeInt()
            ->and($claimed['productItemCode'])->toBe('o2kt');
    }

    expect(CraftingQueue::where('uid', $uid)->pluck('start_ts')->all())->toBe([$futureStart])
        ->and(CraftingInventory::where('uid', $uid)
            ->where('item_code', 'o2kt:1')
            ->where('storage_type', 'crafted')
            ->value('quantity'))->toBe(2);
});
