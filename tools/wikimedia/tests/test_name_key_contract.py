# SPDX-License-Identifier: AGPL-3.0-only
"""The Python side of the name-key contract (name_key_cases.json).

web/tests/Catalog/NameKeyContractTest.php asserts the same file from PHP. Both
must pass or the import guard and this pre-screen disagree about what counts as
the same name, which is worse than either being wrong alone: one would hide
duplicates the other reports.
"""
import json
import pathlib

import pytest

from wikimedia.prescreen_seeded import normalise_name

CASES = json.loads(
    (pathlib.Path(__file__).resolve().parents[1] / "name_key_cases.json").read_text()
)["cases"]


@pytest.mark.parametrize("case", CASES, ids=[c["why"][:40] for c in CASES])
def test_the_contract_case_holds(case):
    assert normalise_name(case["name"]) == case["key"], case["why"]


def test_the_contract_is_not_empty():
    """A file that lost its cases would pass every parametrised test above by
    running none of them."""
    assert len(CASES) >= 15
