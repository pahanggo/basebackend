# Laravel Starter Kit

Supercharged starter kit featuring:

- Laravel 8
- [Laravel Backpack 4.1](https://backpackforlaravel.com/docs/4.1/introduction)
- [Backstrap Frontend](https://backstrap.net/index.html)
- [Roles & Permissions](https://spatie.be/docs/laravel-permission/v3/introduction)
- [System Settings](https://github.com/Laravel-Backpack/Settings)
- [Log Viewer](https://github.com/Laravel-Backpack/LogManager)
- [Laravel Sanctum for Token Auth](https://laravel.com/docs/8.x/sanctum#mobile-application-authentication)
- [Scribe for API Documentation](https://scribe.readthedocs.io/en/latest/guide-getting-started.html)
- [Social Logins](https://laravel.com/docs/8.x/socialite)
- User editable dashboard widgets
- Painless reporting boilerplate
- Customizable SCSS

### Installation

1. Clone this repository
2. Create database
3. Copy .env.example to .env and update the database
4. Run `php artisan migrate --seed`
5. Fire up the server `php artisan serve`
6. Login via [`/app`](/app) with username `Administrator` and password `administrator`

### Creating New Modules

1. Create new table migration for the module eg: `php artisan make:migration create_plots_table` and run the migration
2. Create Backpack crud: `php artisan backpack:crud Plot`
3. Add new permissions in [`/app/permission`](/app/permission). For eg: `Manage Plots`
4. Update the `database/seeders/UserSeeder` to add the permission
5. Add the permission to the `Administrator` Role [`/app/role`](/app/role)
6. Edit `routes/backpack/custom.php` with middleware `can:Manage Plots`
7. Edit `resources/views/vendor/backpack/base/inc/sidebar_content.blade.php` to only show the `Plot` link using `@can('Manage Plots') .. @endcan`

### Creating New Dashboard Widgets

1. Run `php artisan make:widget Plot`
2. Edit the `app/Http/Controllers/Widgets/Plot.php` controller
3. Edit the `resources/views/widgets/plot.blade.php` view file

> Sample widgets that is supported by the theme can be [viewed here](https://backstrap.net/index.html).

### Creating New Reports

1. Run `php artisan make:report Plot`
2. Edit the `app/Http/Controllers/Reports/Plot.php` controller
3. Edit the `resources/views/reports/plot.blade.php` view file

### API Documentation

API documentation can be accessed from [`/app/api-docs`](/app/api-docs) (Needs to be authenticated). API Authentication is via Laravel Sanctum.

To regenerate the api documentation run `php artisan scribe:generate`

### Customizing Generator Templates

Generator templates can be editted inside the `stubs` directory

### Customizing SCSS

Source SCSS is in `resources/scss/style.css`. To customize it run `npm install` and then `npm run dev`. Production builds uses `npm run prod`.

