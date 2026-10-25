<?php

use App\Models\User;
use App\Models\Item;
use App\Models\CraftingInventory;
use App\Support\MarketCompetition;
use App\Support\MarketInnovationState;
use App\Support\MarketInnovationTasks;
use App\Support\MarketRewards;
use App\Support\MarketSeason;
use App\Support\QuestCategoryResolver;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    config()->set('market_innovations.enabled', true);
    Item::clearCache();
    if (! defined('AMFPHP_ROOTPATH')) {
        define('AMFPHP_ROOTPATH', dirname(__DIR__, 2).'/public/farmville/flashservices/amfphp/');
    }

    require_once AMFPHP_ROOTPATH.'Functions/FarmersMarketInnovationService.php';
    require_once AMFPHP_ROOTPATH.'Functions/TropicalMarketInnovationService.php';
});

afterEach(function (): void {
    config()->set('market_innovations.enabled', false);
    config()->set('market_innovations.farmers_enabled', true);
    config()->set('market_innovations.tropical_enabled', true);
});

it('can disable Tropical Market independently while Farmers Market remains active', function (): void {
    config()->set('market_innovations.farmers_enabled', true);
    config()->set('market_innovations.tropical_enabled', false);

    expect(MarketCompetition::isEnabled(MarketCompetition::FARMERS))->toBeTrue()
        ->and(MarketCompetition::isEnabled(MarketCompetition::TROPICAL))->toBeFalse()
        ->and(MarketCompetition::enabledFeatures())->toBe([MarketCompetition::FARMERS]);

    $user = User::factory()->create();
    $player = new class($user->uid) {
        public function __construct(private string $uid) {}
        public function getUid(): string { return $this->uid; }
    };
    $farmersRequest = (object) ['params' => [MarketCompetition::FARMERS]];
    $tropicalRequest = (object) ['params' => [MarketCompetition::TROPICAL]];

    expect(FarmersMarketInnovationService::startQuest($player, $farmersRequest)['data']['featureData'])
        ->toBeArray()
        ->and(fn () => TropicalMarketInnovationService::startQuest($player, $tropicalRequest))
        ->toThrow(InvalidArgumentException::class);
});

it('records only server-confirmed market sales and ranks deterministically', function (): void {
    $first = User::factory()->create();
    $second = User::factory()->create();
    $monday = new DateTimeImmutable('2026-10-05 10:00:00', new DateTimeZone('UTC'));

    expect(MarketCompetition::recordSale($first->uid, MarketCompetition::FARMERS, 120, 'sale-1', $monday))->toBe(120)
        ->and(MarketCompetition::recordSale($second->uid, MarketCompetition::FARMERS, 120, 'sale-1', $monday->modify('+1 hour')))->toBe(120)
        ->and(MarketCompetition::recordSale($first->uid, MarketCompetition::FARMERS, 30, 'sale-2', $monday->modify('+2 hours')))->toBe(150)
        ->and(MarketCompetition::recordSale($first->uid, MarketCompetition::FARMERS, 30, 'sale-2', $monday->modify('+2 hours')))->toBe(150);

    $standing = MarketCompetition::standings($first->uid, MarketCompetition::FARMERS, $monday);
    expect($standing['userData']['rank'])->toBe(1)
        ->and($standing['userData']['value'])->toBe(150)
        ->and(array_column($standing['slotData'], 'zid'))->toBe([$first->uid, $second->uid]);

    expect(MarketCompetition::standings($first->uid, MarketCompetition::TROPICAL, $monday)['slotData'])->toBe([]);
});

it('starts a new market week on Monday UTC without carrying over scores', function (): void {
    $user = User::factory()->create();
    $sunday = new DateTimeImmutable('2026-10-11 23:59:59', new DateTimeZone('UTC'));
    $monday = $sunday->modify('+1 second');

    MarketCompetition::recordSale($user->uid, MarketCompetition::FARMERS, 75, 'sale-before-rollover', $sunday);
    expect(MarketCompetition::weekStart($sunday))->toBe('2026-10-05')
        ->and(MarketCompetition::weekStart($monday))->toBe('2026-10-12')
        ->and(MarketCompetition::standings($user->uid, MarketCompetition::FARMERS, $monday)['userData']['value'])->toBe(0);
});

