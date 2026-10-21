<?php

namespace App\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

/** Re-date the original market CMS in memory; preserve every prize and asset. */
final class MarketSeason
{
    private const ANCHOR = '2026-10-05';

    private const WEEKS = [
        MarketCompetition::FARMERS => 5,
        MarketCompetition::TROPICAL => 6,
    ];

    public static function weekCount(string $feature): int
    {
        MarketCompetition::assertFeature($feature);

        return self::WEEKS[$feature];
    }

    public static function seasonStart(string $feature, ?DateTimeInterface $at = null): DateTimeImmutable
    {
        MarketCompetition::assertFeature($feature);
        $utc = new DateTimeZone('UTC');
        $anchor = new DateTimeImmutable(self::ANCHOR, $utc);
        $today = $at === null ? new DateTimeImmutable('now', $utc) : DateTimeImmutable::createFromInterface($at)->setTimezone($utc);
        $elapsedDays = max(0, (int) $anchor->diff($today)->format('%r%a'));
        $period = 7 * self::WEEKS[$feature];

        return $anchor->modify('+'.(intdiv($elapsedDays, $period) * $period).' days');
    }

    public static function cacheKey(?DateTimeInterface $at = null): string
    {
        return MarketCompetition::weekStart($at);
    }

    public static function cms(?DateTimeInterface $at = null): string
    {
        $source = file_get_contents(public_path('farmville/xml/gz/v855038/gameSettingsCMS.xml.gz'));
        $plain = $source === false ? false : gzuncompress($source);
        if ($plain === false) {
            throw new RuntimeException('Archived market CMS is unavailable.');
        }

        $document = new DOMDocument();
        $document->preserveWhiteSpace = true;
        if (! $document->loadXML($plain, LIBXML_NONET)) {
            throw new RuntimeException('Archived market CMS is invalid.');
        }
        $xpath = new DOMXPath($document);

        foreach (self::WEEKS as $feature => $weekCount) {
            $nodes = $xpath->query("//featureConfig/feature[@name='{$feature}']");
            if ($nodes->length !== 1) {
                throw new RuntimeException("Market CMS feature {$feature} is missing.");
            }
            $featureNode = $nodes->item(0);
            if (! MarketCompetition::isEnabled($feature)) {
                $featureNode->parentNode?->removeChild($featureNode);
                continue;
            }

            $season = self::seasonStart($feature, $at);
            foreach (['weeks/week', 'leaderboardConfig/leaderboard'] as $path) {
                $weeks = $xpath->query($path, $featureNode);
                if ($weeks->length !== $weekCount) {
                    throw new RuntimeException("Unexpected week count for {$feature}.");
                }
                foreach ($weeks as $index => $week) {
                    if (! $week instanceof DOMElement) {
                        continue;
                    }
                    $week->setAttribute('start', $season->modify('+'.($index * 7).' days')->format('m/d/Y'));
                    $week->setAttribute('end', $season->modify('+'.(($index + 1) * 7).' days')->format('m/d/Y'));
                }
            }
            $end = $xpath->query('listEndDate', $featureNode)->item(0);
            if ($end === null) {
                throw new RuntimeException("Market CMS end date for {$feature} is missing.");
            }
            $end->textContent = $season->modify('+'.($weekCount * 7).' days')->format('m/d/Y');
        }

        $xml = $document->saveXML();
        if ($xml === false) {
            throw new RuntimeException('Could not serialize market CMS.');
        }

        return gzcompress($xml, 6);
    }
}
