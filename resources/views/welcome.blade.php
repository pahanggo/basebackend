<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>{{config('app.name')}}</title>
    <link rel="stylesheet" href="/packages/backpack/base/css/bundle.css">
</head>
<body>
    <div class="container pt-5 mb-5">
        <div class="float-right">
            <a href="/app" class="btn btn-primary">
                Backend
            </a>
        </div>
        {!! Markdown::parse(file_get_contents(base_path('README.md'))) !!}
    </div>
</body>
</html>