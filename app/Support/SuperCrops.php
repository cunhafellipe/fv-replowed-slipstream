<?php

namespace App\Support;

use App\Models\Item;
use App\Models\PlayerMeta;
use Illuminate\Support\Facades\DB;

/** Server-authoritative Super Crop unlocks and neighbor certifications (FS07). */
final class SuperCrops
{
    public const SLOT_CASH = 3; // Archived gameSettingsCMS.xml FS07 buySlotCash.

    public static function definition(string $cropCode): ?array
    {
        if ($cropCode === '' || strlen($cropCode) > 100) {
            return null;
        }

        $item = Item::findByCode($cropCode);
        if (!is_array($item)) {
            return null;
        }

        $requirements = self::value($item, 'requirements');
        $requirement = self::findRequirement($requirements);
        if ($requirement === null) {
            return null;
        }

        $baseName = (string) self::value($requirement, 'name', '');
        $baseItem = $baseName !== '' ? Item::findByName($baseName) : false;
        $goal = (int) self::value($requirement, 'amount', 0);
        $level = (int) self::value($requirement, 'level', -1);
        if (!is_array($baseItem) || $goal < 1 || $goal > 100 || $level < 0 || $level > 3) {
            return null;
        }

        return [
            'code' => $cropCode,
            'name' => (string) self::value($item, 'name', ''),
            'base_code' => (string) self::value($baseItem, 'code', ''),
            'goal' => $goal,
            'mastery_level' => $level,
        ];
    }

    private static function value(mixed $source, string $key, mixed $default = null): mixed
    {
        return is_array($source) ? ($source[$key] ?? $default)
            : (is_object($source) ? ($source->{$key} ?? $default) : $default);
    }

    private static function findRequirement(mixed $node, int $depth = 0): ?array
    {
        if ($depth > 4 || (!is_array($node) && !is_object($node))) {
            return null;
        }
        $class = self::value($node, 'className', self::value($node, 'class'));
        if (in_array($class, ['SuperCrop', 'SuperCropRequirement'], true)) {
            return (array) $node;
        }
        foreach ((array) $node as $child) {
            $result = self::findRequirement($child, $depth + 1);
            if ($result !== null) {
                return $result;
            }
        }
        return null;
    }

    public static function mastered(string|int $uid, array $definition): bool
    {
        $baseCode = $definition['base_code'];
        if ($baseCode === '') {
            return false;
        }
        $stored = self::metaArray($uid, 'mastery_data');
        $mastery = $stored['mastery'] ?? [];
        return isset($mastery[$baseCode])
            && (int) $mastery[$baseCode] >= $definition['mastery_level'];
    }

    public static function unlocked(string|int $uid, string $cropCode): bool
    {
        return DB::table('super_crop_unlocks')
            ->where('uid', (string) $uid)->where('crop_code', $cropCode)->exists();
    }

    public static function unlockedCodes(string|int $uid): array
    {
        return DB::table('super_crop_unlocks')->where('uid', (string) $uid)
            ->pluck('crop_code')->all();
    }

    /** Ordinary seeds are unchanged; only catalogued FS07 crops need an unlock. */
    public static function canPlant(string|int $uid, string $itemName): bool
    {
        $item = Item::findByName($itemName);
        if (!is_array($item)) {
            return true; // Existing market validation handles unknown items.
        }
        $code = (string) ($item['code'] ?? '');
        $definition = self::definition($code);
        if ($definition === null) {
            return true;
        }

        // SuperCropRequirement.check() also permits a player-owned seed
        // package, even before the normal friend-set unlock is completed.
        $seedPackage = Item::findByName($itemName . '_seedpackage');
        $seedCode = is_array($seedPackage) ? (string) ($seedPackage['code'] ?? '') : '';
        $storage = self::metaArray($uid, 'inventory_storage');
        $hasSeed = $seedCode !== '' && (int) ($storage[$seedCode][0] ?? 0) > 0;

        return $hasSeed || (self::mastered($uid, $definition) && self::unlocked($uid, $code));
    }

