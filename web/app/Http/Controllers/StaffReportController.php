<?php

namespace App\Http\Controllers;

use App\Models\OfficeSetting;
use App\Services\ReportService;
use App\Support\WorkSchedule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StaffReportController extends Controller
{
    public function __construct(private readonly ReportService $reports)
    {
    }

    public function index(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if (! $user->isStaff()) {
            return redirect()->route('admin.reports.index');
        }

        $date = $request->query('date')
            ? WorkSchedule::local($request->query('date'))->startOfDay()
            : WorkSchedule::now()->startOfDay();
        $month = $this->reports->localMonth($request->query('month'));
        $setting = OfficeSetting::current();
        $mode = $this->reports->normaliseMode($setting->total_time_mode);
        $includeAway = (bool) $setting->total_time_include_away;

        return view('reports.mine', [
            'date' => $date,
            'summary' => $this->reports->daily($user, $date),
            'month' => $month,
            'monthly' => $this->reports->monthly($user, $month, $mode, $includeAway),
            'mode' => $mode,
            'modes' => $this->reports->totalModes(),
            'includeAway' => $includeAway,
            'schedule' => WorkSchedule::status(null, $user),
            'windows' => WorkSchedule::windows($user),
            'breaks' => WorkSchedule::breakRules($user),
        ]);
    }
}
