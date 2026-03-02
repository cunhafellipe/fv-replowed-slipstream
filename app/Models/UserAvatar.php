<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserAvatar extends Model
{
    protected $table = 'useravatars';

    protected $fillable = [
        'uid', 'value'
    ];

    private static array $avatarCache = [];

    public function user()
    {
        return $this->belongsTo(User::class, 'uid');
    }

    public static function getForUser(string|int $uid): mixed
    {
        if (isset(self::$avatarCache[$uid])) {
            return self::$avatarCache[$uid];
        }

        $avatar = static::where('uid', $uid)->first();

        if (!$avatar || empty($avatar->value)) {
            self::$avatarCache[$uid] = null;
            return null;
        }

        $data = @unserialize($avatar->value);
        self::$avatarCache[$uid] = $data;
        return $data;
    }

    public static function updateAttributes(string|int $uid, string $serializedValue): bool
    {
        $affected = static::where('uid', $uid)
            ->update(['value' => $serializedValue]);

        static::invalidateCache($uid);
        return $affected > 0;
    }

    public static function setAvatar(string|int $uid, string $serializedValue): bool
    {
        static::updateOrCreate(
            ['uid' => $uid],
            ['value' => $serializedValue]
        );

        static::invalidateCache($uid);
        return true;
    }

    public static function invalidateCache(string|int $uid): void
    {
        unset(self::$avatarCache[$uid]);
    }
}
