# Models and migrations

## Mass assignment is declared with the attribute, not the property

Every model uses `#[Fillable([...])]` above the class. There is no
`protected $fillable` anywhere in `app/Models`; do not introduce one.
Casts go in a `casts(): array` method, not a `$casts` property.

## A column default is not a model default

`$table->boolean('is_active')->default(true)` fills the column, but a model
that has just been created never read it back, so it serialises as `null` in
the response to a create. When a column has a default that an API response
carries, declare it on the model too:

    protected $attributes = ['is_active' => true];

This was a real bug on `Keyword`: the create endpoint answered
`is_active: null` while the row held `true`, and the list request that followed
corrected it, which is why it stayed invisible until the API was called
directly. Assert the field in the response, not only in the database.

## Tenancy

21 models use `BelongsToTenant`, which applies `TenantScope`. Queries outside a
request — jobs, console commands, tinker — see nothing until a tenant is set:

- `app(Tenancy::class)->forOrganization($id, fn () => ...)` to run as one
  organization. Jobs do this; see `FetchDailyRankingsJob::handle()`.
- `app(Tenancy::class)->withoutTenancy(fn () => ...)` to cross organizations,
  which is for maintenance and tests, not for request handling.

An empty result from a job or a tinker session is almost always a missing
tenant rather than missing data.

## Factories

Every model has a factory. Check its states before building a record by hand.

## A "store" in a specification is a `Location`

There is no `stores` table and no `tenants` table. The tenant is
`organizations` (`BelongsToTenant::getTenantColumn()` answers
`organization_id`), and the store front is `locations` — the row that
keywords, ranking results, scores, analyses, reviews, posts, heatmaps, alerts
and reports all hang off. A specification asking for `stores` is asking for
this, and building it beside `locations` would give the product two store
concepts and leave ten tables pointing at the wrong one.

## Slugs are per organization, and Japanese names do not slug

`HasTenantSlug` derives `slug` from `name`, unique within the organization and
not globally: two tenants may both run a "sakura", and one must not learn the
other exists by failing to take a name. Soft-deleted rows still hold theirs.

`Str::slug()` drops everything it cannot transliterate, so a name written only
in Japanese — which is most of them here — slugs to an empty string. The model
supplies a fallback (`brand`, `store`) and the counter does the rest: `store`,
then `store-2`. Do not present a slug as meaningful text to a shop owner; it is
a URL-safe handle, not a name.

The generation hook is `saving`, which runs **before** `creating`, and it is
`creating` that stamps `organization_id` on a new row. That is why
`tenantKey()` falls back to the active tenant: without it every new row checks
its slug against organization `null`, finds nothing, and collides on insert.

## Deleting a store front is soft

Ten tables of history hang off a location, and a hard delete took the lot.
Brands are soft too, and a brand with locations under it is refused rather than
detaching them — moving a chain's shops out from under their banner is decided
per shop.

## A limit the plan has never heard of is not a limit of zero

`FeatureResolver::limit()` answers `0` both for "this plan sets zero" and for
"this plan does not mention the feature". For a metered allowance the two mean
the same thing; for a structural one — `brand.limit`, `location.limit` — they
do not, and treating an older plan as zero locks an organization out of its own
product. Ask `has()` first, and skip the check when the answer is no.

These two are counted from the rows that exist right now, not through the
`UsageTracker`: that counts consumption over a calendar month, so a deleted
store front would not give its slot back until the next one.
