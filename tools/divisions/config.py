# SPDX-License-Identifier: Apache-2.0
"""Configuration for the Overture divisions exporter.

A country is seeded at ONE operating level (the tessellation invariant,
map-and-search.md §4.5a). Belgium: subtype=region -> ISO 3166-2
BE-WAL / BE-VLG / BE-BRU. Worldwide rollout adds a config block per country as
its first curator appears (map-and-search.md §4.5a demand-driven seeding).
"""

# Overture Maps release to pin. Regeneration hits the public Overture S3 bucket
# for this release; bump deliberately (a newer release may shift boundaries —
# that is a versioned re-import event, slugs/ISO codes stay identity).
OVERTURE_RELEASE = "2026-06-17.0"

# division_area GeoParquet glob on the public (anonymous) Overture bucket.
OVERTURE_DIVISION_AREA = (
    "s3://overturemaps-us-west-2/release/{release}"
    "/theme=divisions/type=division_area/*"
)

# admin_level per Overture subtype, matching the OSM admin_level convention the
# importer + moderation model already use (Wallonia was admin_level=4).
# `country` (admin_level 2) is the operating level for a state too small for its
# official subdivisions to be useful scopes: it seeds the whole country as ONE
# region. Luxembourg (2,586 km²) is the first — its 12 cantons are all 78-343 km²,
# an order of magnitude below the riding-size band, so a single 'Luxembourg' scope
# serves a rider better than a choice of twelve. Still one operating level per
# country (the tessellation invariant holds — the country is a single atom).
SUBTYPE_ADMIN_LEVEL = {"country": 2, "region": 4, "county": 6, "localadmin": 8}

