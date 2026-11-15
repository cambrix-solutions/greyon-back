# Testing flow

Manual walkthrough for Postman/Bruno using
`postman/greyon.postman_collection.json`. Run `php artisan migrate:fresh
--seed` first (see `README.md`) so the demo accounts/roles/features/
packages below exist.

All demo passwords are `password`. Cookie jar / "send cookies
automatically" should be on (default in both Postman and Bruno) — no
token to copy anywhere.

## 0. Setup

1. Import `postman/greyon.postman_collection.json`.
2. Check the collection's `base_url` variable is `http://greyon.info`.
3. The collection ships with `admin_package_id` (`1`), `location_package_id`
   (`2`), `kampot_package_id` (`3`), and `sihanoukville_package_id` (`4`)
   already pre-filled — these are the 4 seeded system packages' ids in
   `PackageSeeder`'s creation order (round 12, 2026-09-22: `Admin`,
   `Manager · Phnom Penh`, `Manager · Kampot`, `Manager · Sihanoukville`)
   and stay correct after any fresh `migrate:fresh --seed`. `admin_package_id`
   is the sole admin-role package now — capped by default (see section 13) —
   and `location_package_id`/`kampot_package_id`/`sihanoukville_package_id`
   are 3 functionally-identical location-scoped manager packages that
   differ only by name (see `PackageSeeder`'s docblock for why they're 3
   separate rows). Requests that specifically need one of these (e.g.
   **Create Admin — manager**, **Update Admin — Packages**) use them
   directly, so they work without any manual copying. Don't repoint them
   at whatever you most recently created in section 4 — that's what the
   plain `package_id` scratch variable is for (Packages CRUD, and the
   custom package created in section 12). If a request that references
   `{{package_id}}` 422s with a package name you didn't expect, it means
   that variable is still holding an older package's id — re-check what
   you last set it to before assuming it's a bug.
4. Same idea for `location_id` (`1`), `hotel_id` (`1`), `room_type_id`
   (`1`), and `rate_plan_id` (`1`) — pre-filled to Phnom Penh / Riverside /
   Riverside's "Deluxe King" / Riverside's "Flexible Rate", the seeders'
   first row of each, so **Get/Update/Delete Location**,
   **Get/Update/Delete Hotel**, **Get/Update/Delete Room Type**, and
   **Get/Update/Delete Rate Plan** all work immediately with zero manual
   copying. This does mean an unmodified **Delete Hotel**/**Delete Room
   Type**/**Delete Rate Plan** click targets real seed data (Location's
   delete is naturally guarded — Phnom Penh still has a hotel under it,
   so it `400`s instead) — harmless, `migrate:fresh --seed` puts it back,
   but worth knowing before clicking Send. Round 12 also seeded 2 new
   locations, Kampot (`3`) and Sihanoukville (`4`), with no hotels under
   either yet — but unlike locations themselves, the `kp@`/`sv@`
   managers *can* add hotels there (up to 3 each, see section 13), so
   this isn't a dead end the way it was in the first pass of round 12.
5. `admin_package_id` (`1`, **`Admin`**) is capped by default now —
   3 locations / 3 hotels per location (see section 13) — there's no
   separate "starter" tier anymore; `admin@greyon.com.kh` / `password`
   already demonstrates the caps directly, no second account needed.

## 1. Developer login + dashboard

1. **Developer → Auth → Login**. Send it.
   - Expect `200` with `{ "developer": { id, name, email, status } }` —
     no roles/featureKeys here, developers bypass all of that.
2. **Developer → Current Developer** (`/developer/me`). Expect the same
   shape back.
3. **Developer → Dashboard**. Expect `200` — no `permission:` gate on
   this route, being on the `developer` guard at all is the gate.
4. **Developer → Auth → Logout**, then **Current Developer** again —
   expect `401` in the `{ statusCode, message, error }` shape.
5. Log back in as developer before continuing.

## 2. Roles CRUD

1. **Developer → Roles → List Roles** — expect the 3 seeded roles:
   `admin` (`isGlobal: true`), `manager`, `hotel_admin`.
2. **Developer → Roles → Create Role** — `{ "name": "support", "description": "Read-only support seat" }`. Copy the returned `id` into `role_id`.
3. **Developer → Roles → Update Role** — PATCH its `description`.
4. **Developer → Roles → Delete Role** — expect `200` (not attached to
   any package yet).

## 3. Features CRUD

1. **Developer → Features → List Features** — expect the full catalog
   (12 admin keys + 4 public keys). The `locations` entry's
   `permissions` array should list all 5 seeded `locations_*`
   permissions (see section 3a) — features no longer carry child
   features of their own (`parentKey` was removed round 11; see
   section 3a).
