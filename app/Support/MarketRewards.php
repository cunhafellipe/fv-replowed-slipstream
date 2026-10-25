<?php

namespace App\Support;

use App\Models\Item;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/** Claim the completed week's archived prize exactly once per account. */
final class MarketRewards
{
    /** Archived final-building thresholds from the two Flash managers. */
    private const FINAL_BUILDINGS = [
        MarketCompetition::FARMERS => [
            50000 => 'xfs_streetvendorshop',
            200000 => 'xfs_valleymarketshop',
            500000 => 'xfs_valleygroceryshop',
            1000000 => 'xfs_farmfreshsupermarket',
        ],
        MarketCompetition::TROPICAL => [
            50000 => 'xah_pineappledrinkhut',
            300000 => 'xah_fruitdrinkshop',
            800000 => 'xah_freshsummerbar',
            1500000 => 'xah_islandjuicemarket',
        ],
    ];

    /** Archived per-building 24-hour bonus rotation from the Flash managers. */
    private const BONUS_HARVEST = [
        MarketCompetition::FARMERS => [
            'xfs_streetvendorshop' => ['fuel1', 'vehiclepart', 'pkg_xfs_treasureparts', 'fawn_feed', 'animalfeedtrough_feed', 'consume_arborists', 'consume_farm_hands', 'brick', 'nail', 'woodenboard'],
            'xfs_valleymarketshop' => ['consume_unwither', 'consume_organic_fertilizer', 'fuel1', 'vehiclepart', 'pkg_xfs_treasureparts', 'fawn_feed', 'animalfeedtrough_feed', 'consume_arborists', 'consume_farm_hands', 'brick', 'nail', 'woodenboard'],
            'xfs_valleygroceryshop' => ['consume_instagrow', 'pkg_xfs_bigbuildable_pack', 'consume_turbo_charger', 'consume_unwither', 'consume_organic_fertilizer', 'fuel1', 'vehiclepart', 'pkg_xfs_treasureparts', 'fawn_feed', 'animalfeedtrough_feed', 'consume_arborists', 'consume_farm_hands'],
            'xfs_farmfreshsupermarket' => ['consume_instagrow', 'pkg_xfs_bigbuildable_pack', 'consume_turbo_charger', 'consume_unwither', 'consume_organic_fertilizer', 'fuel1', 'vehiclepart', 'pkg_xfs_treasureparts', 'fawn_feed', 'animalfeedtrough_feed', 'consume_arborists', 'consume_farm_hands'],
        ],
        MarketCompetition::TROPICAL => [
            'xah_pineappledrinkhut' => ['fuel1', 'vehiclepart', 'pkg_xah_treasureparts', 'fawn_feed', 'animalfeedtrough_feed', 'consume_arborists', 'consume_farm_hands', 'brick', 'nail', 'woodenboard'],
            'xah_fruitdrinkshop' => ['consume_unwither', 'consume_organic_fertilizer', 'fuel1', 'vehiclepart', 'pkg_xah_treasureparts', 'fawn_feed', 'animalfeedtrough_feed', 'consume_arborists', 'consume_farm_hands', 'brick', 'nail', 'woodenboard'],
            'xah_freshsummerbar' => ['consume_instagrow', 'pkg_xah_bigbuildableparts', 'consume_turbo_charger', 'consume_unwither', 'consume_organic_fertilizer', 'fuel1', 'vehiclepart', 'pkg_xah_treasureparts', 'fawn_feed', 'animalfeedtrough_feed', 'consume_arborists', 'consume_farm_hands'],
            'xah_islandjuicemarket' => ['consume_instagrow', 'pkg_xah_bigbuildableparts', 'consume_turbo_charger', 'consume_unwither', 'consume_organic_fertilizer', 'fuel1', 'vehiclepart', 'pkg_xah_treasureparts', 'fawn_feed', 'animalfeedtrough_feed', 'consume_arborists', 'consume_farm_hands'],
        ],
    ];

