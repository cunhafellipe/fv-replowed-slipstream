<?php

namespace App\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Durable order-board state. Produce and progress are never read from a client save. */
final class MarketInnovationState
{
    private const STORAGE_CAPACITIES = [
        MarketCompetition::FARMERS => [50, 100, 150, 200, 300, 400],
        MarketCompetition::TROPICAL => [30, 50, 80, 100, 150, 200],
    ];

    private const STORAGE_UPGRADE_COSTS = [
        MarketCompetition::FARMERS => [0, 10, 20, 30, 50, 80],
        MarketCompetition::TROPICAL => [0, 10, 20, 25, 40, 50],
    ];

    private static function storageCapacity(string $feature, array $state): int
    {
        return self::STORAGE_CAPACITIES[$feature][min(5, max(0, (int) $state['current_storage_capacity_level']))];
    }

    private static function defaults(): array
    {
        return [
            'saved_active_tasks' => [],
            'saved_dumped_timestamp' => [],
            'orderboard_task_points' => 0,
            'total_gold_coins' => 0,
            'weekly_score' => 0,
            'daily_points' => 0,
            'daily_reward_granted' => false,
            'current_storage_capacity_level' => 0,
            'sell_price' => 100,
            'task_cursor' => 0,
            'task_catalog_revision' => 0,
            'score_week_start' => '',
            'active_day_timestamp' => 0,
            'current_week' => 0,
            'season_start' => '',
            'completed_seasons' => [],
            'bonus_harvest_start_time' => 0,
            'farmersMarket_bonus_reward_granted' => 0,
            'tropical_market_bonus_reward_granted' => 0,
            // The legacy client offers a 750-coin local-only tutorial grant.
            // Keep it disabled until that grant is represented in the ledger.
            'ftue_points_granted' => 1,
        ];
    }

    public static function load(string $uid, string $feature): array
    {
        MarketCompetition::assertFeature($feature);
        $raw = DB::table('market_innovation_states')
            ->where('uid', $uid)
            ->where('feature', $feature)
            ->value('state');

        return self::decode($raw);
    }

    private static function decode(?string $raw): array
    {
        $stored = $raw === null ? [] : json_decode($raw, true);
        $state = array_replace(self::defaults(), is_array($stored) ? $stored : []);

        // Older redeemed slots retained the completed task's progress after
        // removing its ID. Flash then rendered both Redeem and Show me!.
        // A slot without an ID has no active task whose progress can count.
        if (is_array($state['saved_active_tasks'])) {
            foreach ($state['saved_active_tasks'] as &$task) {
                if (is_array($task) && ! isset($task['id'])) {
                    $task['orderboard_progress'] = 0;
                }
            }
            unset($task);
        }

        return $state;
    }

