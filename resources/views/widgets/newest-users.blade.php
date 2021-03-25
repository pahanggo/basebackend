<div class="col-md-3 animated bounceIn">
    <div class="card mb-1">
        <div class="card-header">
            <strong>
                Newest Users
            </strong>
        </div>
        <ul class="list-group mb-0">
            @foreach($users as $user)
            <li class="list-group-item">
                <div class="d-flex">
                    <div class="mr-2">
                        <img src="{{$user->getAvatarUrl()}}" style="height:40px" alt="{{$user->name}}" class="img-avatar">
                    </div>
                    <div>
                        {{$user->name}} <br>
                        <small>{{$user->created_at->fromNow()}}</small>
                    </div>
                </div>
            </li>
            @endforeach
        </ul>
    </div>
</div>