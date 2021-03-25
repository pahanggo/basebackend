<?php

namespace App\Traits;

use App\Exports\ReportExporter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

trait ReportTrait {

    public function isEnabled() : bool
    {
        return true;
    }

    public function getRequiredPermission()
    {
        return null;
    }

    public function getPath() : string
    {
        return $this->getLowerName();
    }

    public function getReportName() : string
    {
        return class_basename($this);
    }

    public function getReportTitle() : string
    {
        return $this->getReportName();
    }

    public function getReportSubtitle() : string
    {
        return '';
    }

    public function getGroupName() : string
    {
        return 'Report';
    }

    public function setup()
    {
        $middleware = [];
        if($this->getRequiredPermission()) {
            $middleware[] = 'can:' . $this->getRequiredPermission();
        }
        Route::group([
            'as'         => 'reports.' . $this->getLowerName() . '.',
            'middleware' => array_merge([config('backpack.base.web_middleware', 'web')], $middleware),
            'prefix'     => config('backpack.base.route_prefix') . '/report/' . $this->getLowerName()
        ], function(){
            Route::get('/', [
                'as'   => 'index',
                'uses' => static::class . '@index',
            ]);
        });
    }

    public function index(Request $request)
    {
        if($request->export) {
            return $this->export($request->get('export'));
        }
        return view($this->getViewPath(), $this->getData());
    }

    // protected

    protected abstract function getQuery();

    protected abstract function getViewPath() : string;

    protected function export($type)
    {
        return Excel::download(new ReportExporter($this->getViewPath(), $this->getData()), Str::slug($this->getReportTitle()) . '.xlsx');
    }

    protected function getData()
    {
        return [
            'reportLayout' => request()->export ? 'reports.export-layout' : 'reports.layout',
            'reportGroup' => $this->getGroupName(),
            'reportName' => $this->getReportName(),
            'reportTitle' => $this->getReportTitle(),
            'reportSubtitle' => $this->getReportSubtitle(),
            'reportData' => request()->export ? $this->getQuery()->paginate(9999999) : $this->getQuery()->paginate(100),
        ];
    }

    protected function getClassName() : string
    {
        return class_basename($this);
    }

    protected function getLowerName() : string
    {
        return Str::slug($this->getReportName());
    }
}