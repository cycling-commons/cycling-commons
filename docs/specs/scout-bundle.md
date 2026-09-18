<!-- SPDX-License-Identifier: AGPL-3.0-only -->
# Scout ride bundle

The file a Scout **phone** app exports after a ride, and `/scout/review` opens.
A bike computer (Garmin Edge, Hammerhead Karoo) writes a bare `.fit` file; a
phone can also take a photo and a short note at a tag, and a FIT file cannot
carry a photo. The bundle is the FIT file plus those photos and notes, in one
zip, so the review page stays the one place where tags become submissions
(moderation-and-contribution.md, Scout intake).

Decided with the owner on 2026-09-18.

**The Scout app's own specs are held in the Scout repository:**
[docs/SCOUT-BUNDLE.md](https://github.com/cycling-commons/scout/blob/main/docs/SCOUT-BUNDLE.md)
is the app side of this format. This page is what the review page reads;
when the two disagree, fix both in the same change.

## 1. What stays the same

Everything is read in the rider's browser, as with a bare `.fit`. The page
unpacks the zip in memory, reads the ride, and fills the cards. The route is
never uploaded. A card sends exactly what it sends today; its photos travel
through the ordinary photo uploader (photo-uploads.md), consent gate included,
**when the rider sends that card**. A photo on a card that is never sent never
leaves the phone.

## 2. Layout

```
scout-2026-09-12-1325.zip
├── ride.fit
├── scout.json
└── photos/
    ├── 20260912T144633Z-1.jpg
    └── 20260912T144633Z-2.jpg
```

Only the files named in `scout.json` are read. Anything else in the zip is
ignored. The zip file's own name is free; the one above is the suggested form.

## 3. `scout.json`

```json
{
  "format": "scout-bundle",
  "version": 1,
  "fit": "ride.fit",
  "tags": [
    {
      "at": "2026-09-12T14:46:33Z",
      "note": "Cobbles start after the bridge",
      "photos": ["photos/20260912T144633Z-1.jpg", "photos/20260912T144633Z-2.jpg"]
    }
  ]
}
```

| Field | Rule |
|---|---|
| `format` | Exactly `"scout-bundle"`. Anything else: the file is refused. |
| `version` | `1`. A higher version is refused with a message to update the page. |
| `fit` | Path of the ride inside the zip. |
| `tags[].at` | The **FIT timestamp of the record the tag was written on**, UTC, whole seconds, ISO 8601 with `Z`. This is the link: the page reads the same timestamp from the FIT file for every tag. |
| `tags[].n` | Optional. Only when two tags share one second: `0`, `1`, … in the order they appear in the FIT file. Absent means `0`. |
| `tags[].note` | Optional plain text, one line. It fills the card's name field, which the rider can still edit. Longer than 200 characters is cut at 200. |
| `tags[].photos` | Optional list of paths inside the zip. JPEG or WebP. |

A **surface stretch** is keyed on the tap that **starts** it.

## 4. Photos

- JPEG or WebP, at most 15 MB each (`PhotoProcessor::MAX_BYTES`).
- File names follow `<at in compact form>-<number>.<ext>`, for example
  `20260912T144633Z-1.jpg`. The name is for people; the link is the `photos`
  list, so a name that breaks the pattern still works.
- Photos may keep their EXIF, GPS included. The server strips all of it on
  upload, as for any photo.
- Each photo is uploaded with its tag's place (a stretch: its start point),
  because the upload refuses a photo with no place. The same holds for a
  photo a rider adds by hand on the review page.

## 5. What the page does with a mismatch

- An entry whose `at` (and `n`) matches no tag: counted in the panel's fact
  lines ("notes or photos match no tag"), never guessed onto the nearest tag.
- A photo path that is missing from the zip, too large, or not JPEG/WebP:
  skipped and counted the same way.
- A tag the rider removes from the review takes its photos with it; nothing
  uploads.

## 6. Limits

The page refuses a bundle whose `scout.json` is over 1 MB, whose ride is over
64 MB, or whose unpacked total is over 300 MB, before reading any of it. These
guard the browser, not the server; the server's own limits still apply to
every photo.

## 7. Where the code is

- Reader: `web/assets/map/scout-bundle.js` (pure: zip bytes in, ride bytes
  plus a note/photo index out; node-tested in `tests/js/scout-bundle.test.mjs`).
- Zip: `web/assets/lib/fflate-0.8.3.js` (MIT, vendored verbatim).
- Review page: `scout-review.js` opens `.fit` or `.zip`, fills the cards, and
  hands a card's photos to the uploader on send.
