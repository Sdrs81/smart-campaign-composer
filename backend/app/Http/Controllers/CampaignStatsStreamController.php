<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Delivery\CampaignStatsStream;
use App\Models\Campaign;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CampaignStatsStreamController extends Controller
{
    public function __invoke(Campaign $campaign, CampaignStatsStream $stream): StreamedResponse
    {
        return response()->stream(function () use ($campaign, $stream): void {
            // The stream lives up to CampaignStatsStream::MAX_SECONDS; PHP's default 30 s limit would kill it early.
            set_time_limit(0);

            foreach ($stream($campaign) as $frame) {
                echo $frame;

                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();

                // A closed tab ends the loop so the worker thread is freed.
                if (connection_aborted() === 1) {
                    return;
                }
            }
        }, headers: [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            // Tells nginx not to buffer this response, whatever the location block says.
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
