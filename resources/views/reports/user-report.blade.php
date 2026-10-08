@extends($reportLayout)

@section('report-filters')
    <div class="row mb-2">
        <div class="col-sm-3">
            <div class="mb-2 text-strong text-value-sm">
                {{ __('Created') }}
            </div>
            <input type="date" value="{{request()->input('filter.created_at.from')}}" class="form-control mb-2" name="filter[created_at][from]">
            <input type="date" value="{{request()->input('filter.created_at.to')}}" class="form-control" name="filter[created_at][to]">
        </div>
        <div class="col-sm-3">
            <div class="mb-2 text-strong text-value-sm">
                {{ __('Roles') }}
            </div>
            <select class="form-control" name="filter[role]" id="">
                <option value="">-</option>
                @foreach($roles as $role)
                <option @if(request()->input('filter.role') == $role->id) selected @endif value="{{$role->id}}">{{$role->name}}</option>
                @endforeach
            </select>
        </div>
    </div>
    <button class="btn btn-primary">
        {{ __('Filter') }}
    </button>
    @if(request()->has('filter'))
    <a href="?{{request()->page ? 'page=' . request()->page : '' }}" class="btn btn-default">
        {{ __('Reset') }}
    </a>
    @endif
    <div class="float-right">
        <button name="export" value="excel" class="btn btn-default">
            <i class="la la-download"></i> {{ __('Export') }}
        </button>
    </div>
@endsection

@section('report-body')
<div class="table-responsive">
    <table class="table table-bordered table-striped">
        <thead>
            <tr>
                <th>ID</th>
                <th>{{ __('Name') }}</th>
                <th>{{ __('Username') }}</th>
                <th>{{ __('Email') }}</th>
                <th>{{ __('Roles') }}</th>
                <th>{{ __('Created At') }}</th>
                <th>{{ __('Updated At') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($reportData as $row)
            <tr>
                <td>
                    {{$row->id}}
                </td>
                <td>
                    {{$row->name}}
                </td>
                <td>
                    {{$row->username}}
                </td>
                <td>
                    {{$row->email}}
                </td>
                <td>
                    {{implode(', ', $row->roles->pluck('name')->toArray())}}
                </td>
                <td>
                    {{format_datetime($row->created_at)}}
                </td>
                <td>
                    {{format_datetime($row->updated_at)}}
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>

{{$reportData->appends(['filter' => request()->get('filter')])->links()}}
@endsection