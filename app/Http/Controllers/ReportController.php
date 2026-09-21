<?php

namespace App\Http\Controllers;

use App\Services\ReportService;

class ReportController extends Controller
{
    public function index()
    {
        return view('reports.index', [
            'reports' => ReportService::all(),
        ]);
    }
}
