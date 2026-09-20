# atlas/demo — RETIRED PROTOTYPE

> **This is a retired prototype. Nothing here is served, and nothing here is current.**
>
> The public site is the Symfony application in `web/`. These pages were the
> hand-written HTML/JS prototype that came before it, and their copy was never
> updated afterwards: the privacy, licensing and "what we collect" statements on
> these pages are **superseded** by `/privacy` and `/licenses` on the live site,
> which are the authoritative versions. Read nothing here as a current claim
> about the service.
>
> The folder stays because it is still a **build input**, not because the pages
> are live: `make catalog-export` reads its fixtures, and `make wallonia-data`
> and `make pivot-data` write `atlas/demo/*-osm.js`. `REUSE.toml` carries the
> licensing for its media and fonts. Deleting it breaks those.
>
> The prototype as it stood is preserved in its own right on the `main` branch.

The original scope note follows, kept as it was written.

---

This folder becomes **[cyclingcommons.org](https://cyclingcommons.org)**: the focused public face of
the Commons. Keep it deliberately minimal — its job is to land the idea and point people deeper, not
to document everything.

**Scope (keep it tight):**
- What the Commons is, in one breath.
- Why it matters (open data, curated best-of, nothing personal collected).
- A call to action — contribute / explore / follow.
- Links **into the wiki** ([wiki.cyclingcommons.org](https://wiki.cyclingcommons.org)) for all depth.

**Do not** restate the manifesto, catalog, or governance here — link to the wiki so there is a single
source of truth. The wiki is the reference; this is the invitation.

**Tech:** to be decided. A single well-crafted static page, or a small Astro/Starlight site sharing the
wiki's markdown. No framework is committed yet.