    public static function progress(string|int $uid, string $cropCode): array
    {
        $definition = self::definition($cropCode);
        if ($definition === null) {
            return ['error' => 'invalidCrop'];
        }
        if (self::unlocked($uid, $cropCode)) {
            return ['error' => 'alreadyUnlocked'];
        }
        if (!self::mastered($uid, $definition)) {
            return ['error' => 'notMastered'];
        }

        $uids = [];
        $pending = [];
        foreach (DB::table('super_crop_certifications')
            ->where('owner_uid', (string) $uid)->where('crop_code', $cropCode)->get() as $row) {
            if ($row->status === 'accepted') {
                $uids['_' . $row->neighbor_uid] = 1;
            } elseif ($row->status === 'pending') {
                $pending[] = (string) $row->neighbor_uid;
            }
        }

        return ['index' => 0, 'uids' => (object) $uids, 'pending' => $pending,
            'boughtFriendCount' => 0, 'startTime' => time()];
    }

    public static function neighborIds(string|int $uid): array
    {
        $values = self::metaArray($uid, 'current_neighbors');
        return is_array($values) ? array_values(array_unique(array_map('strval',
            array_filter($values, static fn ($id) => is_scalar($id) && ctype_digit((string) $id))))) : [];
    }

    private static function metaArray(string|int $uid, string $key): array
    {
        $raw = PlayerMeta::getValue($uid, $key);
        $value = is_string($raw) ? @unserialize($raw, ['allowed_classes' => false]) : [];
        return is_array($value) ? $value : [];
    }

