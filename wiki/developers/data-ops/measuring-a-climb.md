<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->

# Measuring a climb

Installed tiles are not a measurement. This page is the last hop: turning a DEM
the previous page put on disk into two published numbers, an average and a
steepest stretch, and the long argument about why only one of them was ever in
trouble.

!!! info "Read these in order"

    1. [What a DEM is, and what it gets wrong](elevation-dem-concepts.md).
    2. [Building elevation tiles](elevation-tiles.md): the pipeline and the
       install.
    3. **This page**: measuring, and the faults no source can fix.

The design record is
[climb-elevation.md](https://github.com/cycling-commons/cycling-commons/blob/main/docs/specs/climb-elevation.md).

## Faults no elevation source can fix

While measuring, the comparison tool flagged problems in the *geometry*:

- **Côte de la Redoute** runs 361 m past its summit, and those metres descend.
  Averaged over the stored line the climb is 6.80%; trimmed at the top it is
  8.61%. **Overshooting the summit understates a climb by 1.8 points**, several
  times the gap between the DEM sources we agonised over.
- **Côte de la Roche-aux-Faucons** is stored **backwards**, starting at 242 m and
  ending at 181 m.

Three of seven stored climbs had an endpoint defect. The reversed one is the
dangerous case: "measure to the highest point" puts the summit at index 0, giving
a length of 0 m, a gain of 0 m and 0%, numbers that look unremarkable in a
database column.

The lesson generalises well beyond elevation. **Getting the source right and the
endpoints wrong still publishes a wrong number**, and a validation that fails to
zero rather than to an error is worse than no validation.

## Measuring a climb, end to end

Everything above is about the *source*. This is the method built on top of it,
as it finally stands, and the order in which it went wrong is more instructive
than the finished shape.

A climb becomes numbers in six steps:

<!-- CODE-ILLUSTRATIVE the measurement pipeline, not a source file -->
```
foot + summit
      ↓  routing engine          WHERE the road goes
a polyline
      ↓  trim at the highest point   the line must END at the summit
the climb
      ↓  resample to 200 points
      ↓  DEM lookup              HOW HIGH each point is
elevations
      ↓
length · gain · average · steepest stretch
```

The first four steps were never the hard part. The last one was, and only one of
the two published figures was ever in trouble.

### One of these two figures is robust and the other is not

**The average is safe.** It is a sum over hundreds of samples, and a Digital
Surface Model's errors, canopy, cuttings, roofs, are scattered, so they cancel.
Measured against published figures for six Swiss passes, ours agreed to within a
few tenths: Nufenen 13.27 km at 8.5% against a published 13.4 km at 8.5%.

**The steepest stretch is fragile**, for a reason worth internalising: **it is an
extreme-value statistic, and on a surface model the extreme is almost always the
artifact.** An average asks "what is this road typically like", which noise
cancels out of. A maximum asks "what is the single worst thing in this data",
which is a question about the noise.

That produced a published **35%** on the Grimsel, the Susten and the Klausen: three
Alpine passes, three identical figures, none of them a measurement. All three
were hitting a `min(35.0, …)` clamp, which is the worst way for this to fail,
because a clamped artifact does not look like an artifact. It looks like a
plausibly steep pass.

### The tell: it got worse as the data got better

The obvious suspect was sampling. The profiler takes a fixed 200 samples
whatever the length, so a 26 km climb samples every 130 m and a 100 m window
spans less than one interval. Plausible, and **wrong**: resampling the Furka at
10 m, 20 m, 30 m and 50 m moved the answer by less than half a point.

The real tell was the opposite of what a bug usually does. **The raw maximum got
worse as sampling improved.** On the Grimsel it went from 35% to 77% when
spacing tightened from 50 m to 20 m. Nothing that reads the road behaves like
that, finer sampling was finding more *spikes*, not more road. That single
observation is what identified the fault: if refining your input degrades your
answer, you are measuring your noise.

Two causes came out of it.

**The window was finer than the data could answer.** The rule earlier on this
page, a bin is never narrower than about four DEM cells, puts GLO-30's floor
at 120 m. The window was 100 m. It had been below the source's resolution from
the first day; short, unroofed Ardennes climbs simply never exposed it.

**And a clamp was hiding the damage.** The code capped the published figure at
35%. Grimsel, Susten and Klausen all published exactly 35%, which looks like
three steep passes and is really one ceiling that three artifacts hit. *Any time
several independent things report the identical value, suspect that you are
reading a limit rather than a measurement.*

### What is published now

**The 95th percentile of sliding 250 m windows.** Wider than the source's
resolution floor, and a statistic that a single bad cell cannot move.

<figure class="gis-fig"><svg viewBox="0 0 660 350" role="img" aria-labelledby="pct-t pct-d" xmlns="http://www.w3.org/2000/svg"><title id="pct-t">Every sliding window on one climb, ordered by gradient</title><desc id="pct-d">A curve showing the gradient of every two hundred and fifty metre sliding window along the Furka, ordered from gentlest on the left to steepest on the right. For the first ninety five percent of windows the curve rises slowly and smoothly from about three percent to about ten percent, which is the road. In the last few percent it turns sharply upward and shoots to twenty four percent at the extreme right. That tail is tinted and labelled as artifacts: cuttings, rock faces and roofs. A vertical marker at the ninety fifth percentile shows the published figure of ten percent, sitting at the top of the smooth part of the curve, while the maximum at the far right is more than twice it.</desc>
<line class="gis-muted" x1="70" y1="278" x2="628" y2="278"/>
<line class="gis-muted" x1="70" y1="278" x2="70" y2="60"/>
<line class="gis-muted" stroke-dasharray="2 5" x1="70" y1="194" x2="628" y2="194"/>
<line class="gis-muted" stroke-dasharray="2 5" x1="70" y1="110" x2="628" y2="110"/>
<text class="gis-label-sm" x="62" y="282" text-anchor="end">0%</text>
<text class="gis-label-sm" x="62" y="198" text-anchor="end">10%</text>
<text class="gis-label-sm" x="62" y="114" text-anchor="end">20%</text>
<path class="gis-fill-clay" stroke="none" fill-opacity=".35" d="M 592 278 L 592 191 L 604 177 L 612 144 L 617 110 L 620 76 L 620 278 Z"/>
<polyline class="gis-accent" fill="none" points="70,253 180,232 290,219 400,209 510,201 565,196 592,191 604,177 612,144 617,110 620,76"/>
<line class="gis-clay" x1="592" y1="191" x2="592" y2="300"/>
<text class="gis-label-sm gis-halo" x="600" y="308" text-anchor="end">95th percentile</text>
<text class="gis-label-sm gis-halo" x="600" y="326" text-anchor="end">published 10%</text>
<circle class="gis-clay gis-fill-clay" cx="620" cy="76" r="4"/>
<text class="gis-label-sm gis-halo" x="608" y="64" text-anchor="end">maximum 24%</text>
<text class="gis-label-sm gis-halo" x="500" y="128" text-anchor="middle">the tail is artifacts</text>
<text class="gis-label-sm" x="70" y="40">Every 250 m window, ordered by gradient</text>
<text class="gis-label-sm gis-halo" x="200" y="262">the road</text>
<text class="gis-label-sm" x="70" y="302">gentlest &#8594; steepest</text></svg><figcaption>Every sliding window on one climb, gentlest to steepest. For 95% of them the curve is <strong>smooth and slow</strong>: that is the road. Then it turns almost vertical. <strong>A maximum reads the very last point of that tail</strong>, which is a cutting, a rock face or a roof; the 95th percentile reads the top of the smooth part. This is why the average was always trustworthy and the steepest figure never was: an average is a question the noise cancels out of; a maximum is a question <em>about</em> the noise.</figcaption></figure>

It was checked against the only two independent truths available, and it hits
both: Wallonia's 50 cm LiDAR puts the Côte de Stockeu's steepest at **16.7%**
and we read **16.7%**; the Furka is about **10%** and we read **10.3%**. Two
points, two countries, two kinds of terrain, enough to adopt, not enough to
stop testing.

### Tunnels, or: ask the road, don't guess from the profile

A percentile removes scattered noise. It does not remove a systematic error, and
alpine roads have a large one.

<figure class="gis-fig"><svg viewBox="0 0 660 340" role="img" aria-labelledby="gal-t gal-d" xmlns="http://www.w3.org/2000/svg"><title id="gal-t">A surface model follows the roof of an avalanche gallery, not the road inside it</title><desc id="gal-d">A cross-section of a mountain road climbing gently from left to right. Over the middle third a solid roof slab sits above the road, forming an avalanche gallery, with the road running straight through underneath it unchanged. The accent line is the road itself, rising steadily and unbroken the whole way. The dashed line is what a digital surface model records: it lies on the road across the open approach, then jumps almost vertically at the gallery entrance up onto the top of the roof, runs dead flat along it, and drops back down onto the road where the gallery ends. The jump is annotated as fifty four metres gained in one hundred and forty metres, which reads as a thirty seven percent ramp, and the flat stretch immediately after it is annotated as the giveaway, because a real ramp does not stop dead.</desc>
<rect class="gis-fill-spruce" stroke="none" fill-opacity=".38" x="200" y="178" width="240" height="14"/>
<line class="gis-muted" x1="201" y1="190" x2="201" y2="261"/>
<line class="gis-muted" x1="439" y1="190" x2="439" y2="233"/>
<path class="gis-accent" fill="none" d="M 40 286 L 200 262 L 440 232 L 620 206"/>
<path class="gis-muted" fill="none" stroke-dasharray="7 4" style="stroke-width:2.6" d="M 40 286 L 197 263 L 203 174 L 437 172 L 443 233 L 620 206"/>
<line class="gis-clay" x1="197" y1="260" x2="197" y2="132"/>
<text class="gis-label-sm gis-halo" x="205" y="126">+54 m in 140 m &#8594; reads as 37%</text>
<text class="gis-label-sm gis-halo" x="320" y="164" text-anchor="middle">dead flat &#8212; the giveaway</text>
<text class="gis-label-sm gis-halo" x="320" y="222" text-anchor="middle">gallery</text>
<text class="gis-label-sm" x="40" y="44">What the model reads where the road is roofed</text>
<rect class="gis-ink gis-fill-accent" x="40" y="306" width="18" height="14"/><text class="gis-label-sm" x="66" y="318">the road you ride</text>
<rect class="gis-ink gis-fill-glacier" x="290" y="306" width="18" height="14"/><text class="gis-label-sm" x="316" y="318">what the surface model records</text></svg><figcaption>Where a road runs under an avalanche gallery or through a tunnel, a <strong>surface model reads the mountain on top of it</strong>. On the Grimsel the profile climbs <strong>768&nbsp;m to 822&nbsp;m in 140&nbsp;m and then goes flat</strong>, a 54&nbsp;m step that is the roof, not tarmac. The flat afterwards is the signature: real ramps do not stop dead. The road underneath never changes gradient at all.</figcaption></figure>

The tempting fix is to detect that signature, find the step-then-flat pattern
and discard it. Resist it. **The road network already knows.** Valhalla's
`/trace_attributes` map-matches a shape onto real edges and reports OpenStreetMap's
`tunnel` flag for each one, so the covered stretches are a *lookup*, not an
inference about what a shape in a profile probably means. A heuristic would also
have to be right about steep-but-real ramps, and this never has to guess.

The Grimsel turns out to carry **2,082 m under cover across 9 spans**, 8% of the
climb. Windows overlapping those spans are simply not candidates for the steepest
stretch.

| | before | tunnel-aware | independent figure |
|---|---:|---:|---:|
| Grimsel | 14% | **12%** | ~11% |
| Susten | 12% | **11%** | |
| Klausen | 15% | **14%** | |
| Furka, Gotthard, Nufenen, Stockeu, Redoute, Huy | | **unchanged** | |

**The last row is the row that matters.** Every climb with no cover measured
identically. A change that only moves what it claims to move is a change you can
believe; one that shifts everything slightly is one you cannot.

Three implementation details carry more weight than they look like they should:

- **Covered stretches are recorded as fractions of the line, not metres.**
  Map-matching snaps to the carriageway, so the matched geometry is *not* the
  shape you sent and its length differs. A proportion survives that. A metre
  offset drifts quietly along the climb, and quietly wrong is the failure mode
  this whole page exists to avoid.
- **One lookup, on the same 200 samples.** Matching follows the *road* between
  your samples, so a 33 m tunnel is still found from points 130 m apart. Checked
  on three passes, the 200-point shape returns identical spans to the full
  690-point route.
- **It fails soft.** If the routing service is unreachable, no spans come back
  and the climb measures exactly as it did before cover was considered. The
  elevation read has already succeeded by then; a second outage should cost
  accuracy on roofed roads, never a missing profile.

### What is still wrong

Stating this plainly is the point of the page.

- **The Grimsel still reads 12% against a real ~11%.** It has the most galleries
  of the six, and a residue survives.
- **Cover is excluded from the steepest search only.** Those readings are still
  in the gain, in the chart bars and in the line colouring, where they are
  diluted enough that nothing has shown up as wrong. That is a reason to leave
  them until someone measures a case where they *are* wrong, not evidence that
  they are right.
- **A percentile is not a maximum**, and the label has to say so. The width the
  figure was averaged over is stored beside the figure and the caption is built
  from it, so the copy cannot drift back to claiming 100 m. It had already done
  exactly that, in four translation catalogues at once.

## Try it

!!! tip "Hands-on: read a published gradient beside the window it was measured over"
    The recompute command is a dry run unless you pass `--write`, so this is safe
    on any stack. Point it at one climb and read what it would publish:

    <!-- CODE-ILLUSTRATIVE dry run, one climb; drop --id for every climb, add --write to persist -->
    ```bash
    docker compose -f developers/docker/compose.yaml exec -T app \
      php bin/console app:climbs:recompute --id=<climb id>
    ```

    There is no `--country`: the options are `--write` and `--id`, so a
    country-scoped pass is a loop over the ids you just onboarded.

    Two numbers come back for each climb, and this page is the argument that they
    are not equally trustworthy. The **average** is a sum over hundreds of
    samples and the errors cancel, so it agreed with published figures for six
    Swiss passes to within a few tenths. The **steepest stretch** is an
    extreme-value statistic over a surface model, where the extreme is usually
    the artifact, and it took a 250 m window and a 95th percentile to make it
    mean anything.

    If the command answers with zeros, stop and read the previous page's
    `/height` check rather than this one. A climb measured against missing tiles
    comes back flat, flat is a number, and nothing downstream will notice.