2. **Developer → Features → Create Feature** — `{ "key": "reports", "label": "Reports" }`. Copy the returned `id` into `feature_id`.
3. **Developer → Features → Delete Feature** — expect `200`.

## 3a. Permissions CRUD (round 11)

The doc maker's sample: "package -> roles, package -> features,
package -> permissions, feature -> permissions." `App\Models\Permission`
is a real table now, one level under `Features` — see README's
"Permissions" section for the full design (in particular: holding a
feature does **not** automatically grant any permission catalogued
under it — each has to be picked explicitly on a package, same as
before this table existed).

1. **Developer → Permissions → List Permissions** — expect the 5
   seeded `locations_*` entries, each with `"featureKey": "locations"`.
2. **Developer → Permissions → Get Permission — locations_publish** —
   confirm the response shape: `id`, `key`, `label`, `description`,
   `featureKey`, `sortOrder`.
3. **Developer → Permissions → Create Permission** —
   `{ "key": "hotels_publish", "label": "Publish hotels", "featureKey": "hotels" }`
   — expect `201`. Copy the returned `id` into `permission_id`.
4. Retry with an unknown `featureKey` (e.g. `"not_a_real_feature"`) —
   expect `422` (`Rule::exists` against `features.key` — a normal
   validation failure, not the `400` "unknown key" shape `featureKeys`/
   `permissionKeys` use on **Packages**; the distinction matters, see
   step 3 of section 4).
5. **Developer → Features → Get Feature — Locations** (or re-run List
   Features from section 3) — confirm the new `hotels_publish`
   permission shows up under the **`hotels`** feature's `permissions`
   array, not `locations`'.
6. **Developer → Permissions → Delete Permission** on `permission_id`
   — expect `200` (not yet attached to any package).
