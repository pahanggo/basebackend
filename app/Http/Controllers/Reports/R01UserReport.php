<?php

namespace App\Http\Controllers\Reports;

use App\Models\Auth\Role;
use App\Models\User;
use App\Traits\ReportTrait;
use Illuminate\Http\Request;

class R01UserReport
{
    // report
    use ReportTrait {
        getData as paretGetData;
    }

    // only users with this permission can add & view this widget
    public function getRequiredPermission()
    {
        return 'Manage Users';
    }

    // grouping of report
    public function getGroupName() : string
    {
        return 'Access Control';
    }

    // what the user will see the widget name as
    public function getReportName() : string
    {
        return 'R01: User Report';
    }

    public function getReportSubtitle(): string
    {
        $subtitles = [];
        $request = request();
        if($request->has('filter')) {
            foreach($request->get('filter', []) as $name => $value) {
                if(!$value) continue;
                $subtitle = '';
                switch($name) {
                    case 'created_at':
                        if(isset($value['from']) || isset($value['to'])) {
                            $subtitle .= ' created at';
                        }
                        if(isset($value['from'])) {
                            $subtitle .= ' from ' . $value['from'];
                        }
                        if(isset($value['to'])) {
                            $subtitle .= ' to ' . $value['to'];
                        }
                        if(isset($value['from']) || isset($value['to'])) {
                            $subtitles[] = $subtitle;
                        }
                        break;
                    case 'role':
                        $subtitles[] = ' has role ' . Role::find($value)->name;
                        break;
                }
            }
        }
        if(!count($subtitles)) {
            return '';
        }
        return 'Where ' . trim(implode(' and ', $subtitles));
    }

    protected function getData()
    {
        return array_merge($this->paretGetData(), [
            'roles' => Role::all()
        ]);
    }

    // return query to get data
    protected function getQuery()
    {
        $request = request();
        $query = User::with('roles')->orderBy('id');
        if($request->has('filter')) {
            foreach($request->get('filter', []) as $name => $value) {
                if(!$value) continue;
                switch($name) {
                    case 'created_at':
                        if(isset($value['from'])) {
                            $query->where('created_at', '>=', $value['from'] . ' 00:00:00');
                        }
                        if(isset($value['to'])) {
                            $query->where('created_at', '<=', $value['to'] . ' 23:59:59');
                        }
                        break;
                    case 'role':
                        $query->whereHas('roles', function($query) use ($value) {
                            $query->where('id', $value);
                        });
                        break;
                }
            }
        }
        return $query;
    }

    protected function getViewPath() : string
    {
        return 'reports.user-report';
    }
}