<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Content\GenerateCampaignContent;
use App\Http\Requests\StreamCampaignContentRequest;
use App\Llm\ContentEvent;
use App\Models\Campaign;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class CampaignContentStreamController extends Controller
{
    public function __invoke(Campaign $campaign, StreamCampaignContentRequest $request, GenerateCampaignContent $generateContent): StreamedResponse
    {
        // Refuse with a normal JSON error before the stream starts; after that only events can carry errors.
        $campaign->assertContentCanBeSet();
        $tone = $request->tone();

        return response()->stream(function () use ($campaign, $tone, $generateContent): void {
            try {
                foreach ($generateContent($campaign, $tone) as $event) {
                    $this->send($event->type, $this->payload($event));

                    // A closed tab stops the loop; dropping the generator cancels the model stream (no wasted tokens).
                    if (connection_aborted() === 1) {
                        return;
                    }
                }
            } catch (Throwable $failure) {
                report($failure);
                $this->send(ContentEvent::FAILED, ['code' => 'server_error', 'message' => 'Content generation failed unexpectedly.', 'errors' => []]);
            }
        }, headers: [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            // Tells nginx not to buffer this response, whatever the location block says.
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(ContentEvent $event): array
    {
        return match ($event->type) {
            ContentEvent::DELTA => ['text' => $event->text],
            ContentEvent::COMPLETED => [
                'sms' => ['body' => $event->sms?->body],
                'email' => ['subject' => $event->email?->subject, 'body' => $event->email?->body],
                'repaired' => $event->repaired,
            ],
            default => ['code' => $event->code, 'message' => $event->message, 'errors' => $event->errors],
        };
    }

    /** @param  array<string, mixed>  $payload */
    private function send(string $type, array $payload): void
    {
        echo "event: {$type}\ndata: ".json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n\n";

        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }
}
