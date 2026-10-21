<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

/** Keep the Flash task definitions and server task catalog in sync. */
final class MarketGameSettings
{
    // These seeds are available on the free home farm at levels 1-7.
    private const HOME_SEED_CODES = ['WH', 'SY', 'SB', 'PU', 'RC'];

    public static function homeSeedCodes(): array
    {
        return self::HOME_SEED_CODES;
    }

    public static function xml(): string
    {
        $source = file_get_contents(public_path('farmville/xml/gz/v855038/gameSettings.xml.gz'));
        $plain = $source === false ? false : gzuncompress($source);
        if ($plain === false) {
            throw new RuntimeException('Archived game settings are unavailable.');
        }

        $document = new DOMDocument();
        $document->preserveWhiteSpace = true;
        if (! $document->loadXML($plain, LIBXML_NONET)) {
            throw new RuntimeException('Archived game settings are invalid.');
        }

        // The recovered Farmers Market board is from the Harvest Valley event.
        // That world is not enabled here, so its crop tasks must be adapted in
        // the document Flash reads, not only in the server's task selector.
        $tasks = (new DOMXPath($document))->query(
            "//orderBoards/orderBoard[@name='FarmersMarket2020Innovation']"
            ."/orderBoardTasks/difficulty/tasks/task[@action='harvestByCode' and @helper='plant_seed']"
        );
        if ($tasks === false || $tasks->length < 3) {
            throw new RuntimeException('Farmers Market plant tasks are unavailable.');
        }
        foreach ($tasks as $index => $task) {
            if ($task instanceof DOMElement) {
                $task->setAttribute('type', self::HOME_SEED_CODES[$index % count(self::HOME_SEED_CODES)]);
            }
        }

        return $document->saveXML() ?: throw new RuntimeException('Could not serialize game settings.');
    }

    public static function compressedXml(): string
    {
        return gzcompress(self::xml(), 6);
    }
}
