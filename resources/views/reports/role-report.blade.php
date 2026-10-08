@extends($reportLayout)

@section('report-body')
<div class="table-responsive">
    <table class="table table-bordered table-striped">
        <thead>
            <tr>
                <th>ID</th>
                <th>{{ __('Name') }}</th>
                <th>{{ __('Users') }}</th>
                <th>{{ __('Permissions') }}</th>
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
                    {{$row->users->count()}}
                </td>
                <td>
                    {{implode(', ', $row->permissions->pluck('name')->toArray())}}
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
{{$reportData->links()}}
@endsection