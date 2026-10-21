<?php

use App\Support\MarketSeason;
use App\Support\MarketGameSettings;

afterEach(function (): void {
    config()->set('market_innovations.enabled', false);
    config()->set('market_innovations.farmers_enabled', true);
    config()->set('market_innovations.tropical_enabled', true);
});

it('hides the dynamic market CMS while the revival is disabled', function (): void {
    config()->set('market_innovations.enabled', false);

    $this->get('/farmville/market-cms/'.MarketSeason::cacheKey().'.xml.gz')->assertNotFound();
});

it('serves a versioned zlib CMS only for the current market week', function (): void {
    config()->set('market_innovations.enabled', true);
    $week = MarketSeason::cacheKey();

    $response = $this->get('/farmville/market-cms-v2/'.$week.'.xml.gz');
    $response->assertOk();
    expect(gzuncompress($response->getContent()))->toContain('FarmersMarket2020Innovation')
        ->and(gzuncompress($response->getContent()))->toContain('TropicalMarketInnovation');

    // A page loaded just before midnight must still fetch its versioned CMS
    // after the Monday rollover, rather than failing with a 404.
    $previous = (new DateTimeImmutable($week))->modify('-7 days')->format('Y-m-d');
    $this->get('/farmville/market-cms-v2/'.$previous.'.xml.gz')->assertOk();

    $this->get('/farmville/market-cms/2020-01-20.xml.gz')->assertNotFound();
});

it('serves Farmers Market but omits Tropical Market when Tropical is disabled', function (): void {
    config()->set('market_innovations.enabled', true);
    config()->set('market_innovations.farmers_enabled', true);
    config()->set('market_innovations.tropical_enabled', false);

    $response = $this->get('/farmville/market-cms-v2/'.MarketSeason::cacheKey().'.xml.gz');
    $response->assertOk();
    $cms = gzuncompress($response->getContent());

    expect($cms)->toContain('FarmersMarket2020Innovation')
        ->and($cms)->not->toContain('TropicalMarketInnovation');
});

it('serves a client task catalog with reachable home-farm crops', function (): void {
    config()->set('market_innovations.enabled', true);
    config()->set('market_innovations.farmers_enabled', true);

    $response = $this->get('/farmville/market-game-settings-v1.xml.gz');
    $response->assertOk();
    $xml = simplexml_load_string(gzuncompress($response->getContent()), 'SimpleXMLElement', LIBXML_NONET);
    $tasks = $xml->xpath("//orderBoards/orderBoard[@name='FarmersMarket2020Innovation']/orderBoardTasks/difficulty/tasks/task[@action='harvestByCode' and @helper='plant_seed']");

    expect(count($tasks))->toBe(16);
    foreach ($tasks as $index => $task) {
        expect((string) $task['type'])->toBe(MarketGameSettings::homeSeedCodes()[$index % 5]);
    }

    $source = gzuncompress(file_get_contents(public_path('farmville/xml/gz/v855038/gameSettings.xml.gz')));
    expect($source)->toContain('type="2gfn"');
});

it('does not serve the adapted catalog when Farmers Market is disabled', function (): void {
    config()->set('market_innovations.enabled', false);
    $this->get('/farmville/market-game-settings-v1.xml.gz')->assertNotFound();
});
