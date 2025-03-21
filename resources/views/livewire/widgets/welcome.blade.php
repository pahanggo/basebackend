<div class="col-12">
    <div class="d-sm-flex d-block text-center text-sm-left">
        <style>
        </style>
        <div class="welcome-avatar">
            <img style="height: 70px" class="img-avatar" src="{{ backpack_avatar_url(backpack_auth()->user()) }}" alt="{{ backpack_auth()->user()->name }}">
        </div>
        <div class="ml-sm-2">
            <h1 class="mb-0 d-none d-sm-block">
                {{__('Welcome')}} {{$user->name}}
            </h1>
            <h3 class="mb-0 d-block d-sm-none mt-2">
                {{__('Welcome')}} {{$user->name}}
            </h3>
            <div>
                <small>
                    <em>
                        {{$user->email}} - {{implode(', ', $user->roles->pluck('name')->toArray())}}
                    </em>
                </small>
            </div>
        </div>
    </div>
    <hr>
</div>