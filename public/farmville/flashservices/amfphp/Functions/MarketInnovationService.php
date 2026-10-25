<?php

require_once AMFPHP_ROOTPATH.'Helpers/general_functions.php';
require_once AMFPHP_ROOTPATH.'Helpers/crafting_helper.php';

use App\Support\MarketCompetition;
use App\Support\MarketInnovationState;
use App\Support\MarketRewards;

/** Shared AMF response contract for the two archived market innovations. */
abstract class MarketInnovationService
{
    protected const FEATURE = '';

    protected static function checkedFeature($request): string
    {
        if (! MarketCompetition::isEnabled(static::FEATURE)) {
            throw new InvalidArgumentException('The market innovation is not currently active.');
        }
        $requested = $request->params[0] ?? null;
        if ($requested !== static::FEATURE) {
            throw new InvalidArgumentException('Market feature does not match the service.');
        }

        return static::FEATURE;
    }

    public static function startQuest($playerObj, $request, $market = null): array
    {
        return ['data' => [
            'featureData' => MarketInnovationState::start((string) $playerObj->getUid(), static::checkedFeature($request)),
        ]];
    }

    public static function redeemRewardPoints($playerObj, $request, $market = null): array
    {
        $feature = static::checkedFeature($request);
        $slot = filter_var($request->params[1] ?? null, FILTER_VALIDATE_INT);
        if ($slot === false || $slot < 0 || $slot > 2) {
            throw new InvalidArgumentException('Invalid market task slot.');
        }

        return ['data' => [
            'featureData' => MarketInnovationState::redeem((string) $playerObj->getUid(), $feature, $slot),
        ]];
    }

    public static function addRemainingOBPoints($playerObj, $request, $market = null): array
    {
        $feature = static::checkedFeature($request);

        return ['data' => [
            'featureData' => MarketInnovationState::redeemRemaining((string) $playerObj->getUid(), $feature, self::checkedSlot($request)),
        ]];
    }

    public static function addRemainingOBPointsOnSkip($playerObj, $request, $market = null): array
    {
        $feature = static::checkedFeature($request);

        return ['data' => [
            'featureData' => MarketInnovationState::redeemRemaining((string) $playerObj->getUid(), $feature, self::checkedSlot($request), true),
        ]];
    }

    public static function getNewTask($playerObj, $request, $market = null): array
    {
        $feature = static::checkedFeature($request);
        $slot = filter_var($request->params[1] ?? null, FILTER_VALIDATE_INT);
        if ($slot === false || $slot < 0 || $slot > 2) {
            throw new InvalidArgumentException('Invalid market task slot.');
        }

        return ['data' => [
            'featureData' => MarketInnovationState::newTask((string) $playerObj->getUid(), $feature, $slot),
        ]];
    }

    public static function dumpTask($playerObj, $request, $market = null): array
    {
        $feature = static::checkedFeature($request);
        $slot = self::checkedSlot($request);

        return ['data' => [
            'featureData' => MarketInnovationState::dumpTask((string) $playerObj->getUid(), $feature, $slot),
        ]];
    }

    public static function skipTask($playerObj, $request, $market = null): array
    {
        $feature = static::checkedFeature($request);
        $slot = self::checkedSlot($request);

        return ['data' => [
            'featureData' => MarketInnovationState::skipTask((string) $playerObj->getUid(), $feature, $slot),
        ]];
    }

    public static function sinkGold($playerObj, $request, $market = null): array
    {
        $feature = static::checkedFeature($request);
        $slot = self::checkedSlot($request);

        return ['data' => [
            'featureData' => MarketInnovationState::sinkGold((string) $playerObj->getUid(), $feature, $slot),
        ]];
    }

    public static function sinkBushel($playerObj, $request, $market = null): array
    {
        $feature = static::checkedFeature($request);
        $slot = self::checkedSlot($request);

        return ['data' => [
            'featureData' => MarketInnovationState::sinkBushel((string) $playerObj->getUid(), $feature, $slot),
        ]];
    }

