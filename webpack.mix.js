const mix = require('laravel-mix');

/*
 |--------------------------------------------------------------------------
 | Mix Asset Management
 |--------------------------------------------------------------------------
 |
 | Mix provides a clean, fluent API for defining some Webpack build steps
 | for your Laravel applications. By default, we are compiling the CSS
 | file for the application as well as bundling up all the JS files.
 |
 */

mix.sass('resources/scss/style.scss', 'public/css', {
        sassOptions: {
            quietDeps: true,
        }
    })
    .combine([
        'resources/css/list/datatables.net-bs4.min.css',
        'resources/css/list/datatables.net-fixed-header-bs4.min.css',
        'resources/css/list/datatables.net-responsive-bs4.min.css',
    ], 'public/css/list.min.css')
    .combine([
        'resources/js/bundle/jquery-3.7.1.min.js',
        'resources/js/bundle/popper-1.16.1.min.js',
        'resources/js/bundle/bootstrap-4.6.2.min.js',
        'resources/js/bundle/coreui-2.1.16.min.js',
        'resources/js/bundle/pace-1.2.4.min.js',
        'resources/js/bundle/sweetalert-2.1.2.min.js',
        'resources/js/bundle/noty-3.1.4.min.js',
    ], 'public/js/bundle.min.js')
    .combine([
        'resources/js/list/jquery.dataTables.min.js',
        'resources/js/list/dataTables.bootstrap4.min.js',
        'resources/js/list/dataTables.responsive.min.js',
        'resources/js/list/responsive.bootstrap4.min.js',
        'resources/js/list/dataTables.fixedHeader.min.js',
        'resources/js/list/fixedHeader.bootstrap4.min.js',
        'resources/js/list/dataTables.buttons.min.js',
        'resources/js/list/buttons.bootstrap4.min.js',
        'resources/js/list/jszip.min.js',
        'resources/js/list/pdfmake.min.js',
        'resources/js/list/vfs_fonts.js',
        'resources/js/list/buttons.html5.min.js',
        'resources/js/list/buttons.print.min.js',
        'resources/js/list/buttons.colVis.min.js',
        'resources/js/list/uri-1.18.2.min.js',
    ], 'public/js/list.min.js')
    // .js([
    //     'src/app.js',
    //     'src/another.js'
    // ], 'public/js/list.min.js')
    ;

if (mix.inProduction()) {
    mix.version();
}

mix.sourceMaps();