# Laravel Starter Kit

Supercharged starter kit featuring:

- Laravel 12
- [Laravel Backpack 4.1](https://backpackforlaravel.com/docs/4.1/introduction)
- [Backstrap Frontend](https://backstrap.net/index.html)
- [Roles & Permissions](https://spatie.be/docs/laravel-permission/v6/introduction)
- [Laravel Sanctum for Token Auth](https://laravel.com/docs/11.x/sanctum#mobile-application-authentication)
- [Social Logins](https://laravel.com/docs/11.x/socialite)
- User editable dashboard widgets
- Painless reporting boilerplate
- Customizable SCSS

### Installation

1. Clone this repository
2. Create database
3. Run the installation file `./install.sh`
4. Fire up the server `php artisan serve`
5. Login via [`/app`](/app) with username `Administrator` and password `administrator`

### Creating New Modules

1. Create new table migration for the module eg: `php artisan make:migration create_plots_table` and run the migration
2. Create Backpack crud: `php artisan backpack:crud Plot`

### Creating New Dashboard Widgets

1. Run `php artisan make:widget Plot`
2. Edit the `app/Livewire/Widgets/Plot.php` file
3. Edit the `resources/views/livewire/widgets/plot.blade.php` view file

Widgets are placed on a GridStack dashboard, so they should fill their tile both horizontally and vertically (see the generated stub or `UserCounter` for the pattern). Any name casing works (`Plot`, `plot`, `plot-summary`, `plot_summary`) — it's normalized to StudlyCase for the class and kebab-case for the view. Pass `--force` to overwrite an existing widget of the same name.

> Sample widgets that is supported by the theme can be [viewed here](https://backstrap.net/index.html).

### Creating New Reports

1. Run `php artisan make:report Plot`
2. Edit the `app/Http/Controllers/Reports/Plot.php` controller
3. Edit the `resources/views/reports/plot.blade.php` view file

### Customizing Generator Templates

Generator templates can be editted inside the `stubs` directory.

### Customizing SCSS

Source SCSS is in `resources/scss/style.scss`, which pulls in variables (`_variables.scss`), the vendored Bootstrap 4 / CoreUI 2 theme (`vendors/coreui-2.1.16`), and app-level overrides (`_custom.scss`, `_vendor.scss`) in that order — put your own styles in `_custom.scss` so they load after (and can override) the vendored theme.

Run `npm install` once, then `npm run dev` to start the Vite dev server with hot-reloading while you work. Run `npm run build` to produce the production build in `public/build` (this is what ships — run it before deploying any SCSS change).

### Customizing Theme Colors

The theme's colors (`primary`, `secondary`, `success`, `info`, `warning`, `danger`) can be changed two ways:

1. **Without a rebuild** — set a `BACKPACK_COLOR_*` env var (e.g. `BACKPACK_COLOR_PRIMARY=#5f0461`) and reload the page. See `config/backpack/base.php`'s `theme_colors` array for the full list. Every themed component (buttons, badges, alerts, tables, sidebar links, links, etc.) reads a CSS custom property (`--primary`, `--success`, ...) instead of a compiled-in color, so this takes effect immediately — no `npm run build` needed. This is the quickest way to try a color or make it admin/environment-configurable, and it's what those components fall back to if you don't set anything.
2. **Permanently, at the source** — edit the color variables (`$primary`, `$secondary`, `$success`, `$info`, `$warning`, `$danger`) in `resources/scss/_variables.scss` and rebuild. This changes what actually ships in the compiled CSS (and the `theme_colors` config defaults, if you keep them in sync), rather than overriding it at runtime.