    public static function skipDumpedTask($playerObj, $request, $market = null): array
    {
        $feature = static::checkedFeature($request);
        $slot = self::checkedSlot($request);

        return ['data' => [
            'featureData' => MarketInnovationState::skipDumpedTask((string) $playerObj->getUid(), $feature, $slot),
        ]];
    }

    private static function checkedSlot($request): int
    {
        $slot = filter_var($request->params[1] ?? null, FILTER_VALIDATE_INT);
        if ($slot === false || $slot === null || $slot < 0 || $slot > 2) {
            throw new InvalidArgumentException('Invalid market task slot.');
        }

        return $slot;
    }

    public static function fetchSellingPrice($playerObj, $request, $market = null): array
    {
        $feature = static::checkedFeature($request);
        $state = MarketInnovationState::load((string) $playerObj->getUid(), $feature);

        return ['data' => ['price' => (int) $state['sell_price']]];
    }

    public static function startnewday($playerObj, $request, $market = null): array
    {
        return ['data' => [
            'featureData' => MarketInnovationState::startNewDay((string) $playerObj->getUid(), static::checkedFeature($request)),
        ]];
    }

    // Selection is local to one sales window. Persisting it account-wide
    // would let concurrent sessions accidentally sell each other's quantity.
    public static function increaseProduceQty($playerObj, $request, $market = null): array
    {
        static::checkedFeature($request);

        return ['data' => []];
    }

    public static function reduceProduceQty($playerObj, $request, $market = null): array
    {
        static::checkedFeature($request);

        return ['data' => []];
    }

    public static function resetProduceQty($playerObj, $request, $market = null): array
    {
        static::checkedFeature($request);

        return ['data' => []];
    }

    public static function grantDailyReward($playerObj, $request, $market = null): array
    {
        return ['data' => MarketInnovationState::grantDailyReward((string) $playerObj->getUid(), static::checkedFeature($request))];
    }

    public static function upgradeStorageCapacity($playerObj, $request, $market = null): array
    {
        $feature = static::checkedFeature($request);
        $expectedLevel = filter_var($request->params[1] ?? null, FILTER_VALIDATE_INT);
        if ($expectedLevel === false || $expectedLevel === null) {
            throw new InvalidArgumentException('Reload the updated market client to upgrade storage.');
        }

        return ['data' => [
            'featureData' => MarketInnovationState::upgradeStorage((string) $playerObj->getUid(), $feature, $expectedLevel),
        ]];
    }

    public static function sellProduce($playerObj, $request, $market = null): array
    {
        $feature = static::checkedFeature($request);
        $quantity = filter_var($request->params[1] ?? null, FILTER_VALIDATE_INT);
        $saleKey = $request->params[2] ?? null;
        if ($quantity === false || ! is_string($saleKey)) {
            // The unpatched Flash build sends only a feature name. Never
            // guess its selected quantity from another window's state.
            throw new InvalidArgumentException('Reload the updated market client to sell produce.');
        }

        return ['data' => MarketInnovationState::sell((string) $playerObj->getUid(), $feature, $quantity, $saleKey)];
    }

    public static function getLeaderboardEntries($playerObj, $request, $market = null): array
    {
        $feature = static::checkedFeature($request);
        $uid = (string) $playerObj->getUid();
        MarketInnovationState::start($uid, $feature);
        $featureData = MarketInnovationState::startNewDay($uid, $feature);
        $standing = MarketCompetition::standings($uid, $feature);

        $award = MarketRewards::claimCompletedWeeks($uid, $feature);

        return ['data' => [
            'zid' => $uid,
            'featureData' => $featureData,
            'userData' => $standing['userData'],
            'globalLeaderboardData' => ['slotData' => $standing['slotData']],
            'syncMainUI' => (bool) ($request->params[1] ?? false),
        ] + ($award ?? [])];
    }

    public static function featureEnd($playerObj, $request, $market = null): array
    {
        $feature = static::checkedFeature($request);

        return ['data' => MarketInnovationState::claimFinalReward((string) $playerObj->getUid(), $feature)];
    }

