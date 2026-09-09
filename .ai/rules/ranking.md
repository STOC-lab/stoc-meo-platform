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

## Sandbox still needs credentials

`DATAFORSEO_SANDBOX=true` only swaps the host for `sandbox.dataforseo.com`. The
sandbox authenticates with the same account as the live API, so without
`DATAFORSEO_LOGIN` and `DATAFORSEO_PASSWORD` it answers
`401 / status_code 40100` and `DataForSEOProvider::isAvailable()` is false.

Every rank in the database showing `provider: "fallback"` and `rank: null` means
the credentials are missing, not that the store front is unranked.

## Calls are live, one keyword at a time

The provider uses the live SERP endpoint rather than the task queue: one keyword
is one call, so a failure is contained to that keyword and
`FetchDailyRankingsJob`'s own retries handle it. `calculate_rectangles` stays
off — nothing reads the pixel geometry and it costs on every call.

A heatmap asks the same keyword from each grid point by passing a `GeoPoint`,
which replaces `location_name` with `location_coordinate`; DataForSEO takes one
or the other, never both.