    private const DAILY_REWARDS = [
        MarketCompetition::FARMERS => [
            'rooster_xfs_icecream', 'pkg_xfs_buildingessentials', 'fox_xfs_scarecrow',
            'pkg_xfs_buildingessentials', 'sheep_xfs_cottonfarmer', 'pkg_xfs_treasureessentials',
            'camel_xfs_renaissance', 'pkg_xfs_vehicleessentials', 'bear_xfs_vintagevendor',
            'pkg_xfs_buildingessentials', 'pig_xfs_40sfarmer', 'pkg_xfs_farmessentials',
            'pkg_xfs_buildingessentials', 'cat_xfs_masonjar', 'pkg_xfs_treasureessentials',
            'pkg_xfs_vehicleessentials', 'kangaroo_xfs_preserver', 'pkg_xfs_buildingessentials',
            'pkg_xfs_farmessentials', 'monkey_xfs_rootcrop', 'pkg_xfs_buildingessentials',
            'pkg_xfs_treasureessentials', 'llama_xfs_wheatfarm', 'pkg_xfs_vehicleessentials',
            'pkg_xfs_buildingessentials', 'bear_xfs_poultryfarm', 'pkg_xfs_farmessentials',
            'pkg_xfs_buildingessentials', 'gorilla_xfs_cabbagefarm', 'pkg_xfs_treasureessentials',
            'pkg_xfs_vehicleessentials', 'penguin_xfs_irrigation', 'pkg_xfs_buildingessentials',
            'pkg_xfs_farmessentials', 'pony_xfs_applepicker',
        ],
        MarketCompetition::TROPICAL => [
            'goat_xah_hawaiishirt', 'pkg_xah_buildingessentials', 'chicken_xah_lighthouse',
            'pkg_xah_buildingessentials', 'dog_xah_seashell', 'pkg_xah_treasureessentials',
            'sheep_xah_sarong', 'pkg_xah_vehicleessentials', 'chipmunk_xah_palm',
            'pkg_xah_buildingessentials', 'panda_xah_sundress', 'pkg_xah_farmessentials',
            'pkg_xah_buildingessentials', 'cat_xah_lifeguard', 'pkg_xah_treasureessentials',
            'pkg_xah_vehicleessentials', 'turtle_xah_hulaflower', 'pkg_xah_buildingessentials',
            'pkg_xah_farmessentials', 'duck_xah_hooping', 'pkg_xah_buildingessentials',
            'pkg_xah_treasureessentials', 'monkey_xah_beachball', 'pkg_xah_vehicleessentials',
            'pkg_xah_buildingessentials', 'whale_xah_deepsea', 'pkg_xah_farmessentials',
            'pkg_xah_buildingessentials', 'elephant_xah_bananalover', 'pkg_xah_treasureessentials',
            'pkg_xah_vehicleessentials', 'penguin_xah_summer', 'pkg_xah_buildingessentials',
            'pkg_xah_farmessentials', 'rabbit_xah_tropical', 'llama_xah_beachdrummer',
            'pkg_xah_buildingessentials', 'pkg_xah_treasureessentials',
            'horse_xah_tropicbeauty', 'pkg_xah_vehicleessentials',
            'dog_xah_sandwaypoodle', 'rhino_xah_sailready',
        ],
    ];

    public static function dailyRewardName(string $feature, ?DateTimeInterface $at = null): ?string
    {
        MarketCompetition::assertFeature($feature);
        $today = $at === null
            ? new DateTimeImmutable('now', new DateTimeZone('UTC'))
            : DateTimeImmutable::createFromInterface($at)->setTimezone(new DateTimeZone('UTC'));
        $season = MarketSeason::seasonStart($feature, $today);
        $index = (int) $season->diff($today)->format('%a');

        return self::DAILY_REWARDS[$feature][$index] ?? null;
    }

    public static function finalRewardName(string $feature, int $totalCoins): ?string
    {
        MarketCompetition::assertFeature($feature);
        $name = null;
        foreach (self::FINAL_BUILDINGS[$feature] as $minimum => $building) {
            if ($totalCoins >= $minimum) {
                $name = $building;
            }
        }

        return $name;
    }

    public static function bonusHarvestRewardName(string $feature, string $building, int $harvestCount): string
    {
        MarketCompetition::assertFeature($feature);
        $rotation = self::BONUS_HARVEST[$feature][$building] ?? null;
        if ($rotation === null || $harvestCount < 0) {
            throw new InvalidArgumentException('Unknown market bonus harvest.');
        }

        return $rotation[$harvestCount % count($rotation)];
    }

    public static function grantNamedGift(string $uid, string $name, int $multiplier = 1): void
    {
        if ($multiplier < 1 || $multiplier > 2) {
            throw new InvalidArgumentException('Invalid market gift quantity.');
        }
        foreach (self::expandGift($name) as $code => $quantity) {
            if (! \addGiftboxItemLocked($uid, $code, $quantity * $multiplier, $uid)) {
                throw new RuntimeException('Could not grant the market gift.');
            }
        }
    }

