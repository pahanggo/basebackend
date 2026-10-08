<?php

namespace App\Http\Controllers\Reports;

use App\Models\Auth\Role;
use App\Models\User;
use App\Traits\ReportTrait;

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
    public function getGroupName(): string
    {
        return 'Access Control';
    }

    // what the user will see the widget name as
    public function getReportName(): string
    {
        return 'R01: User Report';
    }

    /**
     * Human-readable summary of the active filters, shown under the report title.
     *
     * Each clause is a whole translatable sentence with placeholders rather than
     * concatenated words, because clause order and wording differ between
     * languages. Safe to translate here (unlike getReportName()/getGroupName(),
     * which ReportService caches in a static property at boot).
     */
    public function getReportSubtitle(): string
    {
        $filters = request()->get('filter', []);
        $conditions = [];

        $from = $filters['created_at']['from'] ?? null;
        $to = $filters['created_at']['to'] ?? null;

        if ($from && $to) {
            $conditions[] = __('created between :from and :to', ['from' => $from, 'to' => $to]);
        } elseif ($from) {
            $conditions[] = __('created from :from', ['from' => $from]);
        } elseif ($to) {
            $conditions[] = __('created until :to', ['to' => $to]);
        }

        if ($role = Role::find($filters['role'] ?? null)) {
            $conditions[] = __('has role :role', ['role' => $role->name]);
        }

        if (! $conditions) {
            return '';
        }

        return __('Where :conditions', [
            'conditions' => implode(' '.__('and').' ', $conditions),
        ]);
    }

    protected function getData()
    {
        return array_merge($this->paretGetData(), [
            'roles' => Role::all(),
        ]);
    }

    // return query to get data
    protected function getQuery()
    {
        $request = request();
        $query = User::with('roles')->orderBy('id');
        if ($request->has('filter')) {
            foreach ($request->get('filter', []) as $name => $value) {
                if (! $value) {
                    continue;
                }
                switch ($name) {
                    case 'created_at':
                        if (isset($value['from'])) {
                            $query->where('created_at', '>=', $value['from'].' 00:00:00');
                        }
                        if (isset($value['to'])) {
                            $query->where('created_at', '<=', $value['to'].' 23:59:59');
                        }
                        break;
                    case 'role':
                        $query->whereHas('roles', function ($query) use ($value) {
                            $query->where('id', $value);
                        });
                        break;
                }
            }
        }

        return $query;
    }

    protected function getViewPath(): string
    {
        return 'reports.user-report';
    }
}
