<?php

namespace App\Http\Controllers\Daily;

use App\Handover\Teams;
use App\Http\Controllers\Controller;
use App\Models\DailyIssue;
use Illuminate\View\View;

/** Halaman Daily Issue (?role=polisi|engineer, ?issue=ID, ?embed=1). */
class HalamanController extends Controller
{
    public function __invoke(): View
    {
        return view('daily', [
            'teams' => Teams::ALL,
            'statuses' => DailyIssue::STATUS_LABEL,
            'engineerStatuses' => DailyIssue::ENGINEER_STATUSES,
            'media' => DailyIssue::MEDIA,
            'mediaDefault' => DailyIssue::MEDIA_DEFAULT,
            'portalUrl' => config('handover.web.portal_url'),
            'portalNama' => session('portal_user.nama', ''),
        ]);
    }
}