7. **Developer → Features → Delete Feature** on `locations` (`id` 2 on
   a fresh seed) — expect `400 { "message": "Cannot delete a feature that is attached to a package..." }`
   (it's still checked first); this doesn't by itself prove the
   *permissions* guard — section 8's regression note below does, since
   deleting `locations` here never gets far enough to test it. If you
   want to see the permissions-guard message specifically, create a
   fresh unattached feature + a permission under it, then try deleting
   that feature — expect
   `400 { "message": "Cannot delete a feature that has permissions catalogued under it..." }`.

## 4. Packages CRUD

1. **Developer → Packages → List Packages** — expect the 4 seeded
   system packages (round 12: `Admin`, `Manager · Phnom Penh`,
   `Manager · Kampot`, `Manager · Sihanoukville`), each `isSystem: true`
   with an `adminCount` of `1`.
2. **Developer → Packages → Create Package** —
   `{ "name": "Manager · Booking Pro", "roles": ["manager"], "featureKeys": ["dashboard","hotels","rooms","rates","bookings"] }`.
   Copy the returned `id` into `package_id`.
3. Try **Create Package** again with an unknown key added to
   `featureKeys` (e.g. `"not_a_real_key"`) — expect
   `400 { "message": "Unknown feature keys: not_a_real_key" }` (matches
   the spec's "Unknown feature keys → 400," note this is 400 not the
   422 a normal validation failure returns — try omitting `roles`
   entirely to see that contrast). Retry with an unknown key in
   `permissionKeys` instead (e.g. `"not_a_real_permission"`) — expect
   the analogous `400 { "message": "Unknown permission keys: not_a_real_permission" }`
   (round 11 — same 400-not-422 distinction as `featureKeys`, contrast
   with `Permissions → Create Permission`'s unknown-`featureKey` case,
   which is a normal `422` since it's a single foreign-key-style field,
   not an array of catalog keys).
4. **Developer → Packages → Get Package — Admin (view limits)** (`id`
   `1` on a fresh seed) — confirm `featureKeys` (12 module-level keys)
   and `permissionKeys` (all 5 `locations_*` permissions) are two
   separate arrays (round 11) — previously the 5 sub-keys lived inside
   `featureKeys` itself (Round 8) — and (round 12) a `limits` array
   with `locations: 3` and `hotels_per_location: 3`.
5. **Developer → Packages → Duplicate Package** (on the package from
   step 2) — expect a new row named "... (copy)", `isSystem: false`.
6. **Developer → Packages → Delete Package** on the *seeded* `Admin`
   package — expect
   `400 { "message": "Cannot delete a system package." }`.
7. Delete the copy from step 5 instead — expect `200`.

## 5. Admin account management + package assignment

An admin's only real attribute is which packages it holds — there's no
top-level `roles`/`featureKeys`/`locationIds`/`hotelIds` on the admin
itself; each entry in its `packages` array carries its own (see
`App\Models\AdminPackage`, the `admin_package` pivot).

1. **Developer → Admins → List Admins** — expect the 4 seeded admins
   (round 12: `admin@`, `pp@`, `kp@`, `sv@`), each with a `packages`
   array and nothing else scope-related at the top level. Open one
   manager account (e.g. `pp@`) and confirm its single `packages[0]`
   entry has `roles`, `featureKeys`, `locationIds`, `hotelIds` all
   present on it, and that `locationIds` matches its city (`pp@` → `[1]`,
   `kp@` → `[3]`, `sv@` → `[4]`).
2. **Developer → Admins → Create Admin** —
   `{ "name": "PP Manager 2", "email": "pp2@greyon.com.kh", "packages": [{ "packageId": {{location_package_id}} }] }`
   with no `locationIds` on that entry — expect `422` with a
   `locationIds is required for package "Manager · Phnom Penh" — it
   grants a location-scoped role` error (spec parity: "Effective roles
   include manager → locationIds required," now checked per package
   entry against that package's roles' `scope`, not a flat `role`
   field or a flat admin-level locationIds).
3. Retry with `"packages": [{ "packageId": {{location_package_id}}, "locationIds": [1] }]`
   — expect `201`. Copy the returned `id` into `admin_id`.
4. **Developer → Admins → Get Admin** with `admin_id` — expect
   `packages[0].roles` to include `"manager"`, `packages[0].featureKeys`
   matching the package, `packages[0].locationIds: [1]`.
5. **Developer → Admins → Update Admin — Packages** (`PUT .../packages`)
   — swap to `{ "packages": [{ "packageId": {{kampot_package_id}} }] }`
   without `locationIds` on that entry — expect `422`
   (`locationIds is required for package "Manager · Kampot"...`) — same
   validation, a different city's package.
6. Retry with `"locationIds": [3]` added to that entry — expect `200`,
   and confirm the admin's `packages` array now has only the one
   `Manager · Kampot` entry (`Manager · Phnom Penh` was replaced, not
   merged — `.../packages` replaces the whole set).
7. **Bonus — one admin, two independently-scoped packages**: repeat
   step 5 with
   `"packages": [{ "packageId": {{location_package_id}}, "locationIds": [1] }, { "packageId": {{kampot_package_id}}, "locationIds": [3] }]`
   — expect `200` with both entries in the response, each with its own
   `locationIds`. This is the thing the old flat
   `admins.location_ids`/`hotel_ids` columns couldn't express — one
   admin scoped to Phnom Penh *and* Kampot at once, through two
   separate package assignments. This is exactly the **Update Admin →
   Packages (two independently-scoped packages)** saved request.
   (Round 12 note: no seeded package uses a *hotel*-scoped role
   anymore — see step 9 below if you want to exercise that path
   specifically, since `hotel_admin` is still a real seeded role, just
   with no package built on it right now.)
8. **Developer → Admins → Delete Admin** with `admin_id` — expect `200`.
9. **Optional — hotel-scope validation, built ad hoc**: `hotel_admin`
   (`scope: hotel`) is still seeded in `RoleSeeder`, just unused by any
   package after round 12. To see the `hotelIds`-required check fire,
   edit **Developer → Packages → Create Package — Manager Booking
   Pro**'s body to
   `{ "name": "Hotel Scope Demo", "roles": ["hotel_admin"], "featureKeys": ["dashboard","hotels","rooms","rates","bookings"] }`
   and send it — `201`, copy the `id` into `package_id`. Then
   **Developer → Admins → Update Admin — Packages** on any `admin_id`
   with `{ "packages": [{ "packageId": {{package_id}} }] }` (no
   `hotelIds`) — expect `422` (`hotelIds is required for package "Hotel
   Scope Demo"...`), same mechanism as steps 2/5 above, just proving it
   also fires for a hotel-scoped role, not only a location-scoped one.

## 6. Acceptance test — non-developer can't reach any `/developer/*` route

There's nothing to click through here — it's structural. `admin@`/`pp@`/
etc. authenticate on the `admin` guard; `/developer/*` routes require
`auth:developer`. Logging in as `admin@` and then hitting
**Developer → Admins → List Admins** with that cookie jar returns `401`,
not `403` — Laravel never even looks at `admins` for a `developer`-guard
route. Same for a `customer`/`guest` hitting any `/admin/*` or
`/developer/*` route.

## 7. Admin-side permission gate (`permission:<key>`)

1. Log in as **admin@** (`Admin → Auth → Login`).
2. **Admin → Dashboard** — expect `200` (`Admin` grants `dashboard`).
3. Log in as **pp@** (Manager · Phnom Penh) and retry — also `200`
   (`dashboard` is in that package's feature set too).
4. (Optional) In Postman, manually hit a URL your collection doesn't
   have a saved request for yet, gated by a key neither account's
   package grants (e.g. `settings`, once a real endpoint exists there)
   — expect `403 { "message": "Missing permission: settings" }`.

## 8. Locations & Hotels (admin)

Seeded data (round 12 added 2 locations): Location 1 = Phnom Penh,
Location 2 = Siem Reap, Location 3 = Kampot, Location 4 = Sihanoukville
(all `published`, but only Phnom Penh and Siem Reap have hotels under
them — Kampot and Sihanoukville start empty); Hotel 1 = Riverside
(under Phnom Penh), Hotel 2 = Angkor (under Siem Reap). `pp@` has
`locationIds: [1]`, `kp@` has `locationIds: [3]`, `sv@` has
`locationIds: [4]` — these are real foreign keys, not placeholders.
Round 12 dropped the hotel-scoped demo accounts (`hotel@`/`angkor@`) —
see section 5's optional step 9 if you want to exercise a hotel-scoped
role specifically.

**Create is feature-gated, not scope-gated (2026-09-15)**: a developer
can always create; an admin can create iff their effective packages
grant that resource's feature (`locations`/`hotels`/`rooms`) — the
same `permission:<key>` gate that already applies to every other
action. **Round 12 layered a quantity limit on top for locations/
hotels specifically** (see section 13): `pp@`/`kp@`/`sv@` all hold a
`Manager · <City>` package capped at `locations: 0` (can't create a
new destination at all — steps 9/11 below demonstrate that, which
replaces what this section used to show with `pp@` creating a
*location* outside their own scope and 404ing on it themselves; that
demonstration still exists in the app — see section 12, steps 9–10,
which uses an ad hoc *uncapped* location-scoped package to show it) but
`hotels_per_location: 3` — a manager *can* add hotels within their own
location, same cap `Admin` gets (step 8 below). `GET`/`PATCH`/`DELETE`
are unaffected by any of this — they're still scope-filtered (404 if
out of scope) exactly as before, and `DELETE` on locations/hotels is
still global-seats-only.

**Permissions on `PATCH /admin/locations/:id` (2026-09-15, reworked
round 11)**: on top of the parent `locations` feature gate (still
required just to reach these routes at all), 3 of the 5 permissions
catalogued under `locations` (`App\Models\Permission`, see section 3a)
gate specific fields — `locations_list` for content fields
(name/slug/description/heroImage/gallery/highlights), `locations_publish`
for `status`, `locations_seo` for `seoTitle`/`seoDescription`.
**Round 12 change**: every seeded package (`Admin` and all 3
`Manager · <City>` packages) now holds **all 5** permission keys —
"no adding properties, only others" was interpreted as broad
management rights minus creation, so `pp@`/`kp@`/`sv@` can update
`status`/`seoTitle` same as a global seat now (unlike the old
`Manager · Content+`, which only had 2 of 5 and 403'd on those
fields). Step 7a below demonstrates the field gate still exists using
an ad hoc *partial*-permission package, since no seeded account 403s
on this anymore.

1. Log in as **admin@** (global). **Admin → Locations → List Locations**
   — expect all 4, each with a `hotelCount` (`2` and `4` will show `0`).
2. **Admin → Locations → Create Location** —
   `{ "name": "Kep", "slug": "kep" }` — expect `201`.
3. **Admin → Locations → Delete Location** on Phnom Penh (id `1`) —
   expect `400 { "message": "Cannot delete a location that still has
   hotels under it." }`. Delete the Kep one from step 2 instead —
   expect `200`.
4. **Admin → Hotels → List Hotels** — expect Riverside and Angkor (not
   Kampot/Sihanoukville — no hotels there yet), each with a nested
   `location` object and a `coordinates: {lat,lng}` object.
5. Log out, log back in as **pp@** (Manager · Phnom Penh,
   `locationIds: [1]`).
6. **Admin → Locations → List Locations** — expect only Phnom Penh.
   **Admin → Hotels → List Hotels** — expect only Riverside.
7. **Admin → Locations → Get Location** on Siem Reap (id `2`) directly
   — expect `404 { "message": "Not found." }`, **not** `403` (spec's
   "resource exists but out of scope → 404, don't leak existence"
   rule). Same for **Admin → Hotels → Get Hotel** on Angkor (id `2`).
7a. **Field-gating still works, demonstrated ad hoc**: since `pp@` now
    holds all 5 `locations_*` permissions, build a partial-permission
    package to see the 403 — **Developer → Packages → Create Package —
    with permissionKeys**, edit its body to
    `{ "name": "Partial Perms Demo", "roles": ["manager"], "featureKeys": ["dashboard","locations"], "permissionKeys": ["locations_list"] }`
    (log in as `dev@` first), then assign it to a fresh admin scoped to
    Phnom Penh (**Developer → Admins → Create Admin**, adapting the
    body from section 5 step 2/3) and log in as that admin. **Admin →
    Locations → Update Location** on Phnom Penh with
    `{ "description": "Updated" }` — expect `200` (has `locations_list`).
    Retry with `{ "status": "archived" }` — expect `403
    { "message": "Missing permission: locations_publish" }`. Retry with
    `{ "seoTitle": "New title" }` — expect `403
    { "message": "Missing permission: locations_seo" }`. A mixed request
    (`{ "description": "...", "status": "archived" }`) still `403`s
    atomically — nothing in it is applied, same as before round 12.
8. **Admin → Hotels → Create Hotel** with
   `{ "locationId": 1, "name": "PP Hotel 1", "slug": "pp-hotel-1" }` —
   expect `201` (round 12: `pp@`'s package grants `hotels_per_location:
   3`, and Phnom Penh already has 1 seeded hotel — Riverside — so this
   is hotel #2 of 3 for that location). Repeat once more with a
   different `slug` (e.g. `pp-hotel-2`) — expect `201` again (hotel #3
   of 3). A 3rd attempt with yet another `slug` — expect `403
   { "message": "This location already has the maximum 3 hotel(s)
   allowed by your package." }`.
9. **Admin → Locations → Create Location** —
   `{ "name": "Battambang", "slug": "battambang" }` — expect `403
   { "message": "Location limit reached (0/0) for your package(s)." }`
   (round 12: `pp@`'s package still caps `locations` at `0` — "no
   adding [new] properties" only ever meant destinations, not hotels).
   This replaces the old round-7 demonstration ("create outside scope,
   201, then 404 viewing it") for the default seed — see section 12,
   steps 9–10, for that behavior with an uncapped package.
10. Log out, log back in as **kp@** (Manager · Kampot, `locationIds: [3]`).
11. **Admin → Hotels → List Hotels** — expect none yet (Kampot starts
    with zero, unlike Phnom Penh). **Admin → Locations → List
    Locations** — expect only Kampot.
12. **Admin → Hotels → Create Hotel** with
    `{ "locationId": 3, "name": "Kampot Hotel 1", "slug": "kampot-hotel-1" }`
    — expect `201`. Repeat twice more with different `slug`s (e.g.
    `-2`/`-3`) — expect `201` both times (this is the cleaner version of
    step 8's demo, since Kampot starts empty: exactly 3 succeed). A 4th
    attempt — expect the same `403 "...maximum 3 hotel(s)..."` as step 8.
13. **Admin → Locations → Update Location** on Kampot (id `3`) —
    `{ "description": "Updated by kp" }` — expect `200` (round 12:
    managers can still fully edit their own location's content).
14. **Admin → Hotels → Delete Hotel** on Riverside, still logged in as
    `kp@` — expect `403 { "message": "Only a global seat can do
    this." }` — `destroy()` checks `isGlobal()` before scope, so this
    is `403` even though Riverside is also out of `kp@`'s scope
    (unlike `show`/`update`, which check scope first and 404).

## 9. Room types (admin)

New resource this round (`room_types`, perm `rooms`). Seeded: Riverside
has "Deluxe King" (id `1`) and "Standard Twin" (id `2`); Angkor has its
own "Deluxe King" (id `3`) — same name, different hotel, since
`slug` is unique per-hotel not globally.

Round 12 note: room types have no `package_limits` resource key at all
(only `locations`/`hotels_per_location` are wired, see section 13), so
none of round 12's changes affect anything below — a location-scoped
manager can still create room types freely, including on a hotel
outside their own scope.

1. Log in as **admin@** (global). **Admin → Room Types → List Room
   Types** — expect all 3.
2. **Admin → Room Types → Create Room Type** —
   `{ "hotelId": 1, "name": "Family Suite", "slug": "family-suite", "maxAdults": 2, "maxChildren": 2, "maxGuests": 4 }`
   — expect `201`. Copy the `id` into a `room_type_id` variable if you
   add one (not pre-wired in the collection).
3. Log out, log back in as **pp@** (Manager · Phnom Penh,
   `locationIds: [1]` → Phnom Penh → Riverside only; round 12 dropped
   the hotel-scoped demo accounts, but a location-scoped package still
   resolves down to the hotels under it via `canAccessHotel()`, so this
   is an equivalent scoped-admin test).
4. **Admin → Room Types → List Room Types** — expect only Riverside's
   (Deluxe King, Standard Twin, Family Suite) — not Angkor's.
5. **Admin → Room Types → Get Room Type** on Angkor's Deluxe King
   (id `3`) — expect `404`, same anti-leak rule as locations/hotels.
6. **Admin → Room Types → Create Room Type** with `{ "hotelId": 2, ... }`
   (Angkor — outside pp@'s scope) — expect `201` anyway: same
   feature-only create rule as locations/hotels (pp@'s package grants
   `rooms`, and room types have no quantity limit to block this).
   Confirm the immediate follow-up **Get Room Type** on it 404s for
   pp@ — created it, can't see it.
7. **Admin → Room Types → Delete Room Type** on Riverside's Deluxe King
   — expect `403` (destroy is global-only, same as locations/hotels).

## 10. Rate plans (admin)

New resource this round (`rate_plans`, perm `rates`) — one level below
Room types in the `Hotel → RoomType → RatePlan → ...` chain. Seeded: 4
rate plans — Riverside's Deluxe King (room type id `1`) has 2
("Flexible Rate" id `1`, "Non-Refundable Rate" id `2`, proving one room
type can hold several plans), Riverside's Standard Twin (room type id
`2`) has "Standard Rate" (id `3`), Angkor's Deluxe King (room type id
`3`) has "Standard Rate" (id `4`).

**Create-response defaults fix**: `basePrice`/`name`/`roomTypeId` are
the only required fields — omitted `taxPercent`/`serviceFeePercent`/
`status` now correctly come back as `0`/`0`/`"draft"` (their DB column
defaults), not `null`. Found while building this resource and fixed on
all 4 content controllers (locations/hotels/room types/rate plans) —
see README's "Create-response defaults bug" note.

1. Log in as **admin@** (global). **Admin → Rate Plans → List Rate
   Plans** — expect all 4, each with a nested `roomType` object
   (id/name/slug/hotelId).
2. **Admin → Rate Plans → Create Rate Plan** —
   `{ "roomTypeId": 2, "name": "Weekend Special", "basePrice": 45.00 }`
   — expect `201` with `taxPercent: 0`, `serviceFeePercent: 0`,
   `status: "draft"` in the response (not `null`).
3. Log out, log back in as **pp@** (Manager · Phnom Penh,
   `locationIds: [1]` → Phnom Penh → Riverside only). Rate plans have
   no `package_limits` resource key either, so round 12 doesn't affect
   anything in this section.
4. **Admin → Rate Plans → List Rate Plans** — expect only Riverside's
   3 (ids `1`, `2`, `3`) — not Angkor's `4`.
5. **Admin → Rate Plans → Get Rate Plan** on Angkor's (id `4`) directly
   — expect `404`, same anti-leak rule as locations/hotels/room types.
6. **Admin → Rate Plans → Create Rate Plan** with
   `{ "roomTypeId": 3, "name": "PP-created plan", "basePrice": 99.00 }`
   (Angkor's room type — outside pp@'s scope) — expect `201` anyway:
   same feature-only create rule as locations/hotels/room types (pp@'s
   package grants `rates`). Confirm the follow-up **Get Rate Plan** on
   it 404s for pp@ — created it, can't see it.
7. **Admin → Rate Plans → Update Rate Plan** on Riverside's Flexible
   Rate (id `1`) — `{ "basePrice": 68.50 }` — expect `200`.
8. **Admin → Rate Plans → Delete Rate Plan** on id `1` — expect `403`
   (destroy is global-only, same as locations/hotels/room types).
9. **Admin → Rate Plans → Create Rate Plan** with an unknown
   `roomTypeId` (e.g. `9999`) — expect `422`
   (`Rule::exists` validation, same pattern as `locationIds`/`hotelIds`
   from Round 7.5).

## 11. Public locations/hotels (no auth)

1. **Public → Locations → List Locations** — no cookie needed — expect
   all 4 seeded locations (Phnom Penh, Siem Reap, Kampot, Sihanoukville
   — round 12 seeded all of them `published`), each with a `hotelCount`
   counting only published hotels (Kampot/Sihanoukville will show `0`).
2. **Public → Locations → Get Location** by slug (`/locations/siem-reap`)
   — expect `200`. Try a slug that doesn't exist, or a `draft`/
   `archived` one — expect `404`.
3. **Public → Hotels → List Hotels** — expect Riverside + Angkor.
   Add `?locationSlug=siem-reap` — expect only Angkor.
   Add `?featured=1` — expect both (both seeded `featured: true`).
4. **Public → Hotels → Get Hotel** by slug (`/hotels/riverside`) —
   expect `200`. A hotel under a non-published location, or a
   non-published hotel, must 404 even if its own `status` looks
   published — the endpoint checks both.

## 12. Role scoping is schema-driven, not name-matched

Proves `roles.scope` (`none`|`location`|`hotel`) — not the role's
`name` — is what `AccessService` actually reads, by creating a
brand-new role the codebase has never heard of and confirming it gets
real scoping behavior with zero code changes.

1. Log in as **dev@** (`Developer → Auth → Login`).
2. **Developer → Roles → Create Role** —
   `{ "name": "district_supervisor", "description": "...", "scope": "location" }`.
   Copy the returned `id` into `role_id`. Confirm the response includes
   `"scope": "location"`.
3. **Developer → Packages → Create Package** —
   `{ "name": "District Supervisor Pack", "roles": ["district_supervisor"], "featureKeys": ["dashboard","locations","hotels"] }`.
   Copy the returned `id` into `package_id`.
4. **Developer → Admins → Create Admin** —
   `{ "name": "Siem Reap Supervisor", "email": "srsup@greyon.com.kh", "password": "password", "packages": [{ "packageId": <id from step 3> }] }`
   with no `locationIds` on that entry — expect
   `422 { "packages": ["locationIds is required for package \"District Supervisor Pack\" — it grants a location-scoped role."] }`.
   This message and the check behind it never mention `manager` by
   name — it's driven by the role's `scope`.
5. Retry with `"locationIds": [2]` (Siem Reap) added to that package
   entry — expect `201`. Copy the `id` into `admin_id`.
6. Log out of the `admin` guard if logged in, then **Admin → Auth →
   Login** as `srsup@greyon.com.kh` / `password`.
7. **Admin → Locations → List Locations** — expect only Siem Reap,
   even though nothing in the codebase has ever compared a role name
   to `"district_supervisor"`.
8. **Admin → Hotels → List Hotels** — expect only Angkor.
9. **Admin → Hotels → Create Hotel** with `"locationId": 1` (Phnom
   Penh — outside this admin's `locationIds: [2]`) — expect `201`
   anyway: create only checks the `hotels` feature (which "District
   Supervisor Pack" grants), same feature-only rule section 8 covers
   for the built-in roles — proving that policy is *also*
   schema-driven, not special-cased to `manager`/`hotel_admin`.
10. **Admin → Locations → Get Location** on Phnom Penh (`1`) — expect
    `404`, same anti-leak rule as every other role: creating it didn't
    put it in scope, only `locationIds` does. This is the real proof
    that `scope` still gates *view/update* dynamically even though
    create no longer checks it at all.

## 13. Package quantity limits (admin)

`App\Models\PackageLimit` — a package can cap *how many* of a resource
an admin holding it may create, on top of the feature/scope gates
above. **Round 12 (2026-09-22) changed the default lineup so every
seeded package is capped now** — there's no longer an unlimited
package out of the box:

- **`Admin`** (`admin_package_id`, `1`) — `locations: 3`,
  `hotels_per_location: 3`. `admin@greyon.com.kh` holds it and is the
  account to test this with (no separate "starter" account anymore —
  the default admin tier *is* the capped one).
- **`Manager · Phnom Penh` / `Manager · Kampot` / `Manager ·
  Sihanoukville`** (`location_package_id`/`kampot_package_id`/
  `sihanoukville_package_id`, `2`/`3`/`4`) — `locations: 0` on all 3
  (can't create a new destination at all — "no adding [new]
  properties"), but `hotels_per_location: 3`, same as `Admin` — a
  manager *can* add hotels within their own location. Their seeded
  admins `pp@`/`kp@`/`sv@` are already exercised in section 8, steps
  8–9 (`hotels_per_location: 3`, succeeding then blocked) and step 9
  (`locations: 0`, blocked outright); this section focuses on
  `admin@`'s `3`/`3` caps.

1. **Admin → Auth → Login — admin**.
2. **Admin → Locations → Create Location — capped** three times in a
   row (edit the `slug` each time, e.g. `capped-loc-1`/`-2`/`-3`, so
   the unique-slug validation doesn't get in the way) — expect `201`
   each time. Note each response's `createdByAdminId` matches this
   admin's id.
3. **Admin → Locations → Create Location — capped, 4th (expect 403
   limit reached)** — expect
   `403 { "message": "Location limit reached (3/3) for your package(s)." }`.
4. Pick one of the 3 locations you just created and note its id (check
   **Admin → Locations → List Locations**, or the response from step
   2). **Admin → Hotels → Create Hotel — capped, 1st on a location**
   is pre-filled with `"locationId": 3` — edit it to match the id you
   noted if your location landed on a different id (note: id `3` is
   also Kampot's own location id now — that's fine, this test is about
   the *hotel* cap on whichever location you point it at, not about
   Kampot specifically). Expect `201`. Repeat 2 more times against the
   *same* `locationId` (editing the hotel's `slug` each time) — expect
   `201` both times too (cap is `3` now, not `1`).
5. **Admin → Hotels → Create Hotel — capped, 4th on same location
   (expect 403 limit reached)** — same `locationId` as step 4 — expect
   `403 { "message": "This location already has the maximum 3 hotel(s) allowed by your package." }`.
6. Retry **Create Hotel — capped, 1st on a location**, but edit
   `locationId` to a *different* one of your 3 capped locations —
   expect `201`. This is the "per-target-location, not per-admin" rule:
   the cap resets per location, it isn't a total-hotels-for-this-admin
   count.
7. **Regression check — a developer-built package can still be
   genuinely unlimited**: log in as `dev@` and **Developer → Packages →
   Create Package**, omitting `limits` entirely — assign it to a fresh
   admin (or temporarily swap `admin@`'s package via **Update Admin →
   Packages**) and confirm `POST /admin/locations` never hits a `403`
   no matter how many you create. This proves the *mechanism* is still
   fully general — round 12 only changed what the seeded packages
   default to, not what's possible.
8. **Developer → Packages → Get Package — Admin (view limits)** —
   expect a `limits` array with both
   `{ "resourceKey": "locations", "maxCount": 3 }` and
   `{ "resourceKey": "hotels_per_location", "maxCount": 3 }`.
9. **Developer → Packages → Create Package — duplicate resourceKey in
   limits (expect 400)** — expect
   `400 { "message": "Duplicate resourceKey in limits: locations" }`.
10. **Developer → Packages → Create Package — with limits (capped)** —
    expect `201` with a `limits` array containing the one
    `locations: 2` row. Copy the returned `id` into `package_id`.
11. **Developer → Packages → Update Package** with a body of
    `{ "limits": [] }` on that `package_id` — expect `200` with an
    empty `limits` array (confirms `PATCH` **replaces** the whole set,
    same as `featureKeys` — it doesn't merge or leave old rows behind).
12. **Developer → Packages → Duplicate Package** on
    `{{admin_package_id}}` — expect `201` with both limit rows copied
    onto the new `"Admin (copy)"` package.

## 14. Custom roles (admin, Access -> Roles)

Needs the `roles` feature (seeded for the org Admin seat; `php artisan migrate` adds it to an existing DB). Log in with `POST /admin/login` first.

| Call | Expect |
|---|---|
| `GET /admin/roles/options` | `scopes` (location, hotel) + `features` the logged-in admin can grant (never `features`). |
| `POST /admin/roles` `{"name":"Front desk","scope":"hotel","featureKeys":["bookings","rooms"]}` | 201, `role.isSystem=false`. |
| same, with a feature you do not hold | 422 on `featureKeys`. |
| `scope` = `none` or missing | 422 on `scope`. |
| `GET /admin/roles` | custom roles + the system Manager / Hotel desk seats (read-only). |
| `PATCH /admin/roles/{id}` | updates name, description, features. Changing `scope` after people hold it: 422. |
| `PATCH` / `DELETE` on a system seat | 404. |
| `DELETE /admin/roles/{id}` while assigned | 400. |

Then `GET /admin/seats` lists the new role, and `POST /admin/team` can assign it with `locationIds` (scope `location`) or `hotelIds` (scope `hotel`).

## What this doesn't cover yet

The bookings-scope acceptance tests (a location-scoped manager → only
their city's bookings, `admin@` → all bookings) and the rest of the
public API (availability search, guest booking, enquiries, news,
portfolios) aren't testable until those endpoints exist —
availability/rate-calendar and bookings are the next milestones.

**Round 12 (2026-09-22) testing gaps, worth knowing about**: the
default seed no longer includes a *hotel*-scoped demo account (`hotel_admin`
role, `scope: hotel`) or a package with only a *partial* permission
grant — both are still fully supported by the code (see section 5 step
9 and section 8 step 7a for how to build one ad hoc), they just don't
have a ready-made seeded account anymore now that all 4 seeded
packages are either global (`Admin`) or location-scoped-with-full-
permissions (`Manager · <City>`).
