# SPDX-License-Identifier: AGPL-3.0-only
"""The generic authority harvester (docs/specs/data-provider-hierarchy.md §5).

One fetcher, configured per `data_provider` row, replacing the
provider-specific script. Reading a geospatial service and getting its
coordinates into WGS84 is squarely on the Python side of this project's
boundary; matching rows against rows is not, so this half stops at a
normalised GeoJSON file and `app:providers:harvest` takes it from there.
"""
