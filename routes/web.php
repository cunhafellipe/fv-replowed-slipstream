<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\DiscordAvatarController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\NeighborController;
use App\Http\Controllers\SuperCropCertificationController;
use App\Http\Controllers\GameController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\DailyGiftController;
use App\Http\Controllers\WorldShopController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\HomeWorldController;
use App\Http\Controllers\PlayerExportController;
use App\Http\Controllers\AdminPlayerImportController;
use Illuminate\Http\Request;
use App\Support\MarketCompetition;
use App\Support\MarketGameSettings;
use App\Support\MarketSeason;

// Flash checks this policy before a loaded SWF exposes scriptable data to the
// main game movie. Allow only the host that served the policy, rather than a
// broad wildcard policy.
Route::get('/crossdomain.xml', function (Request $request) {
    $host = $request->getHost();

    if (!preg_match('/^[A-Za-z0-9.-]+$/', $host)) {
        abort(400);
    }

    $policy = '<?xml version="1.0"?>' . "\n"
        . '<cross-domain-policy>' . "\n"
        . '  <allow-access-from domain="' . $host . '" secure="false" />' . "\n"
        . '</cross-domain-policy>' . "\n";

    return response($policy, 200)
        ->header('Content-Type', 'text/x-cross-domain-policy')
        ->header('Cache-Control', 'public, max-age=3600');
});

$marketCms = function (string $week) {
    abort_unless(MarketCompetition::enabledFeatures() !== [], 404);
    abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $week) === 1, 404);
    $utc = new \DateTimeZone('UTC');
    $requested = \DateTimeImmutable::createFromFormat('!Y-m-d', $week, $utc);
    abort_unless($requested !== false && $requested->format('Y-m-d') === $week, 404);
    $current = new \DateTimeImmutable(MarketSeason::cacheKey(), $utc);
    abort_unless($requested->format('N') === '1'
        && $requested >= $current->modify('-7 days')
        && $requested <= $current->modify('+7 days'), 404);

    // The CMS response is binary zlib data, which cannot be stored directly in
    // the UTF-8 database cache. Cache its base64 representation instead.
    $featureMask = (MarketCompetition::isEnabled(MarketCompetition::FARMERS) ? 'f1' : 'f0')
        .(MarketCompetition::isEnabled(MarketCompetition::TROPICAL) ? 't1' : 't0');
    $encodedCms = Cache::remember(
        'market-cms-base64-v2-'.$week.'-'.$featureMask,
        now()->addDays(2),
        static fn (): string => base64_encode(MarketSeason::cms($requested))
    );
    $cms = base64_decode($encodedCms, true);
    abort_if($cms === false, 500, 'The cached market CMS is invalid.');

    return response($cms, 200)
        ->header('Content-Type', 'application/octet-stream')
        ->header('Cache-Control', 'public, max-age=86400');
};

// v2 changes the resource path so clients cannot reuse a cached CMS document
// that still lists a market feature disabled by the current server config.
Route::get('/farmville/market-cms-v2/{week}.xml.gz', $marketCms);
Route::get('/farmville/market-cms/{week}.xml.gz', $marketCms);

// A versioned game-settings URL keeps the revived board's crop definitions
// identical in Flash and in the server-authoritative task catalog.
Route::get('/farmville/market-game-settings-v1.xml.gz', function () {
    abort_unless(MarketCompetition::isEnabled(MarketCompetition::FARMERS), 404);
    $encoded = Cache::remember(
        'market-game-settings-base64-v1',
        now()->addDays(7),
        static fn (): string => base64_encode(MarketGameSettings::compressedXml())
    );
    $settings = base64_decode($encoded, true);
    abort_if($settings === false, 500, 'The cached market settings are invalid.');

    return response($settings, 200)
        ->header('Content-Type', 'application/octet-stream')
        ->header('Cache-Control', 'public, max-age=86400');
});

// The v2 Buy Ingredients movie was referenced by the preserved game client but
// is absent from the recovered asset collection. The prior dialog has the same
// ActionScript contract and is present in the collection, so serve it only for
// this missing hashed URL. If the original v2 file is recovered later, the web
// server will serve that real file before this Laravel fallback is reached.
Route::get('/farmville/assets/hashed/assets/dialogs/01c114a2b75b35d546263f7b101c9809.swf', function () {
    $fallback = public_path('farmville/assets/hashed/assets/dialogs/ff6f26b47a9429c3bafdbfbda1600cf5.swf');

    abort_unless(is_file($fallback), 404);

    return response()->file($fallback, [
        'Content-Type' => 'application/x-shockwave-flash',
        'Cache-Control' => 'public, max-age=86400',
    ]);
});

Route::get('/up', function () {
    $health = [
        'status' => 'ok',
        'timestamp' => now()->toIso8601String(),
        'checks' => [],
    ];

    $dbStart = microtime(true);
    try {
        DB::select('SELECT 1');
        $health['checks']['database'] = [
            'status' => 'ok',
            'ping_ms' => round((microtime(true) - $dbStart) * 1000, 2),
        ];
    } catch (\Exception $e) {
        $health['status'] = 'degraded';
        $health['checks']['database'] = ['status' => 'error', 'message' => 'Connection failed'];
    }

    $cacheStart = microtime(true);
    try {
        Cache::put('health_check', true, 10);
        $cacheWorks = Cache::get('health_check') === true;
        $health['checks']['cache'] = [
            'status' => $cacheWorks ? 'ok' : 'error',
            'ping_ms' => round((microtime(true) - $cacheStart) * 1000, 2),
        ];
    } catch (\Exception $e) {
        $health['checks']['cache'] = ['status' => 'error'];
    }

    try {
        if (Storage::exists('last_backup.json')) {
            $backup = json_decode(Storage::get('last_backup.json'), true);
            $health['checks']['last_backup'] = [
                'status' => 'ok',
                'timestamp' => $backup['timestamp'] ?? null,
                'age_hours' => isset($backup['timestamp']) ? round((time() - $backup['timestamp']) / 3600, 1) : null,
            ];
        } else {
            $health['checks']['last_backup'] = ['status' => 'none'];
        }
    } catch (\Exception $e) {
        $health['checks']['last_backup'] = ['status' => 'error'];
    }

    return response()->json($health);
});

