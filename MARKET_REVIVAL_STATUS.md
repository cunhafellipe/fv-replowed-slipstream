# Market revival deployment status

The archived Farmers Market 2020 and Tropical Market revival was deployed to
the production host on 2026-10-12. The master feature remains enabled with
`MARKET_INNOVATIONS_ENABLED=true`; Farmers Market is enabled and Tropical
Market is independently disabled with `TROPICAL_MARKET_ENABLED=false`.

Client revision 8, which fixes the market leaderboard's null week-data
initialization error reported by Sal Vulcano, was deployed to the production host on
2026-10-12. The market feature remains enabled.

Deployment verification:

- The `2026_10_11_000000_create_market_innovation_tables` migration is applied.
- At the initial rev8 launch, the live CMS route returned HTTP 200 through the
  production MySQL cache and included both market definitions. The later v2
  route is feature-filtered as described below.
- `FarmGame-10-marketrevival8.swf` returned HTTP 200 and its running-container
  SHA-256 matched `0124B395F882C93DC3681E2828BB379CCA8074E70E41B7AECB053C34C9C8F7D3`.
- The deployed Blade view passed `php -l`; its market-enabled client selector
  points to revision 8. The web, Reverb, and scheduler containers are running
  the rebuilt image, and the market feature config remains enabled.
- Rollback copies of the prior view and rev7 SWF are under
  `[private deployment backup]`;
  the pre-deploy image is tagged
  `fv-replowed-slipstream:pre-marketrevival8-20261012-171222`.
- The Laravel market feature config reports enabled. Web, Reverb, and scheduler
  containers are running the deployed image.
- The 2026-10-12 Tropical-disable rollout kept Farmers enabled, set only
  `TROPICAL_MARKET_ENABLED=false`, and recreated the web, Reverb, and scheduler
  containers. The live config reports Farmers=true/Tropical=false; the v2 CMS
  returned Farmers Market while omitting Tropical Market, and the AMF service
  guard allows Farmers while rejecting Tropical. No market state, scores,
  rewards, or player inventory data was deleted.
- The CMS cache stores a base64 representation because MySQL's UTF-8 cache
  column rejects the original binary zlib payload.
- Database and pre-deployment source/environment backups are retained under
  `[private deployment backup]`.

Implemented behavior:

- Versioned CMS re-dates enabled features' original five Farmers and six
  Tropical market weeks and leaderboard windows without editing archived XML;
  independently disabled market entries are omitted from the CMS/bootstrap.
- The sale dialog sends the confirmed quantity and retry key, consumes
  authoritative totals, and syncs market state from leaderboard responses.
  The Tropical manager refuses local-only boosts/tile unlocks and does not
  double produce from unavailable Tropic Escape tokens.
- Server-owned board state supports verified task progress, redeem/limited
  storage, paid skips, dumping/cooldowns, sales, weekly score ledger,
  standings, daily rewards, and catch-up claims for unclaimed completed weeks.
  Gift packages are unpacked into the server giftbox once.
- Five-week Farmers and six-week Tropical seasons repeat from the October 5,
  2026 anchor. Rollover resets board tasks and coin totals while preserving
  purchased storage. Unspent produce converts into the ended season's final
  total rather than the next season's weekly score.
- The server grants the archived final building at its earned coin threshold
  once, and accepts its 24-hour bonus harvest only while that building is
  placed on the player's farm and the 14-day window is open. Daily,
  leaderboard, final, and bonus gifts are awarded by the server. Unavailable
  Tropical Escape/boost panels are hidden.
- The original milestone item mapping was not recovered. As requested, those
  milestone prizes remain disabled rather than substituted.
- The live item catalog contains all 66 expected weekly prize packages and
  all 40 distinct daily prize names. The final four Tropical daily rotation
  entries were restored from the archived manager.
- Storage capacities/prices follow the clients: Farmers `[50,100,150,200,300,400]`
  and `[0,10,20,30,50,80]`; Tropical `[30,50,80,100,150,200]` and
  `[0,10,20,25,40,50]`.

Remaining gameplay validation and limits:

- Tropical Escape is a separate unavailable game. Its token, tile and boost
  RPCs decline actions, and the patched client hides those unavailable panels;
  that external integration is not restored.
- Boost task variants and other archived optional task types remain excluded
  from the server-observable task pool. Confirm in Flash that this leaves no
  unreachable UI path.
- The actual Flash windows, reward popups, giftbox refresh timing, and season
  rollover have not yet been exercised in a real client. Test opening both
  markets, harvest/redeem, limited capacity, sale, leaderboard, giftbox after
  reload, simultaneous sessions, and UTC week rollover.
- The isolated full regression run had 298 passing tests and one unrelated
  Jade Falls terrain assertion that also fails on the pre-market baseline.
  The focused market tests passed before the production-cache fix; the final
  CMS code was verified against the live MySQL cache with a successful
  request/decompression check.