    public static function harvestDecos($playerObj, $request, $market = null): array
    {
        $feature = static::checkedFeature($request);
        $building = $request->params[1] ?? null;
        if (! is_string($building) || $building === '') {
            throw new InvalidArgumentException('Unknown market bonus building.');
        }

        return ['data' => MarketInnovationState::harvestBonusBuilding((string) $playerObj->getUid(), $feature, $building)];
    }

    public static function onRedeemStarted($playerObj, $request, $market = null): array
    {
        static::checkedFeature($request);

        return ['data' => []];
    }

    /** The external Tropic Escape bridge is not available in FV Classic. */
    public static function updateEscapeTokensCount($playerObj, $request, $market = null): array
    {
        static::checkedFeature($request);
        if (static::FEATURE !== MarketCompetition::TROPICAL) {
            throw new InvalidArgumentException('Escape tokens belong only to Tropical Market.');
        }

        return ['data' => ['success' => false, 'error' => 'tropic_escape_unavailable']];
    }

    public static function initializeTropicEscapeConnection($playerObj, $request, $market = null): array
    {
        static::checkedFeature($request);
        if (static::FEATURE !== MarketCompetition::TROPICAL) {
            throw new InvalidArgumentException('Tropic Escape belongs only to Tropical Market.');
        }

        // Do not pretend that an account is linked to an unavailable game.
        return ['data' => ['response' => ['data' => ['started' => false]]]];
    }

    public static function getEnvironment($playerObj, $request, $market = null): array
    {
        static::checkedFeature($request);
        if (static::FEATURE !== MarketCompetition::TROPICAL) {
            throw new InvalidArgumentException('Tropic Escape belongs only to Tropical Market.');
        }

        return ['data' => ['success' => true, 'environment' => 'zprod']];
    }

    public static function postNeighbours($playerObj, $request, $market = null): array
    {
        static::checkedFeature($request);
        if (static::FEATURE !== MarketCompetition::TROPICAL) {
            throw new InvalidArgumentException('Tropic Escape belongs only to Tropical Market.');
        }

        return ['data' => ['response' => json_encode(['data' => ['result' => false]], JSON_THROW_ON_ERROR)]];
    }

    public static function updateEscapeTokensProgress($playerObj, $request, $market = null): array
    {
        static::checkedFeature($request);
        if (static::FEATURE !== MarketCompetition::TROPICAL) {
            throw new InvalidArgumentException('Escape tokens belong only to Tropical Market.');
        }

        return ['data' => ['success' => false, 'tileUnlocked' => false, 'error' => 'tropic_escape_unavailable']];
    }

    protected static function unavailableEscapeAction($request): array
    {
        static::checkedFeature($request);
        if (static::FEATURE !== MarketCompetition::TROPICAL) {
            throw new InvalidArgumentException('Escape tokens belong only to Tropical Market.');
        }

        return ['data' => ['success' => false, 'tileUnlocked' => false, 'error' => 'tropic_escape_unavailable']];
    }

    public static function resetEscapeTokensProgress($playerObj, $request, $market = null): array
    {
        return static::unavailableEscapeAction($request);
    }

    public static function unlockCurrentTile($playerObj, $request, $market = null): array
    {
        return static::unavailableEscapeAction($request);
    }

    public static function activateOrderboardBoost($playerObj, $request, $market = null): array
    {
        static::checkedFeature($request);
        if (static::FEATURE !== MarketCompetition::TROPICAL) {
            throw new InvalidArgumentException('Order-board boosts belong only to Tropical Market.');
        }

        return ['data' => ['success' => false, 'error' => 'tropic_escape_unavailable']];
    }

    public static function resetTasksWithoutBoostCount($playerObj, $request, $market = null): array
    {
        static::checkedFeature($request);
        if (static::FEATURE !== MarketCompetition::TROPICAL) {
            throw new InvalidArgumentException('Boost task state belongs only to Tropical Market.');
        }

        return ['data' => []];
    }

    public static function updateTaskBoostPopupTime($playerObj, $request, $market = null): array
    {
        return self::resetTasksWithoutBoostCount($playerObj, $request, $market);
    }
}
