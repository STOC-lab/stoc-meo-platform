# API and front end

## Authentication is a session, not a token

The SPA signs in with `POST /api/v1/auth/login` and Sanctum's cookie session.
There is no `POST /login` — `/login` is a front-end route served by the same
HTML shell as every other page, so posting to it answers 405.

Calling the API by hand takes three steps, and skipping the third is the usual
reason a request that should work answers 401:

1. `GET /sanctum/csrf-cookie` to obtain the session and `XSRF-TOKEN` cookies.
2. Send the url-decoded `XSRF-TOKEN` cookie back as the `X-XSRF-TOKEN` header
   on every write.
3. Send `Referer` (or `Origin`) for the application's own domain on **every**
   request, reads included. Without it Sanctum does not treat the call as a
   stateful one, ignores the session cookie, and falls through to the token
   guard, which answers `401 Unauthenticated` even though the cookie is valid.

The session cookie is `stoc-meo-session`. An unauthenticated API request
answers 401, never 500.

## Shapes

- Responses are assembled by a `present()` method on each controller. There is
  no `app/Http/Resources`; do not add API Resource classes for one endpoint.
- Validation lives in Form Requests under `app/Http/Requests`.
- Routes are versioned under `/api/v1`, and most are scoped to a store front:
  `/api/v1/locations/{location}/...`. Organization-wide ones are
  `/api/v1/organizations/{organization}/...`.

## Front-end routes are not API routes

The SPA's paths are flat — `/rankings`, `/heatmaps`, `/reviews` — and the store
front comes from the location selector in the shell, not from the URL. Only the
API is location-scoped. Do not assume `/locations/{id}/rankings` exists.

## Data hooks

React Query hooks in `resources/js/hooks` own the fetching, and mutations
invalidate their list key on success. Add a hook rather than calling `api`
directly from a page.

## An empty screen has to say which kind of empty it is

"Connect your Google Business Profile" shown to someone who connected it last
week reads as the application being broken, and they wait for content that is
never coming. `/reviews` said exactly that for four days while the nightly
sweep failed at Google's end.

`useGbpReadiness` in `components/common/gbp-connection-notice.tsx` reads
`/auth/google/connection` and separates the four cases the shop owner needs
different words for: no connection, a connection needing reconnection, a
connection that is healthy but whose `last_synced_at` is still null, and one
that is working. A Business Profile screen shows `GbpConnectionNotice` above
its content and leaves its own empty state to speak only for the last case.

`last_synced_at` is what tells "never arrived" from "nothing to show", so it is
load-bearing on that endpoint rather than decoration; `GoogleConnectionTest`
holds it.

Where an action would be queued and then fail for the same reason — posting to
Business Profile without a usable connection — disable it rather than letting
the person write the post first.

## There are five roles, and no "admin / member"

`viewer`, `staff`, `location_admin`, `org_admin`, `owner` — the middleware
alias `role:`, the policies and the invitations all speak these. A
specification asking for a two-role split is asking for something this product
does not have; showing two in the interface would name a role the rest of the
application cannot act on. `resources/js/pages/members.tsx` holds the labels a
shop owner reads.

`owner` is the seat the guards protect, because it is the one that can manage
billing. "The last admin" in a specification is the last owner here: it can be
neither demoted nor removed, and only another owner may act on an owner.

Removing yourself is refused outright (422). It is almost always the wrong row,
and leaving an organization deliberately is a different action.

## A seat is taken when the invitation goes out

`member.limit` counts accepted members plus invitations still outstanding.
Counting only accepted members would let an organization invite past its plan
and discover it when the last person tried to sign in — and the one refused
would be whoever accepted last, not whoever was invited last. Re-inviting an
address replaces its outstanding invitation rather than adding one, so it does
not pay for two seats.

## Toasts

`components/ui/toast.tsx`, no dependency. An error toast stays until it is
dismissed; a success one clears itself, because it confirms something the
reader just did. Outside the provider `useToast()` is a no-op rather than a
crash.
