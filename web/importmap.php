<?php

/**
 * Returns the importmap for this application.
 *
 * - "path" is a path inside the asset mapper system. Use the
 *     "debug:asset-map" command to see the full list of paths.
 *
 * - "entrypoint" (JavaScript only) set to true for any module that will
 *     be used as an "entrypoint" (and passed to the importmap() Twig function).
 *
 * The "importmap:require" command can be used to add new entries to this file.
 */
return [
    'app' => [
        'path' => './assets/app.js',
        'entrypoint' => true,
    ],
    'admin_confirm' => [
        'path' => './assets/admin_confirm.js',
        'entrypoint' => true,
    ],
    // The admin area does not extend base.html.twig, so it cannot pick this up
    // from base's <script> tag — and it needs it for the same reason every
    // other page does: the CSP blocks inline `onchange=` handlers silently.
    'form_autosubmit' => [
        'path' => './assets/js/form-autosubmit.js',
        'entrypoint' => true,
    ],
    // The map front end. Listed so
    // AssetMapper walks map.js's relative imports and emits an importmap entry
    // for each module: JavaScriptImportPathCompiler rewrites `./i18n.js` to the
    // UNDIGESTED public path, which only resolves because the importmap maps it
    // to the digested file.
    //
    // Deliberately NOT an entrypoint. An entrypoint would make importmap() emit
    // `import 'map'`, executing the module the moment it loads — but map.js must
    // not run until catalog-load.js has populated the CC_* globals it reads at
    // module scope. That gate stays: catalog-load.js injects the script itself
    // once its fetch resolves.
    'map' => [
        'path' => './assets/map/map.js',
    ],
];
