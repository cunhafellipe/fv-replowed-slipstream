<?php

require_once AMFPHP_ROOTPATH . 'Helpers/general_functions.php';
require_once AMFPHP_ROOTPATH . 'Helpers/user_resources.php';

use App\Support\SuperCrops;

class SuperCropsService
{
    public static function getProgress($playerObj, $request, $market = null): array
    {
        $code = $request->params[0] ?? null;
        return ['data' => is_string($code)
            ? SuperCrops::progress($playerObj->getUid(), $code)
            : ['error' => 'invalidCrop']];
    }

    public static function completeFriendSet($playerObj, $request, $market = null): array
    {
        $code = $request->params[0] ?? null;
        return ['data' => is_string($code)
            ? SuperCrops::complete($playerObj->getUid(), $code)
            : ['error' => 'invalidCrop']];
    }
}
