---
paths:
  - app/Http/Controllers/Admin/KitchenSinkCrudController.php
---

# Admin

## Kitchen Sink CRUD lives in its own SQLite database
The Kitchen Sink CRUD (/app/kitchensink) showcases every column and field type. It is toggled by config('app.kitchensink') (plain boolean, not env), which gates the route in routes/crud.php, the flask link in base/inc/menu.blade.php, and an abort_unless in the controller. Its tables live on the "kitchensink" SQLite connection (database/kitchensink.sqlite, gitignored) with migrations under database/migrations/kitchensink; create/reset it with `php artisan kitchensink:install [--fresh]`, never with plain `migrate`. Models are in app/Models/KitchenSink with $connection = 'kitchensink'. Field names must be unique per form: Backpack keys fields by name, so two field types on the same column silently collapse into one.
