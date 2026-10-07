# SPDX-License-Identifier: AGPL-3.0-only
"""The Overture extensions ship in the image: _connect() must work with
autoinstall off, so a worker host never downloads code at run time."""
import pytest

duckdb = pytest.importorskip("duckdb")

from divisions.export_divisions import _connect  # noqa: E402


def test_connect_loads_httpfs_and_spatial_without_installing():
    con = _connect()
    loaded = {name for name, is_loaded in con.execute(
        "SELECT extension_name, loaded FROM duckdb_extensions()").fetchall() if is_loaded}
    assert {"httpfs", "spatial"} <= loaded
    assert con.execute("SELECT current_setting('autoinstall_known_extensions')").fetchone()[0] is False
