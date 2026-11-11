<?php

namespace App\Http\Controllers;

use App\Services\ReportService;
use App\Support\WorkSchedule;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(private readonly ReportService $reports)
    {
    }

    public function index(Request $request): View
    {
        $user = $request->user();
        $date = $request->query('date');

        if ($user->isAdmin()) {
            $team = $this->reports->teamDaily($date);
            $totals = $this->reports->teamTotals($team);
            $day = $date ? WorkSchedule::local($date)->startOfDay() : WorkSchedule::now()->startOfDay();

            return view('admin.dashboard', [
                'team' => $team,
                'totals' => $totals,
                'date' => $day,
                'schedule' => WorkSchedule::status(null, $user),
                'recentScreenshots' => \App\Models\Screenshot::query()
                    ->with('user')
                    ->latest('taken_at')
                    ->limit(8)
                    ->get(),
            ]);
        }

        $summary = $this->reports->daily($user, $date);
        $week = $this->reports->weekly($user);
        $screenshots = $user->screenshots()
            ->latest('taken_at')
            ->limit(12)
            ->get();

        return view('staff.dashboard', [
            'summary' => $summary,
            'week' => $week,
            'screenshots' => $screenshots,
            'schedule' => WorkSchedule::status(null, $user),
        ]);
    }
}