    private static function mutate(string $uid, string $feature, callable $change): array
    {
        MarketCompetition::assertFeature($feature);

        return DB::transaction(static function () use ($uid, $feature, $change): array {
            if (! DB::table('users')->where('uid', $uid)->lockForUpdate()->exists()) {
                throw new InvalidArgumentException('Market player does not exist.');
            }

            $row = DB::table('market_innovation_states')
                ->where('uid', $uid)
                ->where('feature', $feature)
                ->lockForUpdate()
                ->first();
            $state = self::decode($row->state ?? null);
            $change($state);
            $encoded = json_encode($state, JSON_THROW_ON_ERROR);
            if ($row) {
                DB::table('market_innovation_states')
                    ->where('id', $row->id)
                    ->update(['state' => $encoded, 'updated_at' => now()]);
            } else {
                DB::table('market_innovation_states')->insert([
                    'uid' => $uid,
                    'feature' => $feature,
                    'state' => $encoded,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $state;
        });
    }

    public static function start(string $uid, string $feature, ?DateTimeInterface $at = null): array
    {
        $now = $at === null ? new DateTimeImmutable('now', new DateTimeZone('UTC'))
            : DateTimeImmutable::createFromInterface($at)->setTimezone(new DateTimeZone('UTC'));
        self::mutate($uid, $feature, static function (array &$state) use ($feature, $now): void {
            if ($state['season_start'] === '') {
                $state['season_start'] = MarketSeason::seasonStart($feature, $now)->format('Y-m-d');
            }
            if ($state['saved_active_tasks'] !== []) {
                return;
            }

            $tasks = MarketInnovationTasks::assignableTasks($feature);
            $state['saved_active_tasks'] = [
                ['id' => $tasks[0]['id'], 'orderboard_progress' => 0],
                ['id' => $tasks[1]['id'], 'orderboard_progress' => 0],
                ['id' => $tasks[2]['id'], 'orderboard_progress' => 0],
            ];
            $state['task_cursor'] = 3;
            $state['task_catalog_revision'] = $feature === MarketCompetition::FARMERS ? 1 : 0;
            $state['score_week_start'] = MarketCompetition::weekStart($now);
            $state['current_week'] = self::weekIndex($feature, $now);
            $state['active_day_timestamp'] = $now->setTime(0, 0)->getTimestamp();
        });

        return self::startNewDay($uid, $feature, $now);
    }

    private static function weekIndex(string $feature, DateTimeInterface $at): int
    {
        $season = MarketSeason::seasonStart($feature, $at);
        $week = new DateTimeImmutable(MarketCompetition::weekStart($at), new DateTimeZone('UTC'));

        return intdiv((int) $season->diff($week)->format('%a'), 7);
    }

    public static function startNewDay(string $uid, string $feature, ?DateTimeInterface $at = null): array
    {
        $now = $at === null ? new DateTimeImmutable('now', new DateTimeZone('UTC'))
            : DateTimeImmutable::createFromInterface($at)->setTimezone(new DateTimeZone('UTC'));
        return self::mutate($uid, $feature, static function (array &$state) use ($uid, $feature, $now): void {
            $today = $now->setTime(0, 0)->getTimestamp();
            $week = MarketCompetition::weekStart($now);
            $seasonStart = MarketSeason::seasonStart($feature, $now)->format('Y-m-d');
            if ($state['season_start'] === '') {
                $state['season_start'] = $seasonStart;
            }
            $seasonChanged = $state['season_start'] !== $seasonStart;
            if ($state['score_week_start'] !== '' && $state['score_week_start'] !== $week) {
                // Unspent produce converts at a week boundary. At a season
                // boundary it counts toward the finished season's final
                // building, but never leaks into the next leaderboard.
                $produce = max(0, (int) $state['orderboard_task_points']);
                $conversion = match (true) {
                    $produce > 200 => 20000,
                    $produce > 100 => 10000,
                    $produce >= 21 => 5000,
                    default => 0,
                };
                $state['orderboard_task_points'] = 0;
                $state['weekly_score'] = $conversion > 0 && ! $seasonChanged
                    ? MarketCompetition::recordSale($uid, $feature, $conversion, 'weekly-conversion-'.$state['score_week_start'], $now)
                    : 0;
                $state['total_gold_coins'] = max(0, (int) $state['total_gold_coins']) + $conversion;
                $state['daily_points'] = $seasonChanged ? 0 : $conversion;
                $state['daily_reward_granted'] = false;
            } elseif ((int) $state['active_day_timestamp'] !== $today) {
                $state['daily_points'] = 0;
                $state['daily_reward_granted'] = false;
            }

            if ($seasonChanged) {
                $previousStart = (string) $state['season_start'];
                if (! isset($state['completed_seasons'][$previousStart])) {
                    $badge = MarketRewards::finalRewardName($feature, (int) $state['total_gold_coins']);
                    $endedAt = (new DateTimeImmutable($previousStart, new DateTimeZone('UTC')))
                        ->modify('+'.MarketSeason::weekCount($feature).' weeks')->getTimestamp();
                    if ($badge !== null) {
                        MarketRewards::grantNamedGift($uid, $badge);
                    }
                    $state['completed_seasons'][$previousStart] = [
                        'badge' => $badge,
                        'initial_harvest_time' => $endedAt,
                        'harvest_count' => 0,
                        'last_harvest_at' => 0,
                        'last_harvest_reward' => null,
                    ];
                }

                // A new season starts a fresh board and coin total. Retain
                // paid storage upgrades, which belong to the account.
                $tasks = MarketInnovationTasks::assignableTasks($feature);
                $state['saved_active_tasks'] = [
                    ['id' => $tasks[0]['id'], 'orderboard_progress' => 0],
                    ['id' => $tasks[1]['id'], 'orderboard_progress' => 0],
                    ['id' => $tasks[2]['id'], 'orderboard_progress' => 0],
                ];
                $state['saved_dumped_timestamp'] = [];
                $state['task_cursor'] = 3;
                $state['task_catalog_revision'] = $feature === MarketCompetition::FARMERS ? 1 : 0;
                $state['total_gold_coins'] = 0;
                $state['season_start'] = $seasonStart;
                $state['bonus_harvest_start_time'] = $state['completed_seasons'][$previousStart]['badge'] === null
                    ? 0 : $state['completed_seasons'][$previousStart]['initial_harvest_time'];
                $claimKey = $feature === MarketCompetition::FARMERS
                    ? 'farmersMarket_bonus_reward_granted' : 'tropical_market_bonus_reward_granted';
                $state[$claimKey] = 0;
            }

            if ($feature === MarketCompetition::FARMERS
                && (int) $state['task_catalog_revision'] < 1
                && $state['saved_active_tasks'] !== []) {
                // Previously assigned tasks describe Harvest Valley crops.
                // Replace them for free; earned produce, coins, and upgrades
                // remain untouched. Progress cannot carry to a different crop.
                $tasks = MarketInnovationTasks::assignableTasks($feature);
                $state['saved_active_tasks'] = [
                    ['id' => $tasks[0]['id'], 'orderboard_progress' => 0],
                    ['id' => $tasks[1]['id'], 'orderboard_progress' => 0],
                    ['id' => $tasks[2]['id'], 'orderboard_progress' => 0],
                ];
                $state['saved_dumped_timestamp'] = [];
                $state['task_cursor'] = 3;
                $state['task_catalog_revision'] = 1;
            }

            $state['score_week_start'] = $week;
            $state['current_week'] = self::weekIndex($feature, $now);
            $state['active_day_timestamp'] = $today;
        });
    }

    /** Final buildings are granted during rollover; this only acknowledges the completed season. */
    public static function claimFinalReward(string $uid, string $feature, ?DateTimeInterface $at = null): array
    {
        self::startNewDay($uid, $feature, $at);
        $result = [];
        self::mutate($uid, $feature, static function (array &$state) use ($feature, &$result): void {
            $completed = $state['completed_seasons'];
            if ($completed === []) {
                throw new InvalidArgumentException('The market season has not ended.');
            }
            $latest = $completed[array_key_last($completed)];
            $claimKey = $feature === MarketCompetition::FARMERS
                ? 'farmersMarket_bonus_reward_granted' : 'tropical_market_bonus_reward_granted';
            if ((int) $state[$claimKey] !== 0) {
                $result = ['BONUS_GRANTED' => 1];
                return;
            }
            $result = ($latest['badge'] === null ? ['none' => true] : ['badge' => $latest['badge']]) + [
                'BONUS_GRANTED' => 1,
                'initial_harvest_time' => (int) $latest['initial_harvest_time'],
            ];
            $state[$claimKey] = 1;
        });

        return $result;
    }

    /** Grant a placed final building's archived bonus at most once per 24 hours. */
    public static function harvestBonusBuilding(
        string $uid,
        string $feature,
        string $building,
        ?DateTimeInterface $at = null,
    ): array {
        $now = $at === null ? new DateTimeImmutable('now', new DateTimeZone('UTC'))
            : DateTimeImmutable::createFromInterface($at)->setTimezone(new DateTimeZone('UTC'));
        self::startNewDay($uid, $feature, $now);
        $result = [];
        self::mutate($uid, $feature, static function (array &$state) use ($uid, $feature, $building, $now, &$result): void {
            $completed = $state['completed_seasons'];
            if ($completed === []) {
                throw new InvalidArgumentException('No completed market season can be harvested.');
            }
            $key = array_key_last($completed);
            $season = $completed[$key];
            if ($season['badge'] !== $building) {
                throw new InvalidArgumentException('This is not the earned market bonus building.');
            }
            $started = (int) $season['initial_harvest_time'];
            $timestamp = $now->getTimestamp();
            if ($timestamp < $started || $timestamp >= $started + 14 * 86400) {
                throw new InvalidArgumentException('The market bonus harvest period has ended.');
            }
            $placed = DB::table('world_objects as objects')
                ->join('userworlds as worlds', 'worlds.id', '=', 'objects.world_id')
                ->where('worlds.uid', $uid)
                ->where('objects.item_name', $building)
                ->where('objects.deleted', false)
                ->exists();
            if (! $placed) {
                throw new InvalidArgumentException('The earned market building is not placed on this farm.');
            }
            $last = (int) $season['last_harvest_at'];
            if ($last > 0 && $timestamp - $last < 86400) {
                $result = ['granted' => false, 'buildingName' => $building, 'rewardName' => $season['last_harvest_reward'], 'lastHarvestTime' => $last];
                return;
            }
            $reward = MarketRewards::bonusHarvestRewardName($feature, $building, (int) $season['harvest_count']);
            $quantity = in_array($building, ['xfs_farmfreshsupermarket', 'xah_islandjuicemarket'], true) ? 2 : 1;
            MarketRewards::grantNamedGift($uid, $reward, $quantity);
            $season['last_harvest_at'] = $timestamp;
            $season['last_harvest_reward'] = $reward;
            $season['harvest_count'] = (int) $season['harvest_count'] + 1;
            $state['completed_seasons'][$key] = $season;
            $result = ['granted' => true, 'buildingName' => $building, 'rewardName' => $reward, 'quantity' => $quantity, 'lastHarvestTime' => $timestamp];
        });

        return $result;
    }

    public static function grantDailyReward(string $uid, string $feature): array
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        self::startNewDay($uid, $feature, $now);
        $granted = false;
        $reward = MarketRewards::dailyRewardName($feature, $now);
        $state = self::mutate($uid, $feature, static function (array &$state) use ($uid, $reward, &$granted): void {
            if ($state['daily_reward_granted']) {
                return;
            }
            if ((int) $state['daily_points'] < 10000) {
                throw new InvalidArgumentException('Daily market target has not been met.');
            }
            if ($reward === null) {
                throw new InvalidArgumentException('No daily market reward is scheduled.');
            }
            MarketRewards::grantNamedGift($uid, $reward);
            $state['daily_reward_granted'] = true;
            $granted = true;
        });

        return ['featureData' => $state, 'granted' => $granted, 'rewardName' => $reward];
    }

    public static function upgradeStorage(string $uid, string $feature, int $expectedLevel): array
    {
        if ($expectedLevel < 0 || $expectedLevel >= 5) {
            throw new InvalidArgumentException('Invalid market storage upgrade level.');
        }

        return self::mutate($uid, $feature, static function (array &$state) use ($uid, $feature, $expectedLevel): void {
            $current = (int) $state['current_storage_capacity_level'];
            if ($current > $expectedLevel) {
                return; // A retry cannot buy the same level twice.
            }
            if ($current !== $expectedLevel) {
                throw new InvalidArgumentException('Market storage level is stale.');
            }
            if (! \UserResources::removeCash($uid, self::STORAGE_UPGRADE_COSTS[$feature][$current + 1])) {
                throw new InvalidArgumentException('Insufficient cash for market storage upgrade.');
            }
            $state['current_storage_capacity_level'] = $current + 1;
        });
    }

    public static function recordHarvest(string $uid, string $itemCode, int $amount = 1): void
    {
        self::recordProgress($uid, 'harvestByCode', $itemCode, $amount);
    }

    public static function recordProgress(string $uid, string $action, string $itemCode, int $amount = 1, array $extraData = []): void
    {
        if ($amount <= 0 || $itemCode === '' || ! in_array($action, [
            'harvestByCode',
            'harvestByCategory',
            'makeRecipeByCode',
            'expandStorageBuildingToLevelByCode',
            'completeFeatureCraftingNPC',
            'useItemByCode',
        ], true)) {
            return;
        }

        foreach (MarketCompetition::enabledFeatures() as $feature) {
            // A dormant market must not create state or count earlier harvests.
            $raw = DB::table('market_innovation_states')->where('uid', $uid)->where('feature', $feature)->value('state');
            if ($raw === null) {
                continue;
            }
            $current = self::decode($raw);
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            if ($current['season_start'] !== MarketSeason::seasonStart($feature, $now)->format('Y-m-d')
                || $current['score_week_start'] !== MarketCompetition::weekStart($now)
                || (int) $current['active_day_timestamp'] !== $now->setTime(0, 0)->getTimestamp()) {
                self::startNewDay($uid, $feature, $now);
            }

            self::mutate($uid, $feature, static function (array &$state) use ($feature, $action, $itemCode, $amount, $extraData): void {
                $catalog = [];
                foreach (MarketInnovationTasks::supportedTasks($feature) as $task) {
                    $catalog[$task['id']] = $task;
                }
                foreach ($state['saved_active_tasks'] as &$active) {
                    $definition = $catalog[(string) ($active['id'] ?? '')] ?? null;
                    if ($definition === null || $definition['action'] !== $action) {
                        continue;
                    }
                    $matches = $action === 'harvestByCategory'
                        ? in_array($definition['code'], $extraData['categories'] ?? [], true)
                        : $definition['code'] === $itemCode;
                    if (! $matches) {
                        continue;
                    }
                    $active['orderboard_progress'] = min(
                        $definition['total'],
                        max(0, (int) ($active['orderboard_progress'] ?? 0)) + $amount,
                    );
                }
                unset($active);
            });
        }
    }

    public static function redeem(string $uid, string $feature, int $slot): array
    {
        return self::mutate($uid, $feature, static function (array &$state) use ($feature, $slot): void {
            $active = $state['saved_active_tasks'][$slot] ?? null;
            if (! is_array($active)) {
                throw new InvalidArgumentException('Market task slot is empty.');
            }
            $catalog = [];
            foreach (MarketInnovationTasks::supportedTasks($feature) as $task) {
                $catalog[$task['id']] = $task;
            }
            $definition = $catalog[(string) ($active['id'] ?? '')] ?? null;
            if ($definition === null || (int) ($active['orderboard_progress'] ?? 0) < $definition['total']) {
                throw new InvalidArgumentException('Market task is not complete.');
            }

            $capacity = self::storageCapacity($feature, $state);
            $produce = max(0, (int) $state['orderboard_task_points']);
            if ($produce + $definition['points'] > $capacity) {
                throw new InvalidArgumentException('Market produce storage is full.');
            }

            $state['orderboard_task_points'] = $produce + $definition['points'];
            // The original board shows a completed slot until Get New Task.
            $state['saved_active_tasks'][$slot] = ['orderboard_progress' => 0];
        });
    }

    /** The limited-storage Continue button awards only the free space. */
    public static function redeemRemaining(string $uid, string $feature, int $slot, bool $paidSkip = false): array
    {
        return self::mutate($uid, $feature, static function (array &$state) use ($uid, $feature, $slot, $paidSkip): void {
            $active = $state['saved_active_tasks'][$slot] ?? null;
            if (! is_array($active)) {
                throw new InvalidArgumentException('Market task slot is invalid.');
            }
            if (! isset($active['id'])) {
                return; // An acknowledged retry must not award points or charge again.
            }

            $catalog = [];
            foreach (MarketInnovationTasks::supportedTasks($feature) as $task) {
                $catalog[$task['id']] = $task;
            }
            $task = $catalog[(string) $active['id']] ?? null;
            if ($task === null) {
                throw new InvalidArgumentException('Market task is no longer available.');
            }
            if (! $paidSkip && (int) ($active['orderboard_progress'] ?? 0) < $task['total']) {
                throw new InvalidArgumentException('Market task is not complete.');
            }

            $capacity = self::storageCapacity($feature, $state);
            $produce = max(0, (int) $state['orderboard_task_points']);
            if ($produce + $task['points'] <= $capacity) {
                throw new InvalidArgumentException('Market storage is not limited for this task.');
            }
            if ($paidSkip && ! \UserResources::removeCash($uid, $task['cash'])) {
                throw new InvalidArgumentException('Insufficient cash to skip market task.');
            }

            $state['orderboard_task_points'] = min($capacity, $produce + $task['points']);
            $state['saved_active_tasks'][$slot] = ['orderboard_progress' => 0];
        });
    }

    public static function skipTask(string $uid, string $feature, int $slot): array
    {
        return self::mutate($uid, $feature, static function (array &$state) use ($uid, $feature, $slot): void {
            $active = $state['saved_active_tasks'][$slot] ?? null;
            if (! is_array($active)) {
                throw new InvalidArgumentException('Market task slot is invalid.');
            }
            if (! isset($active['id'])) {
                return; // Retried skip of an already completed slot.
            }
            $catalog = [];
            foreach (MarketInnovationTasks::supportedTasks($feature) as $task) {
                $catalog[$task['id']] = $task;
            }
            $task = $catalog[(string) $active['id']] ?? null;
            if ($task === null) {
                throw new InvalidArgumentException('Market task is no longer available.');
            }
            $capacity = self::storageCapacity($feature, $state);
            $produce = max(0, (int) $state['orderboard_task_points']);
            if ($produce + $task['points'] > $capacity) {
                throw new InvalidArgumentException('Market produce storage is full.');
            }
            if (! \UserResources::removeCash($uid, $task['cash'])) {
                throw new InvalidArgumentException('Insufficient cash to skip market task.');
            }
            $state['orderboard_task_points'] = $produce + $task['points'];
            $state['saved_active_tasks'][$slot] = ['orderboard_progress' => 0];
        });
    }

    public static function sinkGold(string $uid, string $feature, int $slot): array
    {
        return self::mutate($uid, $feature, static function (array &$state) use ($uid, $feature, $slot): void {
            $active = $state['saved_active_tasks'][$slot] ?? null;
            if (! is_array($active)) {
                throw new InvalidArgumentException('Market task slot is invalid.');
            }
            if (! isset($active['id'])) {
                return; // Retried completed sink; never debit twice.
            }
            $catalog = [];
            foreach (MarketInnovationTasks::supportedTasks($feature) as $task) {
                $catalog[$task['id']] = $task;
            }
            $task = $catalog[(string) $active['id']] ?? null;
            if ($task === null || $task['action'] !== 'sinkGold') {
                throw new InvalidArgumentException('This is not a market coin-sink task.');
            }
            $capacity = self::storageCapacity($feature, $state);
            $produce = max(0, (int) $state['orderboard_task_points']);
            if ($produce + $task['points'] > $capacity) {
                throw new InvalidArgumentException('Market produce storage is full.');
            }
            if (! \UserResources::removeGold($uid, $task['total'])) {
                throw new InvalidArgumentException('Insufficient coins for market task.');
            }
            $state['orderboard_task_points'] = $produce + $task['points'];
            $state['saved_active_tasks'][$slot] = ['orderboard_progress' => 0];
        });
    }

    public static function sinkBushel(string $uid, string $feature, int $slot): array
    {
        return self::mutate($uid, $feature, static function (array &$state) use ($uid, $feature, $slot): void {
            $active = $state['saved_active_tasks'][$slot] ?? null;
            if (! is_array($active)) {
                throw new InvalidArgumentException('Market task slot is invalid.');
            }
            if (! isset($active['id'])) {
                return; // Retried sink; never remove bushels twice.
            }
            $catalog = [];
            foreach (MarketInnovationTasks::supportedTasks($feature) as $task) {
                $catalog[$task['id']] = $task;
            }
            $task = $catalog[(string) $active['id']] ?? null;
            if ($task === null || $task['action'] !== 'sinkBushel') {
                throw new InvalidArgumentException('This is not a market bushel-sink task.');
            }
            $capacity = self::storageCapacity($feature, $state);
            $produce = max(0, (int) $state['orderboard_task_points']);
            if ($produce + $task['points'] > $capacity) {
                throw new InvalidArgumentException('Market produce storage is full.');
            }
            if (! \removeBushelsFromInventory($uid, $task['code'], $task['total'])) {
                throw new InvalidArgumentException('Insufficient bushels for market task.');
            }
            $state['orderboard_task_points'] = $produce + $task['points'];
            $state['saved_active_tasks'][$slot] = ['orderboard_progress' => 0];
        });
    }

    public static function newTask(string $uid, string $feature, int $slot): array
    {
        return self::mutate($uid, $feature, static function (array &$state) use ($feature, $slot): void {
            if (! array_key_exists($slot, $state['saved_active_tasks'])) {
                throw new InvalidArgumentException('Market task slot is invalid.');
            }
            $active = $state['saved_active_tasks'][$slot];
            if (is_array($active) && isset($active['id'])) {
                throw new InvalidArgumentException('Current market task must be redeemed first.');
            }
            $dumpedAt = (int) ($state['saved_dumped_timestamp'][$slot] ?? 0);
            if ($dumpedAt > 0 && time() - $dumpedAt < 900) {
                throw new InvalidArgumentException('Market task replacement is still cooling down.');
            }

            $tasks = MarketInnovationTasks::assignableTasks($feature);
            $cursor = max(0, (int) $state['task_cursor']);
            $task = $tasks[$cursor % count($tasks)];
            $state['saved_active_tasks'][$slot] = ['id' => $task['id'], 'orderboard_progress' => 0];
            $state['task_cursor'] = $cursor + 1;
            $state['saved_dumped_timestamp'][$slot] = 0;
        });
    }

    public static function dumpTask(string $uid, string $feature, int $slot): array
    {
        return self::mutate($uid, $feature, static function (array &$state) use ($slot): void {
            $active = $state['saved_active_tasks'][$slot] ?? null;
            if (! is_array($active)) {
                throw new InvalidArgumentException('Market task slot is invalid.');
            }
            if (! isset($active['id'])) {
                return; // Retried dump; do not extend the cooldown.
            }
            $state['saved_active_tasks'][$slot] = ['orderboard_progress' => 0];
            $state['saved_dumped_timestamp'][$slot] = time();
        });
    }

    public static function skipDumpedTask(string $uid, string $feature, int $slot): array
    {
        return self::mutate($uid, $feature, static function (array &$state) use ($uid, $slot): void {
            $active = $state['saved_active_tasks'][$slot] ?? null;
            if (! is_array($active)) {
                throw new InvalidArgumentException('Market task slot is invalid.');
            }
            $dumpedAt = (int) ($state['saved_dumped_timestamp'][$slot] ?? 0);
            if ($dumpedAt === 0) {
                return; // An acknowledged retry has no further cash cost.
            }
            if (isset($active['id'])) {
                throw new InvalidArgumentException('Market task is not dumped.');
            }
            if (! \UserResources::removeCash($uid, 1)) {
                throw new InvalidArgumentException('Insufficient cash to skip market cooldown.');
            }
            $state['saved_dumped_timestamp'][$slot] = 0;
        });
    }

    /**
     * Sell exactly the quantity the player confirmed. Quantity and price are
     * validated against durable state, and the sale key makes retries inert.
     *
     * @return array{newQty: int, gold_earned: int, featureData: array}
     */
    public static function sell(string $uid, string $feature, int $quantity, string $saleKey): array
    {
        if ($quantity < 1 || $quantity > 400 || $saleKey === '' || strlen($saleKey) > 100) {
            throw new InvalidArgumentException('Invalid market sale.');
        }

        // Close any previous week before taking produce from the account.
        self::startNewDay($uid, $feature);

        $earned = 0;
        $state = self::mutate($uid, $feature, static function (array &$state) use ($uid, $feature, $quantity, $saleKey, &$earned): void {
            $priorSale = DB::table('market_innovation_sales')
                ->where('uid', $uid)
                ->where('feature', $feature)
                ->where('sale_key', $saleKey)
                ->first(['coins']);
            if ($priorSale !== null) {
                $earned = (int) $priorSale->coins;

                return;
            }

            $produce = max(0, (int) $state['orderboard_task_points']);
            if ($quantity > $produce) {
                throw new InvalidArgumentException('Insufficient market produce.');
            }
            $price = (int) $state['sell_price'];
            if ($price < 1 || $price > 1000) {
                throw new InvalidArgumentException('Market price is unavailable.');
            }

            $earned = $quantity * $price;
            $newWeeklyScore = MarketCompetition::recordSale($uid, $feature, $earned, $saleKey);
            $state['orderboard_task_points'] = $produce - $quantity;
            $state['total_gold_coins'] = max(0, (int) $state['total_gold_coins']) + $earned;
            $state['weekly_score'] = $newWeeklyScore;
            $state['score_week_start'] = MarketCompetition::weekStart();

            $today = strtotime(gmdate('Y-m-d 00:00:00').' UTC');
            if ((int) $state['active_day_timestamp'] !== $today) {
                $state['daily_points'] = 0;
                $state['daily_reward_granted'] = false;
                $state['active_day_timestamp'] = $today;
            }
            $state['daily_points'] = max(0, (int) $state['daily_points']) + $earned;
        });

        return [
            'newQty' => (int) $state['orderboard_task_points'],
            'gold_earned' => $earned,
            'featureData' => $state,
        ];
    }
}