    public static function request(string|int $uid, string $itemName, array $recipientIds): array
    {
        $item = Item::findByName($itemName);
        $code = is_array($item) ? (string) ($item['code'] ?? '') : '';
        $definition = self::definition($code);
        if ($definition === null || !self::mastered($uid, $definition) || self::unlocked($uid, $code)) {
            return ['error' => 'invalidCrop'];
        }

        $neighbors = array_flip(self::neighborIds($uid));
        $recipients = [];
        foreach ($recipientIds as $recipientId) {
            if (!is_scalar($recipientId)) {
                return ['error' => 'invalidRecipient'];
            }
            $recipients[] = (string) $recipientId;
        }
        $recipients = array_values(array_unique($recipients));
        if (count($recipients) > 50) {
            return ['error' => 'tooManyRecipients'];
        }
        foreach ($recipients as $recipient) {
            if (!ctype_digit($recipient) || $recipient === (string) $uid
                || !isset($neighbors[$recipient])) {
                return ['error' => 'invalidRecipient'];
            }
        }

        $requested = DB::transaction(function () use ($uid, $code, $definition, $recipients): int {
            self::lockOwner($uid);
            if (self::unlocked($uid, $code)) {
                return 0;
            }

            $counts = DB::table('super_crop_certifications')->where('owner_uid', (string) $uid)
                ->where('crop_code', $code)->select('status', DB::raw('COUNT(*) as total'))
                ->groupBy('status')->pluck('total', 'status');
            $slotsLeft = max(0, $definition['goal'] - (int) ($counts['accepted'] ?? 0)
                - (int) ($counts['pending'] ?? 0));
            if ($slotsLeft === 0 || $recipients === []) {
                return 0;
            }

            $alreadyRequested = DB::table('super_crop_certifications')
                ->where('owner_uid', (string) $uid)->where('crop_code', $code)
                ->whereIn('neighbor_uid', $recipients)->pluck('neighbor_uid')->map('strval')->all();
            $newRecipients = array_values(array_diff($recipients, $alreadyRequested));
            $inserted = 0;
            foreach (array_slice($newRecipients, 0, $slotsLeft) as $recipient) {
                $inserted += DB::table('super_crop_certifications')->insertOrIgnore([
                    'owner_uid' => (string) $uid, 'crop_code' => $code,
                    'neighbor_uid' => $recipient, 'status' => 'pending',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            return $inserted;
        });
        return ['requested' => $requested];
    }

    public static function complete(string|int $uid, string $cropCode): array
    {
        $definition = self::definition($cropCode);
        if ($definition === null || !self::mastered($uid, $definition)) {
            return ['error' => 'notMastered'];
        }

        return DB::transaction(function () use ($uid, $cropCode, $definition): array {
            self::lockOwner($uid);
            if (self::unlocked($uid, $cropCode)) {
                return ['status' => 2, 'cost' => 0, 'data' => self::progressComplete($uid, $cropCode)];
            }
            $accepted = DB::table('super_crop_certifications')->where('owner_uid', (string) $uid)
                ->where('crop_code', $cropCode)->where('status', 'accepted')->count();
            $cost = max(0, $definition['goal'] - $accepted) * self::SLOT_CASH;
            if ($cost > 0 && !\UserResources::removeCash($uid, $cost)) {
                return ['error' => 'insufficientCash'];
            }
            self::saveUnlock($uid, $cropCode, $cost);
            return ['status' => 1, 'cost' => $cost, 'data' => self::progressComplete($uid, $cropCode)];
        });
    }

    private static function progressComplete(string|int $uid, string $cropCode): array
    {
        $data = self::progress($uid, $cropCode);
        // TCompleteFriendSet passes this back to FriendSet.onInitialize.
        return isset($data['error']) ? ['index' => 0, 'uids' => new \stdClass(), 'pending' => []] : $data;
    }

    private static function lockOwner(string|int $uid): void
    {
        DB::table('users')->where('uid', (string) $uid)->lockForUpdate()->first();
    }

    private static function saveUnlock(string|int $uid, string $cropCode, int $cost): void
    {
        DB::table('super_crop_unlocks')->insertOrIgnore([
            'uid' => (string) $uid, 'crop_code' => $cropCode, 'cash_paid' => $cost,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('super_crop_certifications')->where('owner_uid', (string) $uid)
            ->where('crop_code', $cropCode)->where('status', 'pending')->delete();
    }

    public static function inbox(string|int $neighborUid): array
    {
        return DB::table('super_crop_certifications as c')
            ->join('users as u', 'u.uid', '=', 'c.owner_uid')
            ->where('c.neighbor_uid', (string) $neighborUid)->where('c.status', 'pending')
            ->select('c.id', 'c.owner_uid', 'c.crop_code', 'u.name as owner_name')
            ->orderBy('c.created_at')->limit(100)->get()->map(function ($row): array {
                $definition = self::definition($row->crop_code);
                return ['id' => $row->id, 'owner' => $row->owner_name,
                    'crop' => $definition['name'] ?? $row->crop_code];
            })->all();
    }

    public static function respond(string|int $neighborUid, int $requestId, bool $accept): bool
    {
        $request = DB::table('super_crop_certifications')->where('id', $requestId)
            ->where('neighbor_uid', (string) $neighborUid)->first();
        if (!$request || $request->status !== 'pending') {
            return false;
        }

        return DB::transaction(function () use ($request, $neighborUid, $accept): bool {
            self::lockOwner($request->owner_uid);
            $row = DB::table('super_crop_certifications')->where('id', $request->id)
                ->where('neighbor_uid', (string) $neighborUid)->lockForUpdate()->first();
            if (!$row || $row->status !== 'pending') {
                return false;
            }
            if (!$accept) {
                DB::table('super_crop_certifications')->where('id', $row->id)->delete();
                return true;
            }
            $definition = self::definition($row->crop_code);
            if ($definition === null || !self::mastered($row->owner_uid, $definition)
                || self::unlocked($row->owner_uid, $row->crop_code)
                || !in_array((string) $neighborUid, self::neighborIds($row->owner_uid), true)) {
                return false;
            }
            DB::table('super_crop_certifications')->where('id', $row->id)
                ->update(['status' => 'accepted', 'updated_at' => now()]);
            return true;
        });
    }
}