    public static function claimCompletedWeeks(string $uid, string $feature, ?DateTimeInterface $at = null): ?array
    {
        MarketCompetition::assertFeature($feature);
        $completedWeeks = DB::table('market_innovation_scores')
            ->where('uid', $uid)
            ->where('feature', $feature)
            ->where('score', '>', 0)
            ->where('week_start', '<', MarketCompetition::weekStart($at))
            ->orderBy('week_start')
            ->pluck('week_start')
            ->all();
        if ($completedWeeks === []) {
            return null;
        }

        $awards = DB::transaction(static function () use ($uid, $feature, $completedWeeks): array {
            if (! DB::table('users')->where('uid', $uid)->lockForUpdate()->exists()) {
                throw new RuntimeException('Market prize player does not exist.');
            }

            $awards = [];
            foreach ($completedWeeks as $weekStart) {
                if (DB::table('market_innovation_awards')
                    ->where('uid', $uid)->where('feature', $feature)->where('week_start', $weekStart)->exists()) {
                    continue;
                }

                $week = new DateTimeImmutable((string) $weekStart, new DateTimeZone('UTC'));
                $rank = (int) MarketCompetition::standings($uid, $feature, $week)['userData']['rank'];
                if ($rank < 1 || $rank > 50) {
                    continue;
                }

                $season = MarketSeason::seasonStart($feature, $week);
                $weekNumber = intdiv((int) $season->diff($week)->format('%a'), 7) + 1;
                $tier = match (true) {
                    $rank === 1 => 1,
                    $rank === 2 => 2,
                    $rank === 3 => 3,
                    $rank <= 10 => 4,
                    $rank <= 20 => 11,
                    default => 21,
                };
                $prefix = $feature === MarketCompetition::FARMERS
                    ? 'farmersMarket2020Innovation'
                    : 'TropicalMarketInnovation';
                $gift = "pkg_{$prefix}Week{$weekNumber}_rank{$tier}";
                $leaderboard = ($feature === MarketCompetition::FARMERS
                    ? 'FarmersMarketInnovationWeek'
                    : 'TropicalMarketInnovationWeek').$weekNumber;

                self::grantNamedGift($uid, $gift);
                DB::table('market_innovation_awards')->insert([
                    'uid' => $uid,
                    'feature' => $feature,
                    'week_start' => $weekStart,
                    'rank' => $rank,
                    'gift_name' => $gift,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $awards[] = ['gift' => $gift, 'leaderboard' => $leaderboard];
            }

            return $awards;
        });

        if ($awards === []) {
            return null;
        }

        return [
            'gift' => array_column($awards, 'gift'),
            'giftLeaderboardName' => $awards[array_key_last($awards)]['leaderboard'],
        ];
    }

    /** Backwards-compatible name for callers of the first implementation. */
    public static function claimPreviousWeek(string $uid, string $feature, ?DateTimeInterface $at = null): ?array
    {
        return self::claimCompletedWeeks($uid, $feature, $at);
    }

    /** @return array<string, int> Item code => quantity */
    private static function expandGift(string $name, int $depth = 0): array
    {
        if ($depth > 4) {
            throw new RuntimeException('Market gift package is too deeply nested.');
        }
        $item = Item::findByName($name);
        if (! is_array($item)) {
            throw new RuntimeException("Market prize item {$name} is missing.");
        }

        $packages = $item['itemPackage'] ?? [];
        if (is_object($packages)) {
            $packages = [$packages];
        }
        if (is_array($packages) && $packages !== []) {
            $contents = [];
            foreach ($packages as $package) {
                $childName = is_object($package) ? ($package->value ?? null) : ($package['value'] ?? null);
                $amount = (int) (is_object($package) ? ($package->amount ?? 0) : ($package['amount'] ?? 0));
                if (! is_string($childName) || $childName === '' || $amount < 1 || $amount > 100) {
                    throw new RuntimeException("Market prize package {$name} is malformed.");
                }
                foreach (self::expandGift($childName, $depth + 1) as $code => $quantity) {
                    $contents[$code] = ($contents[$code] ?? 0) + $quantity * $amount;
                }
            }

            return $contents;
        }

        $code = $item['code'] ?? null;
        if (! is_string($code) || $code === '') {
            throw new RuntimeException("Market prize item {$name} has no code.");
        }

        return [$code => 1];
    }
}
