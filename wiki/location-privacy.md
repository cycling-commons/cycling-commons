<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->

# Location & storage — how we avoid a consent banner

The map guesses roughly where you are so it can show *your* area first instead of a wall of every
region on Earth, and it lets you jump straight to a region by search or by tapping the map. None of
that needs a cookie banner. Here's exactly why.

!!! note "The short version"
    - Guessing your country from your browser's timezone happens **only in your browser** and is
      **stored nowhere** — not in a cookie, not in local storage, not on our servers.
    - We only remember a location choice when **you make it** — picking a region chip, a search
      result, or tapping a spot on the map. That's a first-party functional preference, the same
      class as remembering your chosen language.
    - There's no advertising, no cross-site tracking, and no device fingerprinting anywhere in this
      feature.
    - Our analytics (self-hosted **Umami**) is aggregate and cookieless, and its dashboard is public.
    - Put together, none of this crosses the threshold that requires a cookie/consent banner — so
      the map doesn't show you one.

## The timezone guess

When the map loads and doesn't already know where you are, it takes one reading your browser already
exposes to every website: your timezone (e.g. `Europe/Brussels`). It looks that up against a small
table of onboarded countries to make a guess — "this rider is probably in Belgium" — and uses that
guess to decide which region chips to show first.

That computation happens **entirely in your browser**. Nothing is sent to our servers to make the
guess, and the result isn't written anywhere — not to a cookie, not to `localStorage`, not to a
server-side session. Reload the page and it's computed fresh from scratch, every time. There is
nothing to opt out of because there is nothing stored.

If you're logged in, or you've already told the map roughly where you ride, we use that instead of
guessing — same rule applies: it's read to decide what to show you, never logged or tracked.

## What we do remember — and why that's fine without a banner

The only thing the map ever saves about your location is a choice **you made on purpose**: tapping a
region's chip, picking a result from search, or clicking a spot on the map to scope it. When you do
that, we save which region you picked in your browser's local storage, so the map opens to it next
time instead of asking again.

One honest caveat about search: what you type into the place-search box is sent, as you type it, to
`photon.komoot.io` — a third-party geocoding service (no API key, called directly from your browser)
that turns your text into place matches. That's a normal, necessary part of how search-as-you-type
works, and it's a different thing from the region choice above: the search text itself isn't something
*we* store, but it does leave your browser to a service we don't run.

That's a **first-party, functional** preference — the same category as remembering your interface
language or your last zoom level. It isn't used to track you across sites, it isn't shared with any
third party, it isn't linked to advertising in any way, and an inferred guess is never silently saved
on your behalf — only a choice you actually made. Storage that a feature strictly needs to remember
your own settings, and that you control by simply choosing something else, doesn't require a consent
banner under cookie-law rules built around tracking and advertising. If you never pick a scope, the
map remembers nothing about your location at all.

## Analytics: aggregate, cookieless, public

Cycling Commons runs its own self-hosted **Umami** analytics — no cookies, no per-visitor
fingerprinting, no data shared with ad networks or third-party trackers. It counts things like page
views in aggregate, not individual visitor journeys tied back to a person. Because it's aggregate and
cookieless, it doesn't trigger a consent requirement either, and we keep its dashboard **public** —
anyone can see the same traffic numbers we do.

## What this page does — and doesn't — cover

This page is specifically about the map's location and scope feature: the timezone guess, the region
chips, search, and click-to-scope. It is not a claim that Cycling Commons holds no personal data at
all — if you create an account, that account does hold ordinary account data (your email address, a
securely hashed password, and standard server access logs), the same as any site with a login. None
of that is used by, or shared with, the location feature described here.

## For the curious

The full engineering design — including the exact rules that keep this feature banner-free — lives in
the project's internal design docs, `2026-07-22-scope-selector-scale-design.md §F`. This page is the
plain-language notice that stands in for a consent banner, as that spec calls for.
