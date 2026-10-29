<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Report;
use App\Reports\CrawlReport;
use Illuminate\Http\Response;

/**
 * Approved monthly reports and each project's report archive, opened by the client from a link. The long random
 * token is the only access control; drafts are never served here.
 */
class MonthlyReportController extends Controller
{
    public function show(string $token): Response
    {
        $report = $this->report($token);
        $html = str_replace(Report::PDF_URL_PLACEHOLDER, route('monthly-report.pdf', $token), $report->html);

        return response($html)->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function pdf(string $token): Response
    {
        $report = $this->report($token);

        return response(CrawlReport::renderPdf($report->pdf_html), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$report->filename().'"',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    public function archive(string $token): Response
    {
        $project = Project::where('archive_token', $token)->with('client')->firstOrFail();
        $reports = $project->reports()->whereIn('status', ['approved', 'sent'])->orderByDesc('period')->get();

        return response(view('reports.archive', ['project' => $project, 'reports' => $reports, 'brand' => config('screamingrabbit.brand')]))
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    /** The panel's preview of any report, draft included, for members who can see its project. */
    public function preview(Report $report): Response
    {
        abort_unless(auth()->user()?->canSeeProject($report->project_id), 404);
        $html = $report->token ? str_replace(Report::PDF_URL_PLACEHOLDER, route('monthly-report.pdf', $report->token), $report->html) : str_replace('href="'.Report::PDF_URL_PLACEHOLDER.'"', 'href="#"', $report->html);

        return response($html)->header('X-Robots-Tag', 'noindex, nofollow');
    }

    private function report(string $token): Report
    {
        return Report::where('token', $token)->whereIn('status', ['approved', 'sent'])->firstOrFail();
    }
}