Route::get('/', function () {
    return view('welcome');
})->middleware(['maintenance', 'auth', 'discord.member']);

// Launcher routes
Route::get('/app', function () {
    if (auth()->check()) {
        return redirect('/play');
    }
    return view('launcher.home');
})->name('app')->middleware(['maintenance', 'auth', 'discord.member']);

Route::get('/play', [GameController::class, 'play'])->middleware(['auth', 'verified', 'discord.member'])->name('play');

Route::get('/dashboard', function () {
    return redirect()->route('play');
})->middleware(['auth', 'verified', 'discord.member'])->name('dashboard');

Route::get('/game', [GameController::class, 'index'])->middleware(['auth', 'verified', 'discord.member'])->name('game');

Route::middleware(['auth', 'verified', 'discord.member', 'admin'])->group(function () {
    Route::get('/admin', [AdminController::class, 'index'])->name('admin');
    Route::post('/admin/lookup', [AdminController::class, 'lookupUser'])->name('admin.lookup');
    Route::post('/admin/update-currency', [AdminController::class, 'updateCurrency'])->name('admin.update-currency');
    Route::post('/admin/impersonate', [AdminController::class, 'impersonate'])->name('admin.impersonate');
    Route::post('/admin/import-save', [AdminPlayerImportController::class, 'import'])
        ->name('admin.import-save')
        ->middleware('throttle:5,1');
});

Route::post('/admin/stop-impersonating', [AdminController::class, 'stopImpersonating'])
    ->middleware(['auth', 'discord.member'])
    ->name('admin.stop-impersonating');

Route::get('/profile-pictures/discord/{uid}', [DiscordAvatarController::class, 'show'])
    ->whereNumber('uid')
    ->name('profile.discord-avatar');

Route::middleware(['auth', 'discord.member'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::post('/profile/settings', [ProfileController::class, 'updateSettings'])->name('profile.settings');
    Route::get('/profile/export', [PlayerExportController::class, 'download'])
        ->name('profile.export')
        ->middleware('throttle:5,1');

    Route::get('/neighbors/data', [NeighborController::class, 'getNeighborsData'])->name('neighbors.data');
    Route::get('/neighbors/potential', [NeighborController::class, 'getPotentialNeighbors'])->name('neighbors.potential');
    Route::get('/neighbors/pending', [NeighborController::class, 'getPendingRequests'])->name('neighbors.pending');
    Route::post('/neighbors/add', [NeighborController::class, 'addNeighbor'])->name('neighbors.add');
    Route::post('/neighbors/remove', [NeighborController::class, 'removeNeighbor'])->name('neighbors.remove');
    Route::post('/neighbors/accept', [NeighborController::class, 'acceptNeighbor'])->name('neighbors.accept');
    Route::post('/neighbors/reject', [NeighborController::class, 'rejectNeighbor'])->name('neighbors.reject');
    Route::post('/neighbors/send-request', [NeighborController::class, 'sendNeighborRequest'])->name('neighbors.send-request');
    Route::get('/super-crops/requests', [SuperCropCertificationController::class, 'inbox'])->name('super-crops.requests');
    Route::post('/super-crops/respond', [SuperCropCertificationController::class, 'respond'])->name('super-crops.respond');

    // Daily Gift routes
    Route::get('/daily-gift/status', [DailyGiftController::class, 'checkStatus'])->name('daily-gift.status');
    Route::post('/daily-gift/claim', [DailyGiftController::class, 'claim'])->name('daily-gift.claim');

    // Recovery path for a broken or unavailable travel world. The player must
    // choose it themselves; only their selected current world is changed.
    Route::post('/api/worlds/return-home', [HomeWorldController::class, 'returnHome'])
        ->name('worlds.return-home')
        ->middleware('throttle:6,1');

    // World Shop routes
    Route::get('/api/world-shop/status', [WorldShopController::class, 'status'])->name('world-shop.status');
    Route::post('/api/world-shop/claim', [WorldShopController::class, 'claim'])->name('world-shop.claim');
    Route::post('/api/world-shop/purchase', [WorldShopController::class, 'purchase'])->name('world-shop.purchase');
    Route::post('/api/world-shop/travel', [WorldShopController::class, 'travel'])->name('world-shop.travel');

    // Chat routes
    Route::get('/chat/messages', [ChatController::class, 'messages'])
        ->middleware('throttle:chat-history')->name('chat.messages');
    Route::post('/chat/send', [ChatController::class, 'send'])
        ->middleware('throttle:chat-send')->name('chat.send');
    Route::get('/chat/unread-count', [ChatController::class, 'unreadCount'])
        ->middleware('throttle:chat-unread')->name('chat.unread-count');
    Route::post('/chat/mark-read', [ChatController::class, 'markRead'])
        ->middleware('throttle:chat-mark-read')->name('chat.mark-read');
});

require __DIR__.'/auth.php';
