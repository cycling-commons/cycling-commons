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
    # Spain — 2026-08-08 rollout. The 17 comunidades autónomas plus the two
    # autonomous cities, i.e. exactly ISO 3166-2:ES. `Plazas de Soberanía`
    # (1 km², the sovereign islets off Morocco) is deliberately NOT seeded: it
    # carries no ISO 3166-2 code, so it has no stable identity to upsert on,
    # and nobody rides it.
    #
    # Slugs are the native forms, following the German precedent (`bayern`, not
    # `bavaria`) — the exonyms live in the message catalogues. Three were
    # rewritten off the scaffolder's output, which builds them from Overture's
    # dual-language and inverted-comma labels: `catalunya-cataluna`,
    # `galicia-galicia`, `illes-balears-islas-baleares`, `murcia-region-de`,
    # `madrid-comunidad-de`, `navarra-comunidad-foral-de`,
    # `asturias-principado-de` and `valenciana-comunidad` are all artifacts of
    # that, not names anyone uses.
    #
    # `la-rioja-es` carries the suffix because Argentina's AR-F is also La
    # Rioja (scaffolder collision warning). AR is not onboarded, but a slug is
    # permanent identity and renaming one later is an upsert-by-slug break —
    # the `limburg-nl` precedent.
    #
    # The bbox spans the Canaries (28°N, 18°W) to the Pyrenees, which is why it
    # is so much wider than a mainland-only box would be.
    "ES": {
        "subtype": "region",
        "slugs": {
            "ES-AN": "andalucia", "ES-AR": "aragon", "ES-AS": "asturias",
            "ES-CB": "cantabria", "ES-CE": "ceuta", "ES-CL": "castilla-y-leon",
            "ES-CM": "castilla-la-mancha", "ES-CN": "canarias",
            "ES-CT": "catalunya", "ES-EX": "extremadura", "ES-GA": "galicia",
            "ES-IB": "illes-balears", "ES-MC": "murcia", "ES-MD": "madrid",
            "ES-ML": "melilla", "ES-NC": "navarra", "ES-PV": "pais-vasco",
            "ES-RI": "la-rioja-es", "ES-VC": "comunitat-valenciana",
        },
        "names": {
            "ES-AN": "Andalucía", "ES-AR": "Aragón", "ES-AS": "Asturias",
            "ES-CB": "Cantabria", "ES-CE": "Ceuta", "ES-CL": "Castilla y León",
            "ES-CM": "Castilla-La Mancha", "ES-CN": "Canarias",
            "ES-CT": "Catalunya", "ES-EX": "Extremadura", "ES-GA": "Galicia",
            "ES-IB": "Illes Balears", "ES-MC": "Región de Murcia",
            "ES-MD": "Comunidad de Madrid", "ES-ML": "Melilla",
            "ES-NC": "Navarra", "ES-PV": "País Vasco", "ES-RI": "La Rioja",
            "ES-VC": "Comunitat Valenciana",
        },
        "bbox": [-18.26, 27.54, 4.43, 43.89],
    },
    # ——— 2026-08-14 rollout: Slovenia, Rwanda, South Africa, Colombia, Chile,
    #     New Zealand, and two Canadian provinces ———
    #
    # Slovenia — the SECOND country to operate at L2, and for the same reason
    # Luxembourg does. Its official ISO 3166-2 level is 212 občine (probe:
    # web/var/scaffold/si/areas.md), every one of them 7–555 km² and so an order
    # of magnitude below the riding-size band; there is no intermediate level
    # carrying ISO identity (the 12 statistical regions are NUTS-3, not
    # administrative). Slovenia itself is 20,271 km² — INSIDE the advisory band
    # — so the country is a region of exactly the right size. Synthetic
    # macro-regions were the alternative and the playbook reserves those for
    # when no official level fits; here one does.
    "SI": {
        "subtype": "country",
        "slugs": {"SI": "slovenia"},
        "names": {"SI": "Slovenia"},
        "bbox": [13.28, 45.32, 16.7, 46.98],
    },
    # Rwanda — 5 provinces (4 + the City of Kigali), all below the band, which
    # is fine: moderation composes upward and the whole country is 26,338 km².
    #
    # NATIVE Kinyarwanda slugs, against the usual "established English exonym
    # first" preference, because here the English names are *Eastern*,
    # *Northern*, *Western* and *Southern* — words that belong to no country in
    # particular. A slug is permanent GLOBAL identity, so `eastern` would have
    # to become `eastern-province-rw` the first time any other country onboards
    # a compass-named division, and the DE/NL precedent already prefers the
    # native form. `kigali` is the exception in the other direction: the city's
    # name is the same in every language.
    "RW": {
        "subtype": "region",
        "slugs": {
            "RW-01": "kigali", "RW-02": "iburasirazuba", "RW-03": "amajyaruguru",
            "RW-04": "iburengerazuba", "RW-05": "amajyepfo",
        },
        "names": {
            "RW-01": "City of Kigali", "RW-02": "Eastern Province",
            "RW-03": "Northern Province", "RW-04": "Western Province",
            "RW-05": "Southern Province",
        },
        "bbox": [28.76, -2.94, 31.0, -0.95],
    },
    # South Africa — 9 provinces, ISO 3166-2:ZA exactly. English is an official
    # language and these ARE the native English names, so no slug was rewritten.
    # NOTE for the coverage step: the Geofabrik `africa/south-africa` extract
    # bundles Lesotho and Eswatini, the same shared-extract case Northern
    # Ireland taught us — neither is onboarded, so nearest-region-wins deletes
    # their rows except in the snap band along the borders.
    "ZA": {
        "subtype": "region",
        "slugs": {
            "ZA-EC": "eastern-cape", "ZA-FS": "free-state", "ZA-GP": "gauteng",
            "ZA-KZN": "kwazulu-natal", "ZA-LP": "limpopo", "ZA-MP": "mpumalanga",
            "ZA-NC": "northern-cape", "ZA-NW": "north-west", "ZA-WC": "western-cape",
        },
        "names": {
            "ZA-EC": "Eastern Cape", "ZA-FS": "Free State", "ZA-GP": "Gauteng",
            "ZA-KZN": "KwaZulu-Natal", "ZA-LP": "Limpopo", "ZA-MP": "Mpumalanga",
            "ZA-NC": "Northern Cape", "ZA-NW": "North West", "ZA-WC": "Western Cape",
        },
        # South to 47.08°S for the Prince Edward Islands, which are part of
        # the Western Cape and 1,700 km off the mainland.
        "bbox": [16.35, -47.08, 38.1, -22.03],
    },
    # Colombia — 32 departamentos + the Distrito Capital, ISO 3166-2:CO exactly.
    # Native Spanish slugs (the Spain precedent).
    #
    # Three carry a -co suffix for collisions with countries that are NOT
    # onboarded, following `la-rioja-es` (suffixed for Argentina's La Rioja
    # while AR was, and still is, unonboarded): Amazonas is also a state of
    # Brazil, Peru and Venezuela; Córdoba a province of Argentina; Bolívar a
    # state of Venezuela. A slug cannot be changed later without orphaning the
    # region row, so the collision is answered now rather than when it bites.
    # `bogota` and `san-andres-y-providencia` are shortened from Overture's
    # administrative long forms to the names people actually use.
    "CO": {
        "subtype": "region",
        "slugs": {
            "CO-AMA": "amazonas-co", "CO-ANT": "antioquia", "CO-ARA": "arauca",
            "CO-ATL": "atlantico", "CO-BOL": "bolivar-co", "CO-BOY": "boyaca",
            "CO-CAL": "caldas", "CO-CAQ": "caqueta", "CO-CAS": "casanare",
            "CO-CAU": "cauca", "CO-CES": "cesar", "CO-CHO": "choco",
            "CO-COR": "cordoba-co", "CO-CUN": "cundinamarca", "CO-DC": "bogota",
            "CO-GUA": "guainia", "CO-GUV": "guaviare", "CO-HUI": "huila",
            "CO-LAG": "la-guajira", "CO-MAG": "magdalena", "CO-MET": "meta",
            "CO-NAR": "narino", "CO-NSA": "norte-de-santander", "CO-PUT": "putumayo",
            "CO-QUI": "quindio", "CO-RIS": "risaralda", "CO-SAN": "santander",
            "CO-SAP": "san-andres-y-providencia", "CO-SUC": "sucre",
            "CO-TOL": "tolima", "CO-VAC": "valle-del-cauca", "CO-VAU": "vaupes",
            "CO-VID": "vichada",
        },
        "names": {
            "CO-AMA": "Amazonas", "CO-ANT": "Antioquia", "CO-ARA": "Arauca",
            "CO-ATL": "Atlántico", "CO-BOL": "Bolívar", "CO-BOY": "Boyacá",
            "CO-CAL": "Caldas", "CO-CAQ": "Caquetá", "CO-CAS": "Casanare",
            "CO-CAU": "Cauca", "CO-CES": "Cesar", "CO-CHO": "Chocó",
            "CO-COR": "Córdoba", "CO-CUN": "Cundinamarca", "CO-DC": "Bogotá",
            "CO-GUA": "Guainía", "CO-GUV": "Guaviare", "CO-HUI": "Huila",
            "CO-LAG": "La Guajira", "CO-MAG": "Magdalena", "CO-MET": "Meta",
            "CO-NAR": "Nariño", "CO-NSA": "Norte de Santander", "CO-PUT": "Putumayo",
            "CO-QUI": "Quindío", "CO-RIS": "Risaralda", "CO-SAN": "Santander",
            "CO-SAP": "San Andrés y Providencia", "CO-SUC": "Sucre",
            "CO-TOL": "Tolima", "CO-VAC": "Valle del Cauca", "CO-VAU": "Vaupés",
            "CO-VID": "Vichada",
        },
        "bbox": [-81.84, -4.33, -66.75, 13.49],
    },
    # Chile — 16 regiones, ISO 3166-2:CL exactly. Three slugs rewritten from
    # Overture's official long forms to the names in ordinary use: `aysen`
    # (Aysén del General Carlos Ibáñez del Campo — Carretera Austral country),
    # `o-higgins` (Libertador General Bernardo O'Higgins) and
    # `region-metropolitana` (Región Metropolitana de Santiago).
    #
    # Two things worth knowing about the geometry. CL-MA is named "Magallanes y
    # de la Antártica Chilena" but Overture's polygon is 131,834 km² — the
    # MAINLAND region only, without the Antarctic claim, which would have added
    # ~1.25 M km² and a region reaching the South Pole. And the bbox opens to
    # 109.55°W for Easter Island, part of Valparaíso: 43° of longitude, nowhere
    # near the 358.9° US span that defeated the GiST prefilter in the 08-06 run.
    "CL": {
        "subtype": "region",
        "slugs": {
            "CL-AI": "aysen", "CL-AN": "antofagasta", "CL-AP": "arica-y-parinacota",
            "CL-AR": "la-araucania", "CL-AT": "atacama", "CL-BI": "biobio",
            "CL-CO": "coquimbo", "CL-LI": "o-higgins", "CL-LL": "los-lagos",
            "CL-LR": "los-rios", "CL-MA": "magallanes", "CL-ML": "maule",
            "CL-NB": "nuble", "CL-RM": "region-metropolitana", "CL-TA": "tarapaca",
            "CL-VS": "valparaiso",
        },
        "names": {
            "CL-AI": "Aysén", "CL-AN": "Antofagasta", "CL-AP": "Arica y Parinacota",
            "CL-AR": "La Araucanía", "CL-AT": "Atacama", "CL-BI": "Biobío",
            "CL-CO": "Coquimbo", "CL-LI": "O'Higgins", "CL-LL": "Los Lagos",
            "CL-LR": "Los Ríos", "CL-MA": "Magallanes", "CL-ML": "Maule",
            "CL-NB": "Ñuble", "CL-RM": "Región Metropolitana", "CL-TA": "Tarapacá",
            "CL-VS": "Valparaíso",
        },
        "bbox": [-109.55, -56.62, -66.32, -17.4],
    },
    # New Zealand — 16 regions plus the Chatham Islands Territory, ISO 3166-2:NZ
    # exactly. Three slugs rewritten: `hawkes-bay` (the scaffolder's
    # `hawke-s-bay` is an apostrophe artifact, not a name), `chatham-islands`,
    # and `wellington` — ISO NZ-WGN is Wellington; "Greater Wellington" is the
    # regional council, not the region. Macrons live in the LABELS
    # (Manawatū-Whanganui), never in a slug.
    "NZ": {
        "subtype": "region",
        "slugs": {
            "NZ-AUK": "auckland", "NZ-BOP": "bay-of-plenty", "NZ-CAN": "canterbury",
            "NZ-CIT": "chatham-islands", "NZ-GIS": "gisborne", "NZ-HKB": "hawkes-bay",
            "NZ-MBH": "marlborough", "NZ-MWT": "manawatu-whanganui", "NZ-NSN": "nelson",
            "NZ-NTL": "northland", "NZ-OTA": "otago", "NZ-STL": "southland",
            "NZ-TAS": "tasman", "NZ-TKI": "taranaki", "NZ-WGN": "wellington",
            "NZ-WKO": "waikato", "NZ-WTC": "west-coast",
        },
        "names": {
            "NZ-AUK": "Auckland", "NZ-BOP": "Bay of Plenty", "NZ-CAN": "Canterbury",
            "NZ-CIT": "Chatham Islands", "NZ-GIS": "Gisborne", "NZ-HKB": "Hawke's Bay",
            "NZ-MBH": "Marlborough", "NZ-MWT": "Manawatū-Whanganui", "NZ-NSN": "Nelson",
            "NZ-NTL": "Northland", "NZ-OTA": "Otago", "NZ-STL": "Southland",
            "NZ-TAS": "Tasman", "NZ-TKI": "Taranaki", "NZ-WGN": "Wellington",
            "NZ-WKO": "Waikato", "NZ-WTC": "West Coast",
        },
        # 355° wide, and deliberately so: the Chatham Islands sit at ~176.5°W
        # while the mainland ends at 178.7°E, so New Zealand STRADDLES THE
        # ANTIMERIDIAN and no honest [xmin…xmax] box is narrow. A mainland-only
        # box would read faster and silently drop NZ-CIT, leaving a hole in the
        # country's tessellation. This is a read predicate for the Overture
        # export only — it costs one slower scan at onboarding time and never
        # touches coverage ownership, which works from the region polygons
        # themselves (the L2 outline that would repeat the US bbox problem is
        # excluded there by the operational-region filter).
        "bbox": [-176.99, -47.39, 178.68, -34.29],
    },
    # Canada — province-level onboarding on the US precedent (`--only`), not the
    # whole country: British Columbia and Québec first, the rest demand-driven.
    # Both are far above the advisory band (947,379 and 1,509,940 km²) and both
    # are chosen for a real cycling reason rather than a size one — Vancouver
    # Island and the Sea-to-Sky for BC, and for Québec the Route verte, at
    # ~5,300 km the largest signed cycle network in the Americas.
    #
    # Geofabrik splits Canada by province, so coverage onboards them singly
    # exactly as California and Colorado do.
    "CA": {
        "subtype": "region",
        "slugs": {"CA-BC": "british-columbia", "CA-QC": "quebec"},
        "names": {"CA-BC": "British Columbia", "CA-QC": "Québec"},
        # BC + QC only, not the probe's whole-Canada box: the two provinces
        # together span -139.06…-57.1 and 41.68…62.59°N, so the read predicate
        # stays tight even though the country reaches 83°N.
        "bbox": [-139.06, 41.68, -57.1, 62.59],
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
    "ES": ("spain", "Spain"),
    # 2026-08-14 rollout. Slovenia's operating subtype is already `country`, so
    # like Luxembourg it is not emitted twice — the entry keeps the invariant
    # "every configured country has an L2 identity" testable.
    "SI": ("slovenia", "Slovenia"),
    "RW": ("rwanda", "Rwanda"),
    "ZA": ("south-africa", "South Africa"),
    "CO": ("colombia", "Colombia"),
    "CL": ("chile", "Chile"),
    "NZ": ("new-zealand", "New Zealand"),
    # Infrastructure only, like the US outline: the Canadian outline covers 11
    # provinces and territories nobody has onboarded, and is never operational
    # while British Columbia and Québec exist at level 4.
    "CA": ("canada", "Canada"),
}
