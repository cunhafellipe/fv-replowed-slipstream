<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use RuntimeException;

/** The server-observable subset of the original order-board catalog. */
final class MarketInnovationTasks
{
    private static array $catalog = [];

    private const SUPPORTED_ACTIONS = [
        'harvestByCode',
        'harvestByCategory',
        'makeRecipeByCode',
        'expandStorageBuildingToLevelByCode',
        'completeFeatureCraftingNPC',
        'useItemByCode',
        'sinkGold',
        'sinkBushel',
    ];

    /** @return array<int, array{id: string, action: string, code: string, total: int, points: int, cash: int}> */
    public static function supportedTasks(string $feature): array
    {
        MarketCompetition::assertFeature($feature);
        if (isset(self::$catalog[$feature])) {
            return self::$catalog[$feature];
        }

        return self::$catalog[$feature] = Cache::remember(
            'market:supported-tasks:v855038:v5:'.$feature,
            86400,
            static fn (): array => self::readSupportedTasks($feature),
        );
    }

    /** @return array<int, array{id: string, action: string, code: string, total: int, points: int, cash: int}> */
    public static function harvestTasks(string $feature): array
    {
        return array_values(array_filter(
            self::supportedTasks($feature),
            static fn (array $task): bool => $task['action'] === 'harvestByCode',
        ));
    }

    /** Only tasks whose requirements are reachable in this server's world set. */
    public static function assignableTasks(string $feature): array
    {
        $tasks = self::supportedTasks($feature);
        if ($feature !== MarketCompetition::FARMERS) {
            return $tasks;
        }

        $homeSeeds = MarketGameSettings::homeSeedCodes();

        return array_values(array_filter($tasks, static fn (array $task): bool =>
            $task['action'] === 'harvestByCode' && in_array($task['code'], $homeSeeds, true)
        ));
    }

    private static function readSupportedTasks(string $feature): array
    {
        $xml = MarketGameSettings::xml();

        $settings = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
        if ($settings === false) {
            throw new RuntimeException('Archived market task catalog is invalid.');
        }

        $boards = $settings->xpath("//orderBoards/orderBoard[@name='{$feature}']");
        if (! is_array($boards) || count($boards) !== 1) {
            throw new RuntimeException('Archived market order board was not found.');
        }

        $tasks = [];
        foreach ($boards[0]->orderBoardTasks->difficulty as $difficulty) {
            foreach ($difficulty->tasks as $group) {
                foreach ($group->task as $task) {
                    $action = (string) $task['action'];
                    if (! in_array($action, self::SUPPORTED_ACTIONS, true)) {
                        continue;
                    }
                    $id = (string) $task['id'];
                    $code = (string) $task['type'];
                    $total = (int) $task['total'];
                    $points = (int) $task['points'];
                    $cash = (int) $task['cashValue'];
                    if ($id !== '' && $code !== '' && $total > 0 && $points > 0 && $cash > 0) {
                        $tasks[] = compact('id', 'action', 'code', 'total', 'points', 'cash');
                    }
                }
            }
        }

        if (count($tasks) < 3) {
            throw new RuntimeException('Archived market order board has too few supported tasks.');
        }

        return $tasks;
    }
}
