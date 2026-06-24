import sys
import pathlib
sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[2]))
from wallonia import regions


def test_province_areas_returns_five_known_provinces(monkeypatch):
    fake = {"elements": [
        {"type": "relation", "id": 1532, "tags": {"name": "Hainaut", "admin_level": "6"}},
        {"type": "relation", "id": 2400, "tags": {"name": "Liège", "admin_level": "6"}},
        {"type": "relation", "id": 2412, "tags": {"name": "Luxembourg", "admin_level": "6"}},
        {"type": "relation", "id": 2402, "tags": {"name": "Namur", "admin_level": "6"}},
        {"type": "relation", "id": 1402, "tags": {"name": "Brabant wallon", "admin_level": "6"}},
    ]}
    monkeypatch.setattr(regions.overpass, "query", lambda ql: fake)
    areas = regions.province_areas()
    assert set(areas) == set(regions.PROVINCES)
    assert areas["Hainaut"] == 3600001532
