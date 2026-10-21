<?php

return [
    // Keep the archived event hidden until its server/client flows are complete.
    'enabled' => (bool) env('MARKET_INNOVATIONS_ENABLED', false),
    'farmers_enabled' => (bool) env('FARMERS_MARKET_ENABLED', true),
    'tropical_enabled' => (bool) env('TROPICAL_MARKET_ENABLED', true),
];