# Per-country operating configuration.
#   subtype : the Overture division_area subtype that is this country's
#             operating level (one level per country — tessellation invariant).
#   slugs   : explicit ISO 3166-2 -> stable slug map for the regions we seed.
#             slug is IDENTITY (upsert-by-slug); it must never change or the
#             region row is orphaned. `wallonia` keeps its existing slug.
#   names   : English placeholder labels. Display labels come from the messages
#             translations domain (region.<slug>.label, 4 locales) — Overture's
#             names.primary is localized/bilingual (map-and-search.md §4.5).
#   bbox    : optional [xmin, ymin, xmax, ymax] for Overture bbox predicate
#             pushdown (fast reads). Omit for a slower country-only scan.
COUNTRY_CONFIG = {
    "BE": {
        "subtype": "region",
        "slugs": {"BE-WAL": "wallonia", "BE-VLG": "flanders", "BE-BRU": "brussels"},
        "names": {"BE-WAL": "Wallonia", "BE-VLG": "Flanders", "BE-BRU": "Brussels"},
        "bbox": [2.5, 49.4, 6.5, 51.6],
    },
    # Netherlands — first worldwide-rollout country
    #. 12 official provinces,
    # subtype=region -> ISO 3166-2. limburg-nl: BE also has a Limburg (slug is
    # global identity). bbox = mainland; the Caribbean NL-BQ* municipalities
    # are deliberately outside it and outside this config.
    "NL": {
        "subtype": "region",
        "slugs": {
            "NL-DR": "drenthe", "NL-FL": "flevoland", "NL-FR": "friesland",
            "NL-GE": "gelderland", "NL-GR": "groningen", "NL-LI": "limburg-nl",
            "NL-NB": "noord-brabant", "NL-NH": "noord-holland",
            "NL-OV": "overijssel", "NL-UT": "utrecht",
            "NL-ZE": "zeeland", "NL-ZH": "zuid-holland",
        },
        "names": {
            "NL-DR": "Drenthe", "NL-FL": "Flevoland", "NL-FR": "Friesland",
            "NL-GE": "Gelderland", "NL-GR": "Groningen", "NL-LI": "Limburg",
            "NL-NB": "North Brabant", "NL-NH": "North Holland",
            "NL-OV": "Overijssel", "NL-UT": "Utrecht",
            "NL-ZE": "Zeeland", "NL-ZH": "South Holland",
        },
        "bbox": [3.2, 50.7, 7.3, 53.6],
    },
    # subtype=region -> ISO 3166-2 (16 Bundesländer). Slugs are native identity
    # (mirrors NL's noord-holland convention); `names` carry the English exonym
    # placeholder — rider-facing labels come from the messages domain (4 locales).
    # No collisions with BE/NL slugs. bbox = mainland Germany.
    "DE": {
        "subtype": "region",
        "slugs": {
            "DE-BW": "baden-wurttemberg", "DE-BY": "bayern", "DE-BE": "berlin",
            "DE-BB": "brandenburg", "DE-HB": "bremen", "DE-HH": "hamburg",
            "DE-HE": "hessen", "DE-MV": "mecklenburg-vorpommern",
            "DE-NI": "niedersachsen", "DE-NW": "nordrhein-westfalen",
            "DE-RP": "rheinland-pfalz", "DE-SL": "saarland", "DE-SN": "sachsen",
            "DE-ST": "sachsen-anhalt", "DE-SH": "schleswig-holstein",
            "DE-TH": "thuringen",
        },
        "names": {
            "DE-BW": "Baden-Württemberg", "DE-BY": "Bavaria", "DE-BE": "Berlin",
            "DE-BB": "Brandenburg", "DE-HB": "Bremen", "DE-HH": "Hamburg",
            "DE-HE": "Hesse", "DE-MV": "Mecklenburg-Western Pomerania",
            "DE-NI": "Lower Saxony", "DE-NW": "North Rhine-Westphalia",
            "DE-RP": "Rhineland-Palatinate", "DE-SL": "Saarland", "DE-SN": "Saxony",
            "DE-ST": "Saxony-Anhalt", "DE-SH": "Schleswig-Holstein",
            "DE-TH": "Thuringia",
        },
        "bbox": [5.77, 47.17, 15.14, 55.16],
    },
    # Luxembourg — seeded as ONE whole-country region (subtype=country ->
    # admin_level 2), not its 12 official cantons. The cantons are all 78-343 km²
    # (probe: web/var/scaffold/lu/areas.md), an order of magnitude below every
    # other onboarded region and finer scope granularity than a country crossable
    # in an hour needs. Overture's country-level division_area has region=NULL, so
    # the slug map is keyed on the ISO 3166-1 code 'LU' (query_country COALESCEs
    # region -> country); iso_code is therefore the 3166-1 code, not a 3166-2 one.
    "LU": {
        "subtype": "country",
        "slugs": {"LU": "luxembourg"},
        "names": {"LU": "Luxembourg"},
        "bbox": [5.7, 49.4, 6.6, 50.2],
    },
    # ——— 2026-08-06 rollout: two countries on as many continents as possible ———
    # Every block below follows the DE precedent: NATIVE slugs are frozen as
    # identity, `names` carry the English exonym placeholder, and the
    # rider-facing label comes from the messages domain in 4 locales.
    #
    # France — 13 métropole régions (subtype=region -> ISO 3166-2). The 96
    # départements (probe: web/var/scaffold/fr/areas.md) sit far closer to the
    # advisory band, but régions were chosen for legibility and a translatable
    # label count; the derived operational rule (tools/divisions/README.md)
    # demotes them to infrastructure automatically if départements ever land.
    # The DOM (Guadeloupe, Réunion, …) are not subtype=region rows in Overture
    # and are deliberately outside this bbox and this config.
    "FR": {
        "subtype": "region",
        "slugs": {
            "FR-ARA": "auvergne-rhone-alpes", "FR-BFC": "bourgogne-franche-comte",
            "FR-BRE": "bretagne", "FR-CVL": "centre-val-de-loire",
            "FR-20R": "corse", "FR-GES": "grand-est",
            "FR-HDF": "hauts-de-france", "FR-IDF": "ile-de-france",
            "FR-NAQ": "nouvelle-aquitaine", "FR-NOR": "normandie",
            "FR-OCC": "occitanie", "FR-PAC": "provence-alpes-cote-d-azur",
            "FR-PDL": "pays-de-la-loire",
        },
        "names": {
            "FR-ARA": "Auvergne-Rhône-Alpes", "FR-BFC": "Burgundy-Franche-Comté",
            "FR-BRE": "Brittany", "FR-CVL": "Centre-Val de Loire",
            "FR-20R": "Corsica", "FR-GES": "Grand Est",
            "FR-HDF": "Hauts-de-France", "FR-IDF": "Île-de-France",
            "FR-NAQ": "Nouvelle-Aquitaine", "FR-NOR": "Normandy",
            "FR-OCC": "Occitania", "FR-PAC": "Provence-Alpes-Côte d'Azur",
            "FR-PDL": "Pays de la Loire",
        },
        "bbox": [-5.24, 41.23, 9.66, 51.19],
    },
    # Switzerland — 26 cantons. Every one is BELOW the advisory band (Basel-Stadt
    # is 37 km²), which the operating-level rule explicitly permits: they are the
    # official level, riders name them, and moderation composes upward. Bilingual
    # official names (Bern/Berne, Valais/Wallis) take their majority-language form
    # as the slug. `jura` is a future collision with the French département of the
    # same name — harmless while FR operates at région level.
    "CH": {
        "subtype": "region",
        "slugs": {
            "CH-AG": "aargau", "CH-AI": "appenzell-innerrhoden",
            "CH-AR": "appenzell-ausserrhoden", "CH-BE": "bern",
            "CH-BL": "basel-landschaft", "CH-BS": "basel-stadt",
            "CH-FR": "fribourg", "CH-GE": "geneve", "CH-GL": "glarus",
            "CH-GR": "graubunden", "CH-JU": "jura", "CH-LU": "luzern",
            "CH-NE": "neuchatel", "CH-NW": "nidwalden", "CH-OW": "obwalden",
            "CH-SG": "st-gallen", "CH-SH": "schaffhausen", "CH-SO": "solothurn",
            "CH-SZ": "schwyz", "CH-TG": "thurgau", "CH-TI": "ticino",
            "CH-UR": "uri", "CH-VD": "vaud", "CH-VS": "valais",
            "CH-ZG": "zug", "CH-ZH": "zurich",
        },
        "names": {
            "CH-AG": "Aargau", "CH-AI": "Appenzell Innerrhoden",
            "CH-AR": "Appenzell Ausserrhoden", "CH-BE": "Bern",
            "CH-BL": "Basel-Landschaft", "CH-BS": "Basel-Stadt",
            "CH-FR": "Fribourg", "CH-GE": "Geneva", "CH-GL": "Glarus",
            "CH-GR": "Grisons", "CH-JU": "Jura", "CH-LU": "Lucerne",
            "CH-NE": "Neuchâtel", "CH-NW": "Nidwalden", "CH-OW": "Obwalden",
            "CH-SG": "St. Gallen", "CH-SH": "Schaffhausen", "CH-SO": "Solothurn",
            "CH-SZ": "Schwyz", "CH-TG": "Thurgau", "CH-TI": "Ticino",
            "CH-UR": "Uri", "CH-VD": "Vaud", "CH-VS": "Valais",
            "CH-ZG": "Zug", "CH-ZH": "Zurich",
        },
        "bbox": [5.86, 45.72, 10.59, 47.91],
    },
    # United Kingdom — the 4 constituent countries (subtype=region -> ISO 3166-2).
    # England is 130,657 km², far above the band and by far the coarsest scope we
    # seed; the alternative levels are 216 counties, which trades one oversized
    # scope for a scope selector nobody can read. Northern Ireland is seeded here
    # but its coverage extract is shared with the Republic of Ireland — see
    # COUNTRY_BY_REGION in pipeline/coverage/load.py.
    "GB": {
        "subtype": "region",
        "slugs": {
            "GB-ENG": "england", "GB-SCT": "scotland",
            "GB-WLS": "wales", "GB-NIR": "northern-ireland",
        },
        "names": {
            "GB-ENG": "England", "GB-SCT": "Scotland",
            "GB-WLS": "Wales", "GB-NIR": "Northern Ireland",
        },
        "bbox": [-8.75, 49.78, 1.86, 60.96],
    },
    # Italy — 20 regioni, the closest fit to the advisory band of any country
    # onboarded so far (9 of 20 land inside it). Native slugs; the bilingual
    # official names (Valle d'Aosta / Vallée d'Aoste, Trentino-Alto Adige /
    # Südtirol) take their Italian form.
    "IT": {
        "subtype": "region",
        "slugs": {
            "IT-21": "piemonte", "IT-23": "valle-d-aosta", "IT-25": "lombardia",
            "IT-32": "trentino-alto-adige", "IT-34": "veneto",
            "IT-36": "friuli-venezia-giulia", "IT-42": "liguria",
            "IT-45": "emilia-romagna", "IT-52": "toscana", "IT-55": "umbria",
            "IT-57": "marche", "IT-62": "lazio", "IT-65": "abruzzo",
            "IT-67": "molise", "IT-72": "campania", "IT-75": "puglia",
            "IT-77": "basilicata", "IT-78": "calabria", "IT-82": "sicilia",
            "IT-88": "sardegna",
        },
        "names": {
            "IT-21": "Piedmont", "IT-23": "Aosta Valley", "IT-25": "Lombardy",
            "IT-32": "Trentino-South Tyrol", "IT-34": "Veneto",
            "IT-36": "Friuli-Venezia Giulia", "IT-42": "Liguria",
            "IT-45": "Emilia-Romagna", "IT-52": "Tuscany", "IT-55": "Umbria",
            "IT-57": "Marche", "IT-62": "Lazio", "IT-65": "Abruzzo",
            "IT-67": "Molise", "IT-72": "Campania", "IT-75": "Apulia",
            "IT-77": "Basilicata", "IT-78": "Calabria", "IT-82": "Sicily",
            "IT-88": "Sardinia",
        },
        "bbox": [6.53, 35.39, 18.62, 47.19],
    },
    # Australia — the 8 ISO 3166-2 states/territories. Every one is enormously
    # above the band (WA alone is 2.5M km²) and there is no finer OFFICIAL level
    # that helps: the 599 subtype=county rows are Local Government Areas, which
    # riders do not navigate by. The probe also returns three uninhabited
    # non-ISO entries (Ashmore and Cartier, Coral Sea Islands, Jervis Bay) —
    # deliberately not seeded, so the exporter skips them.
    "AU": {
        "subtype": "region",
        "slugs": {
            "AU-ACT": "australian-capital-territory", "AU-NSW": "new-south-wales",
            "AU-NT": "northern-territory", "AU-QLD": "queensland",
            "AU-SA": "south-australia", "AU-TAS": "tasmania",
            "AU-VIC": "victoria", "AU-WA": "western-australia",
        },
        "names": {
            "AU-ACT": "Australian Capital Territory", "AU-NSW": "New South Wales",
            "AU-NT": "Northern Territory", "AU-QLD": "Queensland",
            "AU-SA": "South Australia", "AU-TAS": "Tasmania",
            "AU-VIC": "Victoria", "AU-WA": "Western Australia",
        },
        # Reaches to 54.9°S because Macquarie Island is part of Tasmania.
        "bbox": [112.82, -54.88, 159.36, -9.12],
    },
    # Japan — 47 prefectures (subtype=region -> ISO 3166-2). Overture carries the
    # names in Japanese (北海道); slugs and `names` are the standard Hepburn
    # romanisations, and the Japanese form belongs in a ja locale if one is ever
    # added — the four shipped locales are en/fr/nl/de.
    "JP": {
        "subtype": "region",
        "slugs": {
            "JP-01": "hokkaido", "JP-02": "aomori", "JP-03": "iwate",
            "JP-04": "miyagi", "JP-05": "akita", "JP-06": "yamagata",
            "JP-07": "fukushima", "JP-08": "ibaraki", "JP-09": "tochigi",
            "JP-10": "gunma", "JP-11": "saitama", "JP-12": "chiba",
            "JP-13": "tokyo", "JP-14": "kanagawa", "JP-15": "niigata",
            "JP-16": "toyama", "JP-17": "ishikawa", "JP-18": "fukui",
            "JP-19": "yamanashi", "JP-20": "nagano", "JP-21": "gifu",
            "JP-22": "shizuoka", "JP-23": "aichi", "JP-24": "mie",
            "JP-25": "shiga", "JP-26": "kyoto", "JP-27": "osaka",
            "JP-28": "hyogo", "JP-29": "nara", "JP-30": "wakayama",
            "JP-31": "tottori", "JP-32": "shimane", "JP-33": "okayama",
            "JP-34": "hiroshima", "JP-35": "yamaguchi", "JP-36": "tokushima",
            "JP-37": "kagawa", "JP-38": "ehime", "JP-39": "kochi",
            "JP-40": "fukuoka", "JP-41": "saga", "JP-42": "nagasaki",
            "JP-43": "kumamoto", "JP-44": "oita", "JP-45": "miyazaki",
            "JP-46": "kagoshima", "JP-47": "okinawa",
        },
        "names": {
            "JP-01": "Hokkaido", "JP-02": "Aomori", "JP-03": "Iwate",
            "JP-04": "Miyagi", "JP-05": "Akita", "JP-06": "Yamagata",
            "JP-07": "Fukushima", "JP-08": "Ibaraki", "JP-09": "Tochigi",
            "JP-10": "Gunma", "JP-11": "Saitama", "JP-12": "Chiba",
            "JP-13": "Tokyo", "JP-14": "Kanagawa", "JP-15": "Niigata",
            "JP-16": "Toyama", "JP-17": "Ishikawa", "JP-18": "Fukui",
            "JP-19": "Yamanashi", "JP-20": "Nagano", "JP-21": "Gifu",
            "JP-22": "Shizuoka", "JP-23": "Aichi", "JP-24": "Mie",
            "JP-25": "Shiga", "JP-26": "Kyoto", "JP-27": "Osaka",
            "JP-28": "Hyogo", "JP-29": "Nara", "JP-30": "Wakayama",
            "JP-31": "Tottori", "JP-32": "Shimane", "JP-33": "Okayama",
            "JP-34": "Hiroshima", "JP-35": "Yamaguchi", "JP-36": "Tokushima",
            "JP-37": "Kagawa", "JP-38": "Ehime", "JP-39": "Kochi",
            "JP-40": "Fukuoka", "JP-41": "Saga", "JP-42": "Nagasaki",
            "JP-43": "Kumamoto", "JP-44": "Oita", "JP-45": "Miyazaki",
            "JP-46": "Kagoshima", "JP-47": "Okinawa",
        },
        "bbox": [122.83, 23.95, 154.09, 45.62],
    },
    # United States — STATE-LEVEL onboarding (tools/divisions/README.md): only
    # California and Colorado are seeded, the rest of the country onboards later,
    # demand-driven. The exporter seeds exactly what `slugs` lists, so no --only
    # flag is needed here. bbox is the CA+CO union, NOT the country: the US
    # subtype=region rows span Alaska to American Samoa and a country-wide box
    # would read a hundred states to write two. The L2 outline still exports
    # correctly because the bbox predicate is an OVERLAP test, and the whole-US
    # country row overlaps this box.
    "US": {
        "subtype": "region",
        "slugs": {"US-CA": "california", "US-CO": "colorado"},
        "names": {"US-CA": "California", "US-CO": "Colorado"},
        "bbox": [-124.6, 32.4, -101.9, 42.1],
    },
}

# Level-2 country identity every onboarding run emits ALONGSIDE the operating
# level: (slug, English name).
# slug is IDENTITY (upsert-by-slug) like every region slug — plain English
# country name, matching the 'luxembourg' precedent. A country whose operating
# subtype is already 'country' (LU) is not emitted twice; its entry here keeps
# the invariant "every configured country has an L2 identity" testable.
COUNTRY_L2 = {
    "BE": ("belgium", "Belgium"),
    "NL": ("netherlands", "Netherlands"),
    "DE": ("germany", "Germany"),
    "LU": ("luxembourg", "Luxembourg"),
    "FR": ("france", "France"),
    "CH": ("switzerland", "Switzerland"),
    # ISO 3166-1 alpha-2 for the United Kingdom is GB, not UK.
    "GB": ("united-kingdom", "United Kingdom"),
    "IT": ("italy", "Italy"),
    "AU": ("australia", "Australia"),
    "JP": ("japan", "Japan"),
    # Infrastructure only, and unusually so: the US outline covers 48 states
    # nobody has onboarded. It anchors pre-onboarding evidence submissions and
    # is never operational while California and Colorado exist at level 4.
    "US": ("united-states", "United States"),
}
