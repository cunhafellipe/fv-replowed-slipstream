<?php

namespace App\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Server-authoritative market score ledger. Only an accepted produce sale may
 * call recordSale; the Flash feature-options snapshot is never a score source.
 */
final class MarketCompetition
{
    public const FARMERS = 'FarmersMarket2020Innovation';

    public const TROPICAL = 'TropicalMarketInnovation';

    public static function assertFeature(string $feature): void
    {
        if (! in_array($feature, [self::FARMERS, self::TROPICAL], true)) {
            throw new InvalidArgumentException('Unknown market innovation feature.');
        }
    }

    public static function isEnabled(string $feature): bool
    {
        self::assertFeature($feature);

        return (bool) config('market_innovations.enabled')
            && (bool) config($feature === self::FARMERS
                ? 'market_innovations.farmers_enabled'
                : 'market_innovations.tropical_enabled', true);
    }

    /** @return list<string> */
    public static function enabledFeatures(): array
    {
        return array_values(array_filter(
            [self::FARMERS, self::TROPICAL],
            static fn (string $feature): bool => self::isEnabled($feature),
        ));
    }

    public static function weekStart(?DateTimeInterface $at = null): string
    {
        $utc = new DateTimeZone('UTC');
        $date = $at === null
            ? new DateTimeImmutable('now', $utc)
            : DateTimeImmutable::createFromInterface($at)->setTimezone($utc);

        return $date->modify('monday this week')->format('Y-m-d');
    }

    /** Record a confirmed sale, serialized by the owning account row. */
    public static function recordSale(string $uid, string $feature, int $coins, string $saleKey, ?DateTimeInterface $at = null): int
    {
        self::assertFeature($feature);
        if (! self::isEnabled($feature)) {
            throw new InvalidArgumentException('The market innovation is not currently active.');
        }
        if ($coins <= 0 || $coins > 10_000_000) {
            throw new InvalidArgumentException('Market sale score is outside the accepted range.');
        }
        if ($saleKey === '' || strlen($saleKey) > 100) {
            throw new InvalidArgumentException('Market sale key is invalid.');
        }

        $weekStart = self::weekStart($at);
        $timestamp = ($at === null
            ? new DateTimeImmutable('now', new DateTimeZone('UTC'))
            : DateTimeImmutable::createFromInterface($at)->setTimezone(new DateTimeZone('UTC'))
        )->format('Y-m-d H:i:s');

        return DB::transaction(static function () use ($uid, $feature, $coins, $saleKey, $weekStart, $timestamp): int {
            // This also serializes a player's sales across simultaneous farms.
            if (! DB::table('users')->where('uid', $uid)->lockForUpdate()->exists()) {
                throw new InvalidArgumentException('Market player does not exist.');
            }

            $inserted = DB::table('market_innovation_sales')->insertOrIgnore([
                'uid' => $uid,
                'feature' => $feature,
                'sale_key' => $saleKey,
                'week_start' => $weekStart,
                'coins' => $coins,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
            if ($inserted === 0) {
                $sale = DB::table('market_innovation_sales')
                    ->where('uid', $uid)
                    ->where('feature', $feature)
                    ->where('sale_key', $saleKey)
                    ->first(['coins', 'week_start']);
                if (! $sale || (int) $sale->coins !== $coins) {
                    throw new InvalidArgumentException('Market sale key was reused for a different sale.');
                }

                return (int) DB::table('market_innovation_scores')
                    ->where('uid', $uid)
                    ->where('feature', $feature)
                    ->where('week_start', $sale->week_start)
                    ->value('score');
            }

            DB::table('market_innovation_scores')->insertOrIgnore([
                'uid' => $uid,
                'feature' => $feature,
                'week_start' => $weekStart,
                'score' => 0,
                'first_earned_at' => $timestamp,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

            DB::table('market_innovation_scores')
                ->where('uid', $uid)
                ->where('feature', $feature)
                ->where('week_start', $weekStart)
                ->increment('score', $coins, ['updated_at' => $timestamp]);

            return (int) DB::table('market_innovation_scores')
                ->where('uid', $uid)
                ->where('feature', $feature)
                ->where('week_start', $weekStart)
                ->value('score');
        });
    }

    /** @return array{slotData: array<int, array<string, mixed>>, userData: array<string, mixed>} */
    public static function standings(string $uid, string $feature, ?DateTimeInterface $at = null): array
    {
        self::assertFeature($feature);
        $weekStart = self::weekStart($at);

        $rows = DB::table('market_innovation_scores as scores')
            ->leftJoin('users', 'users.uid', '=', 'scores.uid')
            ->leftJoin('usermeta', 'usermeta.uid', '=', 'scores.uid')
            ->where('scores.feature', $feature)
            ->where('scores.week_start', $weekStart)
            ->where('scores.score', '>', 0)
            ->orderByDesc('scores.score')
            ->orderBy('scores.first_earned_at')
            ->orderBy('scores.uid')
            ->select('scores.uid', 'scores.score', 'users.name', 'usermeta.profile_picture')
            ->get();

        $slots = [];
        $userData = ['zid' => $uid, 'rank' => 0, 'value' => 0];
        foreach ($rows as $index => $row) {
            $entry = [
                'zid' => (string) $row->uid,
                'rank' => $index + 1,
                'value' => (int) $row->score,
                'name' => (string) ($row->name ?: 'Farmer'),
                'icon' => (string) ($row->profile_picture ?: ''),
            ];
            if ($index < 6) {
                $slots[] = $entry;
            }
            if ((string) $row->uid === $uid) {
                $userData = $entry;
            }
        }

        return ['slotData' => $slots, 'userData' => $userData];
    }
}
