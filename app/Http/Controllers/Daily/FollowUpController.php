<?php

namespace App\Http\Controllers\Daily;

use App\Http\Controllers\Controller;
use App\Http\Requests\Daily\FollowUpRequest;
use App\Models\DailyIssue;
use Illuminate\Http\JsonResponse;

/** Panel Issue Follow-up: daftar issue yang ditandai dan belum Done. */
class FollowUpController extends Controller
{
    public function index(): JsonResponse
    {
        $issues = DailyIssue::followUpAktif()->terbaru()->with('lampiran')->get();

        return self::json($issues->map(fn (DailyIssue $issue) => $issue->toRow())->all());
    }

    public function update(FollowUpRequest $request, DailyIssue $issue): JsonResponse
    {
        $followUp = $request->validated('follow_up');
        if ($followUp && $issue->status === 'done') {
            return self::json(['error' => 'Issue berstatus Done tidak dapat ditambahkan ke follow-up.'], 422);
        }

        $issue->update(['follow_up' => $followUp]);

        return self::json($issue->fresh()->load('lampiran')->toRow());
    }
}
