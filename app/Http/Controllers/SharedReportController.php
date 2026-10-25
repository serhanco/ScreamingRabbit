<?php

namespace App\Http\Controllers;

use App\Models\Crawl;
use App\Reports\CrawlReport;
use Illuminate\Http\Response;

class SharedReportController extends Controller
{
    public function show(string $token): Response
    {
        $report = new CrawlReport($this->crawl($token));

        return response($report->html())->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function pdf(string $token): Response
    {
        $report = new CrawlReport($this->crawl($token));

        return response($report->pdf(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$report->filename().'"',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    private function crawl(string $token): Crawl
    {
        return Crawl::where('share_token', $token)->where('status', 'completed')->firstOrFail();
    }
}
