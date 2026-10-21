# Harvest Valley revival: first milestone

Harvest Valley's internal world key is `farmfest`; its asset prefix is `xfs`.
The archived game settings define `farmfestPoints`, `farmfest_theme`, and
`/config/npc_farms/farmfestStart.farm`. The recovered Flash client has
`FarmfestWorldInit`, and the archived world settings, YIMF theme, world-score
data, and effective AMF item catalog contain Farmfest entries. The core
environment SWFs are also present. The archived market's Harvest Valley
shipping-license promotion checks `farmfestXfer_visible`, confirming the
public/internal name mapping.

This milestone maps `farmfest` to its original `farmfest_theme` and adds tests
for the archived world metadata and core environment assets. It deliberately
does **not** make the world claimable or travelable.

## Remaining blockers before a pilot

- The referenced `farmfestStart.farm` is not present under `public/`. The
  server's generic `createWorldByType` would currently create an empty world.
  Recover or reconstruct the starter objects from an authoritative source;
  do not silently substitute a fabricated layout.
- `farmfest` is excluded from both the web World Shop and AMF world allowlists.
  Add access only behind a per-account pilot gate after the starter world is
  ready, including a safe return-home path if the pilot gate is disabled.
- The original `FarmfestService.unlockFarmfestWorld` transaction has no server
  handler here. Audit the client calls and route or implement them deliberately.
- The asset archive has many `farmfest`/`xfs` files, but a full URL-to-file
  audit and in-client load test have not been completed. Core theme SWFs exist;
  that does not prove every object, dialog, or quest asset is present.
- The Farmers Market currently substitutes home-farm crops for the original
  Harvest Valley tasks. Restore those tasks only after Farmfest crops can be
  planted and harvested on a working Farmfest world.

## Pilot acceptance checks

1. A selected test account can unlock and enter `farmfest`; non-pilot accounts
   cannot claim, travel to, or load it through AMF.
2. The authored terrain/background and starter objects render without missing
   assets; the world survives reload and return-home/re-entry.
3. Farmfest crops can be bought, planted, grown, harvested, and persisted, and
   world score/currency and inventory remain consistent after reload.
4. Audit world-specific crafting, expansion, shipping, quests, and market
   interactions before broad availability. A minimal playable pilot is not a
   claim that all original Harvest Valley features have been restored.

Harvest Valley access remains disabled, and no production deployment was
performed in this milestone.
