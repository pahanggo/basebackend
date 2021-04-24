@extends(backpack_view('blank'))

@section('after_styles')
    <style media="screen">
        .backpack-profile-form .required::after {
            content: ' *';
            color: red;
        }
    </style>
@endsection

@php
  $breadcrumbs = [
      trans('backpack::crud.admin') => url(config('backpack.base.route_prefix'), 'dashboard'),
      trans('backpack::base.my_account') => false,
  ];
@endphp

@section('header')
    <section class="content-header">
        <div class="container-fluid mb-3">
            <h1>{{ trans('backpack::base.my_account') }}</h1>
        </div>
    </section>
@endsection

@section('content')
    <div class="row">

        @if (session('success'))
        <div class="col-lg-12">
            <div class="alert alert-success">
                {{ session('success') }}
            </div>
        </div>
        @endif

        @if ($errors->count())
        <div class="col-lg-12">
            <div class="alert alert-danger">
                <ul class="mb-1">
                    @foreach ($errors->all() as $e)
                    <li>{{ $e }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
        @endif

        {{-- UPDATE INFO FORM --}}
        <div class="col-lg-12">
            <form class="form" action="{{ route('backpack.account.info.store') }}" method="post">

                {!! csrf_field() !!}

                <div class="card padding-10">

                    <div class="card-header">
                        {{ trans('backpack::base.update_account_info') }}
                    </div>

                    <div class="card-body backpack-profile-form bold-labels">
                        <div class="row">
                            <div class="col-md-3 form-group text-center">
                                <a onclick="changeProfilePicture()" class="user-image d-block" style="cursor:pointer;bottom: -10px;">
                                    <img src="{{$user->getAvatarUrl()}}" class="img has-hover img-avatar" style="height: 70px">
                                </a>
                                <small>Change Avatar</small>
                            </div>

                            <div class="col-md-3 form-group">
                                @php
                                    $label = trans('backpack::base.name');
                                    $field = 'name';
                                @endphp
                                <label class="required">{{ $label }}</label>
                                <input required class="form-control" type="text" name="{{ $field }}" value="{{ old($field) ? old($field) : $user->$field }}">
                            </div>

                            <div class="col-md-3 form-group">
                                @php
                                    $label = config('backpack.base.authentication_column_name');
                                    $field = backpack_authentication_column();
                                @endphp
                                <label class="required">{{ $label }}</label>
                                <input required class="form-control" type="{{ backpack_authentication_column()=='email'?'email':'text' }}" name="{{ $field }}" value="{{ old($field) ? old($field) : $user->$field }}">
                            </div>

                            <div class="col-md-3 form-group">
                                <label class="required">Email</label>
                                <input required class="form-control" type="email" name="email" value="{{ old('email') ? old('email') : $user->email }}">
                            </div>
                        </div>
                    </div>

                    <div class="card-footer">
                        <button type="submit" class="btn btn-success"><i class="la la-save"></i> {{ trans('backpack::base.save') }}</button>
                        <a href="{{ backpack_url() }}" class="btn">{{ trans('backpack::base.cancel') }}</a>
                    </div>
                </div>

            </form>
        </div>

        {{-- CHANGE PASSWORD FORM --}}
        <div class="col-lg-12">
            <form class="form" action="{{ route('backpack.account.password') }}" method="post">

                {!! csrf_field() !!}

                <div class="card padding-10">

                    <div class="card-header">
                        {{ trans('backpack::base.change_password') }}
                    </div>

                    <div class="card-body backpack-profile-form bold-labels">
                        <div class="row">
                            <div class="col-md-4 form-group">
                                @php
                                    $label = trans('backpack::base.old_password');
                                    $field = 'old_password';
                                @endphp
                                <label class="required">{{ $label }}</label>
                                <input autocomplete="new-password" required class="form-control" type="password" name="{{ $field }}" id="{{ $field }}" value="">
                            </div>

                            <div class="col-md-4 form-group">
                                @php
                                    $label = trans('backpack::base.new_password');
                                    $field = 'new_password';
                                @endphp
                                <label class="required">{{ $label }}</label>
                                <input autocomplete="new-password" required class="form-control" type="password" name="{{ $field }}" id="{{ $field }}" value="">
                            </div>

                            <div class="col-md-4 form-group">
                                @php
                                    $label = trans('backpack::base.confirm_password');
                                    $field = 'confirm_password';
                                @endphp
                                <label class="required">{{ $label }}</label>
                                <input autocomplete="new-password" required class="form-control" type="password" name="{{ $field }}" id="{{ $field }}" value="">
                            </div>
                        </div>
                    </div>

                    <div class="card-footer">
                            <button type="submit" class="btn btn-success"><i class="la la-save"></i> {{ trans('backpack::base.change_password') }}</button>
                            <a href="{{ backpack_url() }}" class="btn">{{ trans('backpack::base.cancel') }}</a>
                    </div>

                </div>

            </form>

            @if(config('auth.socialite.enabled'))
            <div class="card padding-10">
                <div class="card-header">
                    Linked Social Accounts
                </div>
                <div class="card-body">
                    <div class="list-group">
                        @if('auth.socialite.providers.google')
                        <div class="list-group-item d-flex justify-content-between align-items-center">
                            <div>
                                <i class="la la-google-plus"></i>
                                Google Logins
                            </div>
                            <a href="{{route('socialite.google')}}" class="btn btn-primary">Link</a>
                        </div>
                        @foreach(user()->socialAccounts()->whereAccountType('google')->get() as $account)
                        <div class="list-group-item d-flex justify-content-between align-items-center">
                            <div>
                                {{$account->name}} <br>
                                {{$account->email}}
                            </div>
                            <a href="{{route('socialite.unlink', ['token' => encrypt($account->id)])}}" class="btn btn-warning">Unlink</a>
                        </div>
                        @endforeach
                        <div class="mb-3"></div>
                        @endif
                        @if('auth.socialite.providers.github')
                        <div class="list-group-item d-flex justify-content-between align-items-center">
                            <div>
                                <i class="la la-github"></i>
                                Github Logins
                            </div>
                            <a href="{{route('socialite.github')}}" class="btn btn-primary">Link</a>
                        </div>
                        @endif
                        @foreach(user()->socialAccounts()->whereAccountType('github')->get() as $account)
                        <div class="list-group-item d-flex justify-content-between align-items-center">
                            <div>
                                {{$account->name}} <br>
                                {{$account->email}}
                            </div>
                            <a href="{{route('socialite.unlink', ['token' => encrypt($account->id)])}}" class="btn btn-warning">Unlink</a>
                        </div>
                        @endforeach
                        <div class="mb-3"></div>
                    </div>
                </div>
            </div>
            @endif
        </div>

    </div>
@endsection

@push('after_scripts')
<div class="modal" id="change-profile-image" tabindex="-1" role="dialog">
    <div class="modal-lg modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Change Avatar</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <input type="file" name="" id="chosenAvatar" accept=".jpg,.png">
                </div>
                <img src="" alt="" id="chosenAvatarOutput">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" onclick="saveAvatar()">Save Avatar</button>
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/croppie/2.6.5/croppie.min.js" integrity="sha512-Gs+PsXsGkmr+15rqObPJbenQ2wB3qYvTHuJO6YJzPe/dTLvhy0fmae2BcnaozxDo5iaF8emzmCZWbQ1XXiX2Ig==" crossorigin="anonymous"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/croppie/2.6.5/croppie.min.css" integrity="sha512-zxBiDORGDEAYDdKLuYU9X/JaJo/DPzE42UubfBw9yg8Qvb2YRRIQ8v4KsGHOx2H1/+sdSXyXxLXv5r7tHc9ygg==" crossorigin="anonymous" />
<script>
    function changeProfilePicture() {
        $('#change-profile-image').modal('show');
    }
    var avatarCroppie;
    $('#chosenAvatar').change(function(event){
        var input = event.target;
        if(input.files.length > 0){

            var reader = new FileReader();
            reader.onload = function(){
                var dataURL = reader.result;
                var output = document.getElementById('chosenAvatarOutput');
                output.src = dataURL;

                if(avatarCroppie) {
                    avatarCroppie.destroy();
                }
                avatarCroppie = new Croppie(output, {
                    viewport: { width: 300, height: 300, type: 'circle' },
                    boundary: { width: 400, height: 400 },
                });
            };
            reader.readAsDataURL(input.files[0]);
        }
    });
    function saveAvatar() {
        if(avatarCroppie) {
            avatarCroppie.result({ quality: 0.8 })
                .then(function(res){
                    $.post('{{route('user.update-avatar')}}', {
                        _token: $('meta[name=csrf-token]').attr('content'),
                        payload: res
                    }).done(function(response){
                        $('#change-profile-image').modal('hide');
                        window.location.reload(true);
                    });
                })
        }
    }
</script>
@endpush
