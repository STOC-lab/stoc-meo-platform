# Rank providers

## The router tries providers in order and the last one cannot fail

`RankProviderRouter` is registered in `AppServiceProvider::registerRankProviders()`
with `DataForSEOProvider` first and `FallbackProvider` last. Providers that
answer `isAvailable() === false` are skipped, and a `RankProviderException` moves
to the next one. `FallbackProvider` records a "not found" with a hand-checkable
Google Maps URL, so a check always leaves exactly one row per day and a gap is
visible in the product rather than silent.

Registration order is the priority order. A new provider goes before the
fallback, never after it.

## Not every failure is worth degrading for

`RankProviderException` carries `isTransient()`. A transient failure — a
throttle, a timeout, a 5xx, a reply that was not JSON — says nothing about the
keyword and would be answered differently a minute later. A permanent one is
the provider's settled answer to the request as asked.

The router still moves to the next provider for either, *unless* the caller
passes `callerWillRetry: true` and the failure is transient; then it re-raises
so the caller's own retry can ask again. `FetchDailyRankingsJob` passes it
while it has an attempt left, which is what finally puts its `tries = 3` and
`backoff [60, 300, 900]` to use — before 2026-09-11 the router swallowed every
exception, the job always succeeded, and the retries never once ran.

The last attempt passes `false` and takes the fallback, so the day still ends
with exactly one row per keyword. `FetchHeatmapJob` always passes `false`: a
retry there re-runs all 25 or 49 points, so one unanswerable point is cheaper
degraded than retried.

## A fallback row is not proof the credentials are wrong

Missing credentials are one cause; `isAvailable()` catches those before a call
is made. A live 401 is another, and DataForSEO answers a burst of live calls
from one account with 401 as readily as it answers a bad password — on
2026-09-11 three of five keywords in the same second were served and two were
refused, with the account verified good either side of the sweep.

Check `/v3/appendix/user_data` before concluding anything from a fallback row.
It is free, and it answers the credentials question directly.

## Sandbox still needs credentials

`DATAFORSEO_SANDBOX=true` only swaps the host for `sandbox.dataforseo.com`. The
sandbox authenticates with the same account as the live API, so without
`DATAFORSEO_LOGIN` and `DATAFORSEO_PASSWORD` it answers
`401 / status_code 40100` and `DataForSEOProvider::isAvailable()` is false.

A rank showing `provider: "fallback"` and `rank: null` does not mean the store
front is unranked — but it does not mean the credentials are missing either.
See the section above before acting on one.

## Calls are live, one keyword at a time

The provider uses the live SERP endpoint rather than the task queue: one keyword
is one call, so a failure is contained to that keyword and
`FetchDailyRankingsJob`'s own retries handle it. `calculate_rectangles` stays
off — nothing reads the pixel geometry and it costs on every call.

A heatmap asks the same keyword from each grid point by passing a `GeoPoint`,
which replaces `location_name` with `location_coordinate`; DataForSEO takes one
or the other, never both.
