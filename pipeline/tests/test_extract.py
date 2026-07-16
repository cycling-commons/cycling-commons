# SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
"""coverage.extract — osmium tags-filter wrapper, over the mini.osm fixture."""

import osmium
import pytest

from coverage.extract import run_extract, selector_expressions
from coverage.parse import parse_pois


class _TaggedNodeScan(osmium.SimpleHandler):
    def __init__(self):
        super().__init__()
        self.tags = []

    def node(self, n):
        if n.tags:
            self.tags.append({t.k: t.v for t in n.tags})


def test_selector_expressions_cover_contract_nodes_and_ways(contract):
    exprs = selector_expressions(contract)
    contract_tags = {
        sel.tag for spec in contract.letters.values() for sel in spec.selectors
    }
    # Exhaustive: every contract selector maps to exactly ONE expression — a
    # silently dropped selector fails here, not in production coverage data.
    for tag in sorted(contract_tags):
        assert exprs.count(f"nw/{tag}") == 1, f"selector {tag} missing or duplicated"
    assert len(exprs) == len(contract_tags)          # nothing extra, nothing dropped
    assert len(exprs) == len(set(exprs))             # deduped
    assert all(e.startswith("nw/") for e in exprs)   # decision B1: nodes + ways


def test_run_extract_keeps_selector_subset_only(mini_pbf, contract, tmp_path):
    out = run_extract(mini_pbf, tmp_path / "filtered.osm.pbf", contract)
    scan = _TaggedNodeScan()
    scan.apply_file(str(out))
    assert {"amenity": "bench"} not in scan.tags     # non-selector node dropped
    assert {"shop": "bicycle", "name": "Vélo Namur"} in scan.tags
    # the filtered file still parses fully (matched way + its corner nodes kept)
    refs = {r.ref for r in parse_pois(out, contract, "europe/belgium", "BE")}
    assert "way/201" in refs
    assert "node/108" not in refs


def test_run_extract_surfaces_osmium_failure(contract, tmp_path):
    with pytest.raises(RuntimeError, match="osmium tags-filter failed"):
        run_extract(tmp_path / "missing.osm.pbf", tmp_path / "out.osm.pbf", contract)
