<?php

namespace App\Console\Commands;

use App\Models\WorldObject;
use App\Support\ConstructionMaterials;
use Illuminate\Console\Command;

class RepairCompletedConstruction extends Command
{
    protected $signature = 'world:repair-completed-construction
                            {uid : Farmer UID}
                            {worldType : Farm world type, such as farm}
                            {objectId : World object ID}
                            {--apply : Complete the verified building and award its completion XP}';

    protected $description = 'Preview or repair one construction site stuck after collecting every part';

    public function handle(): int
    {
        $uid = (string) $this->argument('uid');
        $worldType = (string) $this->argument('worldType');
        $objectId = (int) $this->argument('objectId');
        $object = WorldObject::query()
            ->with('world')
            ->where('object_id', $objectId)
            ->where('deleted', false)
            ->whereHas('world', static fn ($query) => $query
                ->where('uid', $uid)
                ->where('type', $worldType))
            ->first();

        if ($object === null || $object->world === null
            || !str_ends_with((string) $object->class_name, 'ConstructionBuilding')) {
            $this->error('No active construction site matches that farmer and object ID.');
            return self::FAILURE;
        }

        if (!defined('AMFPHP_ROOTPATH')) {
            define('AMFPHP_ROOTPATH', public_path('farmville/flashservices/amfphp/'));
        }
        require_once AMFPHP_ROOTPATH . 'Helpers/player.php';

        $item = getItemByName((string) $object->item_name, 'db');
        $finishedName = is_array($item) ? ($item['finishedName'] ?? null) : null;
        $materials = is_array($item)
            ? ConstructionMaterials::status($item, is_array($object->contents) ? $object->contents : [])
            : null;
        if (!is_string($finishedName) || $finishedName === '' || $materials === null) {
            $this->error('The item catalogue or construction material definition is incomplete.');
            return self::FAILURE;
        }

        $this->line(sprintf(
            'uid=%s world=%s object=%d item=%s -> %s; missing=%s',
            $uid,
            $object->world->type,
            $objectId,
            $object->item_name,
            $finishedName,
            json_encode(array_filter($materials['remaining'])),
        ));

        if (!$materials['complete']) {
            $this->warn('Construction is not complete; no change made.');
            return self::FAILURE;
        }

        if (!$this->option('apply')) {
            $this->info('Dry run only. Re-run with --apply to finish this building.');
            return self::SUCCESS;
        }

        if (!(new \Player($uid))->repairCompletedConstruction((string) $object->world->type, $objectId)) {
            $this->error('Construction changed or failed validation; no repair was applied.');
            return self::FAILURE;
        }

        $this->info('Construction completed. The farmer should reload the farm.');
        return self::SUCCESS;
    }
}
