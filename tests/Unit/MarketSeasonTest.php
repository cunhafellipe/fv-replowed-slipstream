<?php

use App\Support\MarketCompetition;
use App\Support\MarketSeason;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    config()->set('market_innovations.enabled', true);
    config()->set('market_innovations.farmers_enabled', true);
    config()->set('market_innovations.tropical_enabled', true);
});

it('redates both archived market seasons without changing their prizes', function () {
    $at = new DateTimeImmutable('2026-10-12 12:00:00 UTC');
    $xml = gzuncompress(MarketSeason::cms($at));
    expect($xml)->not->toBeFalse();

    $document = new DOMDocument();
    expect($document->loadXML($xml, LIBXML_NONET))->toBeTrue();
    $xpath = new DOMXPath($document);

    foreach ([
        MarketCompetition::FARMERS => ['01/20/2020', '10/05/2026', '11/09/2026', 5],
        MarketCompetition::TROPICAL => ['08/31/2020', '10/05/2026', '11/16/2026', 6],
    ] as $feature => [$original, $start, $end, $count]) {
        $root = $xpath->query("//featureConfig/feature[@name='{$feature}']")->item(0);
        $weeks = $xpath->query('weeks/week', $root);
        $boards = $xpath->query('leaderboardConfig/leaderboard', $root);
        expect($weeks->length)->toBe($count)
            ->and($boards->length)->toBe($count)
            ->and($weeks->item(0)->getAttribute('start'))->toBe($start)
            ->and($weeks->item($count - 1)->getAttribute('end'))->toBe($end)
            ->and($boards->item(0)->getAttribute('start'))->toBe($start)
            ->and($boards->item($count - 1)->getAttribute('end'))->toBe($end)
            ->and($xpath->query('listEndDate', $root)->item(0)->textContent)->toBe($end)
            ->and($root->textContent)->not->toContain($original)
            ->and($xpath->query('leaderboardConfig/leaderboard/place', $root)->length)->toBe($count * 6);
    }
});

it('cycles the five-week and six-week events independently', function () {
    $at = new DateTimeImmutable('2026-11-09 12:00:00 UTC');
    expect(MarketSeason::seasonStart(MarketCompetition::FARMERS, $at)->format('Y-m-d'))->toBe('2026-11-09')
        ->and(MarketSeason::seasonStart(MarketCompetition::TROPICAL, $at)->format('Y-m-d'))->toBe('2026-10-05')
        ->and(MarketSeason::cacheKey($at))->toBe('2026-11-09');
});
