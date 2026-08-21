import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { viteStaticCopy } from 'vite-plugin-static-copy';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/scss/style.scss',
                'resources/css/list-bundle.css',
            ],
            refresh: true,
        }),
        // The vendored third-party JS files (jQuery, Bootstrap, CoreUI, DataTables
        // Buttons/JSZip/pdfmake, etc.) are legacy UMD/global scripts, not ES
        // modules — bundling them via `import` breaks their internal
        // `require('datatables.net-bs4')`-style CommonJS branches at runtime.
        // Copy them through untouched and load them as plain <script> tags
        // (same convention already used for the packages/** vendor assets).
        viteStaticCopy({
            targets: [
                { src: 'resources/js/bundle/*', dest: 'vendor/bundle', rename: { stripBase: true } },
                { src: 'resources/js/list/*', dest: 'vendor/list', rename: { stripBase: true } },
            ],
        }),
    ],
    css: {
        preprocessorOptions: {
            scss: {
                quietDeps: true,
            },
        },
    },
});
