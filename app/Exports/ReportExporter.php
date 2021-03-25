<?php

namespace App\Exports;

use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Concerns\FromView;

class ReportExporter implements FromView
{
    protected $viewPath;
    protected $exportData;

    public function __construct($viewPath, $exportData)
    {
        $this->viewPath = $viewPath;
        $this->exportData = $exportData;
    }

    public function view(): View
    {
        return view($this->viewPath, $this->exportData);
    }

}