# Laravel Starter Kit

Supercharged starter kit featuring:

- Laravel 11
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
2. Edit the `app/Http/Controllers/Widgets/Plot.php` controller
3. Edit the `resources/views/widgets/plot.blade.php` view file

> Sample widgets that is supported by the theme can be [viewed here](https://backstrap.net/index.html).

### Creating New Reports

1. Run `php artisan make:report Plot`
2. Edit the `app/Http/Controllers/Reports/Plot.php` controller
3. Edit the `resources/views/reports/plot.blade.php` view file

### Customizing Generator Templates

Generator templates can be editted inside the `stubs` directory.

### Customizing SCSS

Source SCSS is in `resources/scss/style.css`. To customize it run `npm install` and then `npm run dev`. Production builds uses `npm run prod`.

