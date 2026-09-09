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
