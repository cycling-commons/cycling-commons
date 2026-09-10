# SPDX-License-Identifier: AGPL-3.0-only
"""Unit tests for climbs.py's pure record/attribute assembly (no network)."""
from wallonia import climbs


def test_assemble_climb_emits_discrete_attributes_and_length_only_record():
    """C5 fix: avgGradient/maxGradient/surface/famousFor must be discrete
    registry-keyed attributes (CatalogFormRegistry's Climbs field keys) so the
    improve edit form can prefill them from item.attributes — not baked into
    `record` display strings. `record` keeps only the derived Length row."""
    m = {"name": "Côte de Test", "race": "Liège–Bastogne–Liège", "km": 2.0, "avg": 6.5, "ll": [50.1, 5.2]}
    p = {}
    traced = {"route": [[50.1, 5.2], [50.11, 5.21]], "grad": [1, 2], "km": 2.1, "avg": 5.7,
              "max": 26, "steep": {"at": [50.105, 5.205], "pct": "~26%"}}

    climb = climbs._assemble_climb(m, p, traced)

    assert climb["avgGradient"] == "5.7"  # the number, no '%'
    assert climb["maxGradient"] == "26"
    assert climb["surface"] == "Asphalt"
    assert climb["famousFor"] == "Liège–Bastogne–Liège"

    labels = [row["label"] for row in climb["record"]]
    assert labels == ["Length"]  # no Average gradient / Max gradient / Famous for / Surface rows
    assert climb["record"][0]["value"] == "2.1 km"


def test_assemble_climb_without_trace_still_emits_avg_gradient_no_max():
    """No BRouter trace (traced=None): avg falls back to seed/desc stats, and
    maxGradient is simply omitted (no discrete max reading exists without a trace)."""
    m = {"name": "Côte Sans Trace", "race": "La Flèche Wallonne", "km": 1.3, "avg": 8.1, "ll": [50.2, 5.3]}
    p = {"desc": "A short climb."}

    climb = climbs._assemble_climb(m, p, None)

    assert climb["avgGradient"] == "8.1"
    assert "maxGradient" not in climb
    assert climb["surface"] == "Asphalt"
    assert climb["famousFor"] == "La Flèche Wallonne"
    assert [row["label"] for row in climb["record"]] == ["Length"]