it('rejects invalid market scores before touching the ledger', function (): void {
    $user = User::factory()->create();
    MarketCompetition::recordSale($user->uid, MarketCompetition::FARMERS, 1, 'valid-sale');

    expect(fn () => MarketCompetition::recordSale($user->uid, MarketCompetition::FARMERS, -1, 'bad-sale'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => MarketCompetition::recordSale($user->uid, 'UnknownMarket', 10, 'unknown-feature'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => MarketCompetition::recordSale($user->uid, MarketCompetition::FARMERS, 2, 'valid-sale'))
        ->toThrow(InvalidArgumentException::class);
});

it('returns the leaderboard envelope expected by the Flash market window', function (): void {
    $user = User::factory()->create();
    MarketCompetition::recordSale($user->uid, MarketCompetition::FARMERS, 250, 'accepted-sale');
    $player = new class($user->uid) {
        public function __construct(private string $uid) {}
        public function getUid(): string { return $this->uid; }
    };

    $response = FarmersMarketInnovationService::getLeaderboardEntries(
        $player,
        (object) ['params' => [MarketCompetition::FARMERS, false]],
    );

    expect($response['data']['zid'])->toBe($user->uid)
        ->and($response['data']['featureData']['saved_active_tasks'])->not->toBeEmpty()
        ->and($response['data']['userData']['value'])->toBe(250)
        ->and($response['data']['globalLeaderboardData']['slotData'][0]['rank'])->toBe(1)
        ->and($response['data']['globalLeaderboardData']['slotData'][0]['zid'])->toBe($user->uid);
});

it('keeps unavailable Tropical Escape rewards and tile actions disabled', function (): void {
    $user = User::factory()->create();
    $player = new class($user->uid) {
        public function __construct(private string $uid) {}
        public function getUid(): string { return $this->uid; }
    };
    $request = (object) ['params' => [MarketCompetition::TROPICAL]];

    expect(TropicalMarketInnovationService::initializeTropicEscapeConnection($player, $request)['data']['response']['data']['started'])->toBeFalse()
        ->and(TropicalMarketInnovationService::updateEscapeTokensCount($player, $request)['data']['success'])->toBeFalse()
        ->and(TropicalMarketInnovationService::updateEscapeTokensProgress($player, $request)['data']['tileUnlocked'])->toBeFalse()
        ->and(TropicalMarketInnovationService::resetEscapeTokensProgress($player, $request)['data']['success'])->toBeFalse()
        ->and(TropicalMarketInnovationService::unlockCurrentTile($player, $request)['data']['success'])->toBeFalse()
        ->and(TropicalMarketInnovationService::activateOrderboardBoost($player, $request)['data']['success'])->toBeFalse()
        ->and(TropicalMarketInnovationService::getEnvironment($player, $request)['data']['environment'])->toBe('zprod')
        ->and(TropicalMarketInnovationService::postNeighbours($player, $request)['data']['response'])->toContain('"result":false');
});

it('persists a server-observed task, redeem, and exactly-once produce sale', function (): void {
    $user = User::factory()->create();
    $task = MarketInnovationTasks::harvestTasks(MarketCompetition::FARMERS)[0];
    $started = MarketInnovationState::start($user->uid, MarketCompetition::FARMERS);

    expect($started['saved_active_tasks'][0]['id'])->toBe($task['id'])
        ->and($started['orderboard_task_points'])->toBe(0);

    expect(fn () => MarketInnovationState::redeem($user->uid, MarketCompetition::FARMERS, 0))
        ->toThrow(InvalidArgumentException::class);

    MarketInnovationState::recordHarvest($user->uid, $task['code'], $task['total'] - 1);
    MarketInnovationState::recordHarvest($user->uid, $task['code'], 2);
    $ready = MarketInnovationState::load($user->uid, MarketCompetition::FARMERS);
    expect($ready['saved_active_tasks'][0]['orderboard_progress'])->toBe($task['total']);

    $redeemed = MarketInnovationState::redeem($user->uid, MarketCompetition::FARMERS, 0);
    expect($redeemed['orderboard_task_points'])->toBe($task['points'])
        ->and(isset($redeemed['saved_active_tasks'][0]['id']))->toBeFalse()
        ->and($redeemed['saved_active_tasks'][0]['orderboard_progress'])->toBe(0);

    $result = MarketInnovationState::sell($user->uid, MarketCompetition::FARMERS, $task['points'], 'task-sale-1');
    $retry = MarketInnovationState::sell($user->uid, MarketCompetition::FARMERS, $task['points'], 'task-sale-1');
    expect($result['newQty'])->toBe(0)
        ->and($result['gold_earned'])->toBe($task['points'] * 100)
        ->and($retry['gold_earned'])->toBe($result['gold_earned'])
        ->and(MarketInnovationState::load($user->uid, MarketCompetition::FARMERS)['total_gold_coins'])
        ->toBe($result['gold_earned'])
        ->and(MarketCompetition::standings($user->uid, MarketCompetition::FARMERS)['userData']['value'])
        ->toBe($result['gold_earned']);

    $replacement = MarketInnovationState::newTask($user->uid, MarketCompetition::FARMERS, 0);
    expect($replacement['saved_active_tasks'][0]['id'])->not->toBe($task['id']);
});

it('normalizes legacy redeemed market slots without awarding produce twice', function (): void {
    $user = User::factory()->create();
    $feature = MarketCompetition::FARMERS;
    $state = MarketInnovationState::start($user->uid, $feature);
    $state['saved_active_tasks'][0] = ['orderboard_progress' => 40];
    $state['saved_active_tasks'][1] = ['orderboard_progress' => 5];
    $state['orderboard_task_points'] = 8;
    DB::table('market_innovation_states')->where('uid', $user->uid)->where('feature', $feature)
        ->update(['state' => json_encode($state, JSON_THROW_ON_ERROR)]);

    $loaded = MarketInnovationState::load($user->uid, $feature);
    expect($loaded['saved_active_tasks'][0]['orderboard_progress'])->toBe(0)
        ->and($loaded['saved_active_tasks'][1]['orderboard_progress'])->toBe(0)
        ->and($loaded['orderboard_task_points'])->toBe(8);

    expect(fn () => MarketInnovationState::redeem($user->uid, $feature, 0))
        ->toThrow(InvalidArgumentException::class);

    $replacement = MarketInnovationState::newTask($user->uid, $feature, 0);
    expect($replacement['saved_active_tasks'][0]['id'])->not->toBeNull()
        ->and($replacement['saved_active_tasks'][0]['orderboard_progress'])->toBe(0)
        ->and($replacement['orderboard_task_points'])->toBe(8);
});

it('assigns only reachable Farmers Market tasks and repairs old Harvest Valley slots', function (): void {
    $user = User::factory()->create();
    $feature = MarketCompetition::FARMERS;
    $assignable = MarketInnovationTasks::assignableTasks($feature);

    expect(count($assignable))->toBe(16);
    foreach ($assignable as $task) {
        expect($task['action'])->toBe('harvestByCode')
            ->and(in_array($task['code'], ['WH', 'SY', 'SB', 'PU', 'RC'], true))->toBeTrue();
    }

    $started = MarketInnovationState::start($user->uid, $feature);
    expect(array_column($started['saved_active_tasks'], 'id'))->toBe(array_column(array_slice($assignable, 0, 3), 'id'));

    // Simulate a board assigned by the old Harvest Valley-only catalog.
    $started['task_catalog_revision'] = 0;
    $started['saved_active_tasks'] = [
        ['id' => '1', 'orderboard_progress' => 12],
        ['id' => '22', 'orderboard_progress' => 1],
        ['id' => '3', 'orderboard_progress' => 9],
    ];
    $started['orderboard_task_points'] = 8;
    $started['total_gold_coins'] = 500;
    $started['current_storage_capacity_level'] = 2;
    DB::table('market_innovation_states')->where('uid', $user->uid)->where('feature', $feature)
        ->update(['state' => json_encode($started, JSON_THROW_ON_ERROR)]);

    $repaired = MarketInnovationState::startNewDay($user->uid, $feature);
    expect(array_column($repaired['saved_active_tasks'], 'id'))->toBe(array_column(array_slice($assignable, 0, 3), 'id'))
        ->and(array_column($repaired['saved_active_tasks'], 'orderboard_progress'))->toBe([0, 0, 0])
        ->and($repaired['task_catalog_revision'])->toBe(1)
        ->and($repaired['orderboard_task_points'])->toBe(8)
        ->and($repaired['total_gold_coins'])->toBe(500)
        ->and($repaired['current_storage_capacity_level'])->toBe(2);

    $repaired['saved_active_tasks'][0] = ['orderboard_progress' => 0];
    DB::table('market_innovation_states')->where('uid', $user->uid)->where('feature', $feature)
        ->update(['state' => json_encode($repaired, JSON_THROW_ON_ERROR)]);
    $next = MarketInnovationState::newTask($user->uid, $feature, 0);
    expect($next['saved_active_tasks'][0]['id'])->toBe($assignable[3]['id']);
});

it('converts leftover produce once when a new market week starts', function (): void {
    $user = User::factory()->create();
    $feature = MarketCompetition::FARMERS;
    MarketInnovationState::start($user->uid, $feature);
    $state = MarketInnovationState::load($user->uid, $feature);
    $state['score_week_start'] = (new DateTimeImmutable(MarketCompetition::weekStart()))->modify('-7 days')->format('Y-m-d');
    $state['orderboard_task_points'] = 25;
    DB::table('market_innovation_states')->where('uid', $user->uid)->where('feature', $feature)
        ->update(['state' => json_encode($state, JSON_THROW_ON_ERROR)]);

    $once = MarketInnovationState::startNewDay($user->uid, $feature);
    $twice = MarketInnovationState::startNewDay($user->uid, $feature);
    expect($once['orderboard_task_points'])->toBe(0)
        ->and($once['total_gold_coins'])->toBe(5000)
        ->and($twice['total_gold_coins'])->toBe(5000)
        ->and(MarketCompetition::standings($user->uid, $feature)['userData']['value'])->toBe(5000)
        ->and(DB::table('market_innovation_sales')->where('uid', $user->uid)->count())->toBe(1);
});

it('grants an archived leaderboard package to the giftbox exactly once', function (): void {
    require_once AMFPHP_ROOTPATH.'Helpers/general_functions.php';

    $user = User::factory()->create();
    $lastWeek = (new DateTimeImmutable(MarketCompetition::weekStart()))->modify('-7 days');
    $season = MarketSeason::seasonStart(MarketCompetition::FARMERS, $lastWeek);
    $number = intdiv((int) $season->diff($lastWeek)->format('%a'), 7) + 1;
    $packageName = "pkg_farmersMarket2020InnovationWeek{$number}_rank1";
    Item::create([
        'name' => $packageName,
        'code' => 'prize-package',
        'data' => serialize([
            'code' => 'prize-package',
            'itemPackage' => [(object) ['value' => 'market-prize-child', 'amount' => '2']],
        ]),
    ]);
    Item::create([
        'name' => 'market-prize-child',
        'code' => 'prize-child',
        'data' => serialize(['code' => 'prize-child']),
    ]);
    MarketCompetition::recordSale($user->uid, MarketCompetition::FARMERS, 100, 'previous-week-sale', $lastWeek);

    $first = MarketRewards::claimPreviousWeek($user->uid, MarketCompetition::FARMERS);
    $second = MarketRewards::claimPreviousWeek($user->uid, MarketCompetition::FARMERS);
    expect($first['gift'])->toBe([$packageName])
        ->and($second)->toBeNull()
        ->and(getGiftBox($user->uid)['prize-child'][0])->toBe(2)
        ->and(DB::table('market_innovation_awards')->where('uid', $user->uid)->count())->toBe(1);
});

it('claims every unclaimed completed leaderboard week in chronological order', function (): void {
    require_once AMFPHP_ROOTPATH.'Helpers/general_functions.php';

    $user = User::factory()->create();
    $feature = MarketCompetition::FARMERS;
    $currentWeek = new DateTimeImmutable('2026-10-26 12:00:00', new DateTimeZone('UTC'));
    $oldWeeks = [$currentWeek->modify('-14 days'), $currentWeek->modify('-7 days')];
    $expectedPackages = [];

    foreach ($oldWeeks as $index => $week) {
        $season = MarketSeason::seasonStart($feature, $week);
        $weekNumber = intdiv((int) $season->diff($week)->format('%a'), 7) + 1;
        $packageName = "pkg_farmersMarket2020InnovationWeek{$weekNumber}_rank1";
        $childName = 'market-prize-child-'.$index;
        $childCode = 'market-prize-child-code-'.$index;
        Item::create([
            'name' => $packageName,
            'code' => 'market-prize-package-'.$index,
            'data' => serialize([
                'code' => 'market-prize-package-'.$index,
                'itemPackage' => [(object) ['value' => $childName, 'amount' => '1']],
            ]),
        ]);
        Item::create([
            'name' => $childName,
            'code' => $childCode,
            'data' => serialize(['code' => $childCode]),
        ]);
        MarketCompetition::recordSale($user->uid, $feature, 100 + $index, 'completed-week-sale-'.$index, $week);
        $expectedPackages[] = $packageName;
    }

    $claimed = MarketRewards::claimCompletedWeeks($user->uid, $feature, $currentWeek);
    expect($claimed['gift'])->toBe($expectedPackages)
        ->and(MarketRewards::claimCompletedWeeks($user->uid, $feature, $currentWeek))->toBeNull()
        ->and(getGiftBox($user->uid)['market-prize-child-code-0'][0])->toBe(1)
        ->and(getGiftBox($user->uid)['market-prize-child-code-1'][0])->toBe(1)
        ->and(DB::table('market_innovation_awards')->where('uid', $user->uid)->count())->toBe(2);
});

it('requires the daily sales target and persists one daily gift', function (): void {
    require_once AMFPHP_ROOTPATH.'Helpers/general_functions.php';

    $user = User::factory()->create();
    $feature = MarketCompetition::TROPICAL;
    $rewardName = MarketRewards::dailyRewardName($feature);
    expect($rewardName)->not->toBeNull();
    Item::create([
        'name' => $rewardName,
        'code' => 'daily-package',
        'data' => serialize([
            'code' => 'daily-package',
            'itemPackage' => [(object) ['value' => 'daily-prize-child', 'amount' => '1']],
        ]),
    ]);
    Item::create([
        'name' => 'daily-prize-child',
        'code' => 'daily-child',
        'data' => serialize(['code' => 'daily-child']),
    ]);
    MarketInnovationState::start($user->uid, $feature);
    expect(fn () => MarketInnovationState::grantDailyReward($user->uid, $feature))
        ->toThrow(InvalidArgumentException::class);

    $state = MarketInnovationState::load($user->uid, $feature);
    $state['daily_points'] = 10000;
    DB::table('market_innovation_states')->where('uid', $user->uid)->where('feature', $feature)
        ->update(['state' => json_encode($state, JSON_THROW_ON_ERROR)]);

    $first = MarketInnovationState::grantDailyReward($user->uid, $feature);
    $second = MarketInnovationState::grantDailyReward($user->uid, $feature);
    expect($first['granted'])->toBeTrue()
        ->and($first['featureData']['daily_reward_granted'])->toBeTrue()
        ->and($second['granted'])->toBeFalse()
        ->and(getGiftBox($user->uid)['daily-child'][0])->toBe(1);
});

it('repeats market seasons and grants the earned final building only once', function (): void {
    require_once AMFPHP_ROOTPATH.'Helpers/general_functions.php';
    $user = User::factory()->create();
    $feature = MarketCompetition::FARMERS;
    $firstDay = new DateTimeImmutable('2026-10-05 12:00:00 UTC');
    $nextSeason = new DateTimeImmutable('2026-11-09 12:00:00 UTC');
    Item::create([
        'name' => 'xfs_streetvendorshop',
        'code' => 'market-final-building-test',
        'data' => serialize(['code' => 'market-final-building-test']),
    ]);

    $state = MarketInnovationState::start($user->uid, $feature, $firstDay);
    $state['total_gold_coins'] = 45000;
    $state['orderboard_task_points'] = 25;
    $state['current_storage_capacity_level'] = 2;
    DB::table('market_innovation_states')->where('uid', $user->uid)->where('feature', $feature)
        ->update(['state' => json_encode($state, JSON_THROW_ON_ERROR)]);

    $rolled = MarketInnovationState::startNewDay($user->uid, $feature, $nextSeason);
    $retry = MarketInnovationState::startNewDay($user->uid, $feature, $nextSeason);
    expect($rolled['season_start'])->toBe('2026-11-09')
        ->and($rolled['total_gold_coins'])->toBe(0)
        ->and($rolled['orderboard_task_points'])->toBe(0)
        ->and($rolled['weekly_score'])->toBe(0)
        ->and($rolled['current_storage_capacity_level'])->toBe(2)
        ->and($rolled['completed_seasons']['2026-10-05']['badge'])->toBe('xfs_streetvendorshop')
        ->and($retry['season_start'])->toBe('2026-11-09')
        ->and(MarketCompetition::standings($user->uid, $feature, $nextSeason)['userData']['value'])->toBe(0)
        ->and(getGiftBox($user->uid)['market-final-building-test'][0])->toBe(1);

    $claimed = MarketInnovationState::claimFinalReward($user->uid, $feature, $nextSeason);
    $claimedAgain = MarketInnovationState::claimFinalReward($user->uid, $feature, $nextSeason);
    expect($claimed['badge'])->toBe('xfs_streetvendorshop')
        ->and($claimedAgain)->toBe(['BONUS_GRANTED' => 1])
        ->and(getGiftBox($user->uid)['market-final-building-test'][0])->toBe(1);

    Item::create([
        'name' => 'xfs_valleymarketshop',
        'code' => 'market-second-final-test',
        'data' => serialize(['code' => 'market-second-final-test']),
    ]);
    $state = MarketInnovationState::load($user->uid, $feature);
    $state['total_gold_coins'] = 200000;
    DB::table('market_innovation_states')->where('uid', $user->uid)->where('feature', $feature)
        ->update(['state' => json_encode($state, JSON_THROW_ON_ERROR)]);
    $followingSeason = new DateTimeImmutable('2026-12-14 12:00:00 UTC');
    $secondRollover = MarketInnovationState::startNewDay($user->uid, $feature, $followingSeason);
    expect($secondRollover['season_start'])->toBe('2026-12-14')
        ->and($secondRollover['completed_seasons']['2026-11-09']['badge'])->toBe('xfs_valleymarketshop')
        ->and(MarketInnovationState::claimFinalReward($user->uid, $feature, $followingSeason)['badge'])->toBe('xfs_valleymarketshop')
        ->and(getGiftBox($user->uid)['market-final-building-test'][0])->toBe(1)
        ->and(getGiftBox($user->uid)['market-second-final-test'][0])->toBe(1);
});

it('uses the original final-building thresholds for both recurring markets', function (): void {
    expect(MarketRewards::finalRewardName(MarketCompetition::FARMERS, 49999))->toBeNull()
        ->and(MarketRewards::finalRewardName(MarketCompetition::FARMERS, 50000))->toBe('xfs_streetvendorshop')
        ->and(MarketRewards::finalRewardName(MarketCompetition::FARMERS, 200000))->toBe('xfs_valleymarketshop')
        ->and(MarketRewards::finalRewardName(MarketCompetition::FARMERS, 500000))->toBe('xfs_valleygroceryshop')
        ->and(MarketRewards::finalRewardName(MarketCompetition::FARMERS, 1000000))->toBe('xfs_farmfreshsupermarket')
        ->and(MarketRewards::finalRewardName(MarketCompetition::TROPICAL, 49999))->toBeNull()
        ->and(MarketRewards::finalRewardName(MarketCompetition::TROPICAL, 50000))->toBe('xah_pineappledrinkhut')
        ->and(MarketRewards::finalRewardName(MarketCompetition::TROPICAL, 300000))->toBe('xah_fruitdrinkshop')
        ->and(MarketRewards::finalRewardName(MarketCompetition::TROPICAL, 800000))->toBe('xah_freshsummerbar')
        ->and(MarketRewards::finalRewardName(MarketCompetition::TROPICAL, 1500000))->toBe('xah_islandjuicemarket');
});

it('keeps all six Tropical weeks of archived daily prizes in the rotation', function (): void {
    $start = new DateTimeImmutable('2026-10-05 12:00:00 UTC');
    expect(MarketRewards::dailyRewardName(MarketCompetition::TROPICAL, $start->modify('+38 days')))->toBe('horse_xah_tropicbeauty')
        ->and(MarketRewards::dailyRewardName(MarketCompetition::TROPICAL, $start->modify('+39 days')))->toBe('pkg_xah_vehicleessentials')
        ->and(MarketRewards::dailyRewardName(MarketCompetition::TROPICAL, $start->modify('+40 days')))->toBe('dog_xah_sandwaypoodle')
        ->and(MarketRewards::dailyRewardName(MarketCompetition::TROPICAL, $start->modify('+41 days')))->toBe('rhino_xah_sailready')
        ->and(MarketRewards::dailyRewardName(MarketCompetition::TROPICAL, $start->modify('+42 days')))->toBe('goat_xah_hawaiishirt');
});

it('ends a below-threshold market season without inventing a final gift', function (): void {
    $user = User::factory()->create();
    $feature = MarketCompetition::TROPICAL;
    $firstDay = new DateTimeImmutable('2026-10-05 12:00:00 UTC');
    $nextSeason = new DateTimeImmutable('2026-11-16 12:00:00 UTC');
    MarketInnovationState::start($user->uid, $feature, $firstDay);
    expect(fn () => MarketInnovationState::claimFinalReward($user->uid, $feature, $firstDay))
        ->toThrow(InvalidArgumentException::class);
    $state = MarketInnovationState::startNewDay($user->uid, $feature, $nextSeason);
    $result = MarketInnovationState::claimFinalReward($user->uid, $feature, $nextSeason);
    expect($state['completed_seasons']['2026-10-05']['badge'])->toBeNull()
        ->and($result['none'])->toBeTrue()
        ->and($result['BONUS_GRANTED'])->toBe(1)
        ->and(DB::table('playermeta')->where('uid', $user->uid)->where('meta_key', 'giftbox')->exists())->toBeFalse();
});

it('enforces ownership, 24-hour cooldown and a 14-day window on bonus harvests', function (): void {
    require_once AMFPHP_ROOTPATH.'Helpers/general_functions.php';
    $user = User::factory()->create();
    $other = User::factory()->create();
    $feature = MarketCompetition::FARMERS;
    $firstDay = new DateTimeImmutable('2026-10-05 12:00:00 UTC');
    $nextSeason = new DateTimeImmutable('2026-11-09 12:00:00 UTC');
    Item::create(['name' => 'fuel1', 'code' => 'market-fuel-test', 'data' => serialize(['code' => 'market-fuel-test'])]);
    Item::create(['name' => 'xfs_streetvendorshop', 'code' => 'market-final-building-test', 'data' => serialize(['code' => 'market-final-building-test'])]);
    $state = MarketInnovationState::start($user->uid, $feature, $firstDay);
    $state['total_gold_coins'] = 50000;
    DB::table('market_innovation_states')->where('uid', $user->uid)->where('feature', $feature)
        ->update(['state' => json_encode($state, JSON_THROW_ON_ERROR)]);
    MarketInnovationState::startNewDay($user->uid, $feature, $nextSeason);

    $world = DB::table('userworlds')->insertGetId(['uid' => $other->uid, 'type' => 'main', 'objects' => '', 'messageManager' => '']);
    $objectId = DB::table('world_objects')->insertGetId([
        'world_id' => $world, 'object_id' => 1, 'class_name' => 'Building',
        'item_name' => 'xfs_streetvendorshop', 'created_at' => now(), 'updated_at' => now(),
    ]);
    expect(fn () => MarketInnovationState::harvestBonusBuilding($user->uid, $feature, 'xfs_streetvendorshop', $nextSeason))
        ->toThrow(InvalidArgumentException::class);
    DB::table('userworlds')->where('id', $world)->update(['uid' => $user->uid]);
    expect(fn () => MarketInnovationState::harvestBonusBuilding($user->uid, $feature, 'xfs_valleymarketshop', $nextSeason))
        ->toThrow(InvalidArgumentException::class);

    $first = MarketInnovationState::harvestBonusBuilding($user->uid, $feature, 'xfs_streetvendorshop', $nextSeason);
    $retry = MarketInnovationState::harvestBonusBuilding($user->uid, $feature, 'xfs_streetvendorshop', $nextSeason);
    expect($first['granted'])->toBeTrue()
        ->and($first['rewardName'])->toBe('fuel1')
        ->and($retry['granted'])->toBeFalse()
        ->and(MarketRewards::bonusHarvestRewardName($feature, 'xfs_streetvendorshop', 1))->toBe('vehiclepart')
        ->and(getGiftBox($user->uid)['market-fuel-test'][0])->toBe(1);
    DB::table('world_objects')->where('id', $objectId)->update(['deleted' => true]);
    expect(fn () => MarketInnovationState::harvestBonusBuilding($user->uid, $feature, 'xfs_streetvendorshop', $nextSeason->modify('+2 days')))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => MarketInnovationState::harvestBonusBuilding($user->uid, $feature, 'xfs_streetvendorshop', $nextSeason->modify('+14 days')))
        ->toThrow(InvalidArgumentException::class);
});

it('grants two items per harvest from the top Tropical final building', function (): void {
    require_once AMFPHP_ROOTPATH.'Helpers/general_functions.php';
    $user = User::factory()->create();
    $feature = MarketCompetition::TROPICAL;
    $firstDay = new DateTimeImmutable('2026-10-05 12:00:00 UTC');
    $nextSeason = new DateTimeImmutable('2026-11-16 12:00:00 UTC');
    Item::create(['name' => 'xah_islandjuicemarket', 'code' => 'tropical-final-test', 'data' => serialize(['code' => 'tropical-final-test'])]);
    Item::create(['name' => 'consume_instagrow', 'code' => 'tropical-bonus-test', 'data' => serialize(['code' => 'tropical-bonus-test'])]);
    $state = MarketInnovationState::start($user->uid, $feature, $firstDay);
    $state['total_gold_coins'] = 1500000;
    DB::table('market_innovation_states')->where('uid', $user->uid)->where('feature', $feature)
        ->update(['state' => json_encode($state, JSON_THROW_ON_ERROR)]);
    MarketInnovationState::startNewDay($user->uid, $feature, $nextSeason);
    $world = DB::table('userworlds')->insertGetId(['uid' => $user->uid, 'type' => 'main', 'objects' => '', 'messageManager' => '']);
    DB::table('world_objects')->insert([
        'world_id' => $world, 'object_id' => 1, 'class_name' => 'Building',
        'item_name' => 'xah_islandjuicemarket', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $granted = MarketInnovationState::harvestBonusBuilding($user->uid, $feature, 'xah_islandjuicemarket', $nextSeason);
    $retry = MarketInnovationState::harvestBonusBuilding($user->uid, $feature, 'xah_islandjuicemarket', $nextSeason);
    expect($granted['quantity'])->toBe(2)
        ->and($granted['rewardName'])->toBe('consume_instagrow')
        ->and($retry['granted'])->toBeFalse()
        ->and(getGiftBox($user->uid)['tropical-bonus-test'][0])->toBe(2);
});

it('charges a storage upgrade once even if its request is retried', function (): void {
    require_once AMFPHP_ROOTPATH.'Helpers/user_resources.php';

    $user = User::factory()->create();
    $uid = $user->uid;
    $feature = MarketCompetition::FARMERS;
    DB::table('usermeta')->insert([
        'uid' => $uid,
        'firstName' => 'Test',
        'lastName' => 'Farmer',
        'cash' => 100,
    ]);
    $before = UserResources::getCash($uid);
    expect($before)->toBeGreaterThanOrEqual(10);

    $first = MarketInnovationState::upgradeStorage($uid, $feature, 0);
    $retry = MarketInnovationState::upgradeStorage($uid, $feature, 0);
    expect($first['current_storage_capacity_level'])->toBe(1)
        ->and($retry['current_storage_capacity_level'])->toBe(1)
        ->and(UserResources::getCash($uid))->toBe($before - 10);
});

it('uses Tropical Market storage capacities and upgrade prices', function (): void {
    require_once AMFPHP_ROOTPATH.'Helpers/user_resources.php';

    $user = User::factory()->create();
    $uid = $user->uid;
    $feature = MarketCompetition::TROPICAL;
    DB::table('usermeta')->insert([
        'uid' => $uid,
        'firstName' => 'Test',
        'lastName' => 'Farmer',
        'cash' => 100,
    ]);
    $task = collect(MarketInnovationTasks::harvestTasks($feature))->first(
        static fn (array $definition): bool => $definition['points'] > 1,
    );
    $state = MarketInnovationState::start($uid, $feature);
    $state['orderboard_task_points'] = 29;
    $state['saved_active_tasks'][0] = ['id' => $task['id'], 'orderboard_progress' => $task['total']];
    DB::table('market_innovation_states')->where('uid', $uid)->where('feature', $feature)
        ->update(['state' => json_encode($state, JSON_THROW_ON_ERROR)]);

    expect(fn () => MarketInnovationState::redeem($uid, $feature, 0))
        ->toThrow(InvalidArgumentException::class);
    $upgraded = MarketInnovationState::upgradeStorage($uid, $feature, 0);
    $redeemed = MarketInnovationState::redeem($uid, $feature, 0);
    expect($upgraded['current_storage_capacity_level'])->toBe(1)
        ->and($redeemed['orderboard_task_points'])->toBe(29 + $task['points']);

    MarketInnovationState::upgradeStorage($uid, $feature, 1);
    MarketInnovationState::upgradeStorage($uid, $feature, 2);
    $retry = MarketInnovationState::upgradeStorage($uid, $feature, 2);
    expect($retry['current_storage_capacity_level'])->toBe(3)
        ->and(UserResources::getCash($uid))->toBe(100 - 10 - 20 - 25);
});

it('enforces task-dump cooldown and charges a cash skip only once', function (): void {
    require_once AMFPHP_ROOTPATH.'Helpers/user_resources.php';

    $user = User::factory()->create();
    $uid = $user->uid;
    $feature = MarketCompetition::FARMERS;
    DB::table('usermeta')->insert([
        'uid' => $uid,
        'firstName' => 'Test',
        'lastName' => 'Farmer',
        'cash' => 10,
    ]);
    MarketInnovationState::start($uid, $feature);

    $dumped = MarketInnovationState::dumpTask($uid, $feature, 0);
    $retried = MarketInnovationState::dumpTask($uid, $feature, 0);
    expect($dumped['saved_dumped_timestamp'][0])->toBe($retried['saved_dumped_timestamp'][0])
        ->and(fn () => MarketInnovationState::newTask($uid, $feature, 0))
        ->toThrow(InvalidArgumentException::class);

    MarketInnovationState::skipDumpedTask($uid, $feature, 0);
    MarketInnovationState::skipDumpedTask($uid, $feature, 0);
    $replacement = MarketInnovationState::newTask($uid, $feature, 0);
    expect($replacement['saved_active_tasks'][0]['id'])->not->toBeNull()
        ->and(UserResources::getCash($uid))->toBe(9);
});

it('uses the archived task price and grants produce once on a paid skip', function (): void {
    require_once AMFPHP_ROOTPATH.'Helpers/user_resources.php';

    $user = User::factory()->create();
    $uid = $user->uid;
    $feature = MarketCompetition::FARMERS;
    DB::table('usermeta')->insert([
        'uid' => $uid,
        'firstName' => 'Test',
        'lastName' => 'Farmer',
        'cash' => 10,
    ]);
    $task = MarketInnovationTasks::harvestTasks($feature)[0];
    MarketInnovationState::start($uid, $feature);

    $first = MarketInnovationState::skipTask($uid, $feature, 0);
    $retry = MarketInnovationState::skipTask($uid, $feature, 0);
    expect($first['orderboard_task_points'])->toBe($task['points'])
        ->and($retry['orderboard_task_points'])->toBe($task['points'])
        ->and($first['saved_active_tasks'][0]['orderboard_progress'])->toBe(0)
        ->and(UserResources::getCash($uid))->toBe(10 - $task['cash']);
});

it('honors the limited-storage Continue RPC without exceeding capacity', function (): void {
    $user = User::factory()->create();
    $uid = $user->uid;
    $feature = MarketCompetition::FARMERS;
    $task = collect(MarketInnovationTasks::harvestTasks($feature))->first(
        static fn (array $definition): bool => $definition['points'] > 1,
    );
    $state = MarketInnovationState::start($uid, $feature);
    $state['orderboard_task_points'] = 49;
    $state['saved_active_tasks'][0] = ['id' => $task['id'], 'orderboard_progress' => $task['total']];
    DB::table('market_innovation_states')->where('uid', $uid)->where('feature', $feature)
        ->update(['state' => json_encode($state, JSON_THROW_ON_ERROR)]);
    $player = new class($uid) {
        public function __construct(private string $uid) {}
        public function getUid(): string { return $this->uid; }
    };
    $request = (object) ['params' => [$feature, 0]];

    expect(fn () => MarketInnovationState::redeem($uid, $feature, 0))
        ->toThrow(InvalidArgumentException::class);
    $first = FarmersMarketInnovationService::addRemainingOBPoints($player, $request);
    $retry = FarmersMarketInnovationService::addRemainingOBPoints($player, $request);
    expect($first['data']['featureData']['orderboard_task_points'])->toBe(50)
        ->and($retry['data']['featureData']['orderboard_task_points'])->toBe(50)
        ->and($first['data']['featureData']['saved_active_tasks'][0]['orderboard_progress'])->toBe(0)
        ->and(isset($retry['data']['featureData']['saved_active_tasks'][0]['id']))->toBeFalse();
});

it('charges a limited-storage paid skip only once', function (): void {
    require_once AMFPHP_ROOTPATH.'Helpers/user_resources.php';

    $user = User::factory()->create();
    $uid = $user->uid;
    $feature = MarketCompetition::TROPICAL;
    $task = collect(MarketInnovationTasks::harvestTasks($feature))->first(
        static fn (array $definition): bool => $definition['points'] > 1,
    );
    DB::table('usermeta')->insert([
        'uid' => $uid,
        'firstName' => 'Test',
        'lastName' => 'Farmer',
        'cash' => 100,
    ]);
    $state = MarketInnovationState::start($uid, $feature);
    $state['orderboard_task_points'] = 29;
    $state['saved_active_tasks'][0] = ['id' => $task['id'], 'orderboard_progress' => 0];
    DB::table('market_innovation_states')->where('uid', $uid)->where('feature', $feature)
        ->update(['state' => json_encode($state, JSON_THROW_ON_ERROR)]);
    $player = new class($uid) {
        public function __construct(private string $uid) {}
        public function getUid(): string { return $this->uid; }
    };
    $request = (object) ['params' => [$feature, 0]];

    $first = TropicalMarketInnovationService::addRemainingOBPointsOnSkip($player, $request);
    $retry = TropicalMarketInnovationService::addRemainingOBPointsOnSkip($player, $request);
    expect($first['data']['featureData']['orderboard_task_points'])->toBe(30)
        ->and($retry['data']['featureData']['orderboard_task_points'])->toBe(30)
        ->and(UserResources::getCash($uid))->toBe(100 - $task['cash']);
});

it('counts server-observed crafting events for archived recipe tasks', function (): void {
    require_once AMFPHP_ROOTPATH.'Helpers/quest_progress.php';

    $user = User::factory()->create();
    $uid = $user->uid;
    $feature = MarketCompetition::FARMERS;
    $supported = MarketInnovationTasks::supportedTasks($feature);
    $actions = array_count_values(array_column($supported, 'action'));
    expect(count($supported))->toBe(133)
        ->and(count(MarketInnovationTasks::supportedTasks(MarketCompetition::TROPICAL)))->toBe(133)
        ->and($actions['harvestByCode'])->toBeGreaterThan(0)
        ->and($actions['makeRecipeByCode'])->toBeGreaterThan(0)
        ->and($actions['expandStorageBuildingToLevelByCode'])->toBeGreaterThan(0)
        ->and($actions['completeFeatureCraftingNPC'])->toBeGreaterThan(0)
        ->and($actions['useItemByCode'])->toBeGreaterThan(0);

    $recipe = collect($supported)->firstWhere('action', 'makeRecipeByCode');
    $state = MarketInnovationState::start($uid, $feature);
    $state['saved_active_tasks'][0] = ['id' => $recipe['id'], 'orderboard_progress' => 0];
    DB::table('market_innovation_states')->where('uid', $uid)->where('feature', $feature)
        ->update(['state' => json_encode($state, JSON_THROW_ON_ERROR)]);

    MarketInnovationState::recordHarvest($uid, $recipe['code'], $recipe['total']);
    expect(MarketInnovationState::load($uid, $feature)['saved_active_tasks'][0]['orderboard_progress'])->toBe(0);

    trackQuestProgress($uid, 'makeRecipeByCode', $recipe['code'], $recipe['total']);
    $ready = MarketInnovationState::load($uid, $feature);
    expect($ready['saved_active_tasks'][0]['orderboard_progress'])->toBe($recipe['total'])
        ->and(MarketInnovationState::redeem($uid, $feature, 0)['orderboard_task_points'])
        ->toBe($recipe['points']);
});

it('counts only correctly typed market-world animal harvests for category tasks', function (): void {
    require_once AMFPHP_ROOTPATH.'Helpers/quest_progress.php';

    $user = User::factory()->create();
    $uid = $user->uid;
    $feature = MarketCompetition::FARMERS;
    $category = collect(MarketInnovationTasks::supportedTasks($feature))
        ->firstWhere('action', 'harvestByCategory');
    expect($category['code'])->toBe('AllFarmfestAnimals');

    $state = MarketInnovationState::start($uid, $feature);
    $state['saved_active_tasks'][0] = ['id' => $category['id'], 'orderboard_progress' => 0];
    DB::table('market_innovation_states')->where('uid', $uid)->where('feature', $feature)
        ->update(['state' => json_encode($state, JSON_THROW_ON_ERROR)]);

    trackQuestProgress($uid, 'harvestByCategory', 'xfs_purpleartichoke', 1, [
        'categories' => QuestCategoryResolver::categories('xfs_purpleartichoke', ['type' => 'seed']),
    ]);
    expect(MarketInnovationState::load($uid, $feature)['saved_active_tasks'][0]['orderboard_progress'])->toBe(0);

    trackQuestProgress($uid, 'harvestByCategory', 'rooster_xfs_icecream', 1, [
        'categories' => QuestCategoryResolver::categories('rooster_xfs_icecream', ['type' => 'animal']),
    ]);
    expect(MarketInnovationState::load($uid, $feature)['saved_active_tasks'][0]['orderboard_progress'])->toBe(1);
});

it('debits a coin-sink task once and grants its archived produce points', function (): void {
    require_once AMFPHP_ROOTPATH.'Helpers/user_resources.php';

    $user = User::factory()->create();
    $uid = $user->uid;
    $feature = MarketCompetition::FARMERS;
    $task = collect(MarketInnovationTasks::supportedTasks($feature))->firstWhere('action', 'sinkGold');
    DB::table('usermeta')->insert([
        'uid' => $uid,
        'firstName' => 'Test',
        'lastName' => 'Farmer',
        'gold' => $task['total'] + 1000,
    ]);
    $state = MarketInnovationState::start($uid, $feature);
    $state['saved_active_tasks'][0] = ['id' => $task['id'], 'orderboard_progress' => 0];
    DB::table('market_innovation_states')->where('uid', $uid)->where('feature', $feature)
        ->update(['state' => json_encode($state, JSON_THROW_ON_ERROR)]);

    $first = MarketInnovationState::sinkGold($uid, $feature, 0);
    $retry = MarketInnovationState::sinkGold($uid, $feature, 0);
    expect($first['orderboard_task_points'])->toBe($task['points'])
        ->and($retry['orderboard_task_points'])->toBe($task['points'])
        ->and($first['saved_active_tasks'][0]['orderboard_progress'])->toBe(0)
        ->and(UserResources::getGold($uid))->toBe(1000);
});

it('consumes the exact bushels once for an archived market sink task', function (): void {
    $user = User::factory()->create();
    $uid = $user->uid;
    $feature = MarketCompetition::FARMERS;
    $task = collect(MarketInnovationTasks::supportedTasks($feature))->firstWhere('action', 'sinkBushel');
    Item::create([
        'name' => 'market-test-bushel',
        'code' => $task['code'],
        'data' => serialize(['code' => $task['code'], 'type' => 'bushel', 'className' => 'CBushel']),
    ]);
    CraftingInventory::create([
        'uid' => $uid,
        'item_code' => $task['code'],
        'quantity' => $task['total'],
        'storage_type' => 'stall',
    ]);
    $state = MarketInnovationState::start($uid, $feature);
    $state['saved_active_tasks'][0] = ['id' => $task['id'], 'orderboard_progress' => 0];
    DB::table('market_innovation_states')->where('uid', $uid)->where('feature', $feature)
        ->update(['state' => json_encode($state, JSON_THROW_ON_ERROR)]);

    $first = MarketInnovationState::sinkBushel($uid, $feature, 0);
    $retry = MarketInnovationState::sinkBushel($uid, $feature, 0);
    expect($first['orderboard_task_points'])->toBe($task['points'])
        ->and($retry['orderboard_task_points'])->toBe($task['points'])
        ->and($first['saved_active_tasks'][0]['orderboard_progress'])->toBe(0)
        ->and(CraftingInventory::where('uid', $uid)->where('item_code', $task['code'])->value('quantity'))->toBe(0);
});
