<!DOCTYPE html>
<html lang="en">
<head>
    @include(backpack_view('inc.head'))
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