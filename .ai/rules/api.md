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
