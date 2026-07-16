# SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
"""Shared pytest fixtures for the coverage pipeline suite.

Runs INSIDE the pipeline container (osmium-tool + pyosmium come from the image):
  docker compose -f developers/docker/compose.yaml exec -T pipeline python -m pytest tests -q
CI never touches the network: mini.osm.pbf is a COMMITTED fixture, built once
from the hand-written mini.osm via `osmium cat` (regenerate + re-commit on
fixture changes — see the plan), per coverage-provider.md §3.
"""

import pathlib

import pytest

from coverage.contract import load_contract

FIXTURES = pathlib.Path(__file__).resolve().parent / "fixtures"


@pytest.fixture(scope="session")
def contract():
    return load_contract()


@pytest.fixture(scope="session")
def mini_pbf():
    """The committed PBF twin of the hand-written mini.osm fixture."""
    return FIXTURES / "mini.osm.pbf"
