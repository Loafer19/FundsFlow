<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessMonobankWebhookJob;
use App\Models\MonobankConnection;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class MonobankWebhookController extends Controller
{
    /**
     * Mono sends GET to confirm the URL. The reply must be exactly HTTP 200.
     */
    public function challenge(string $uuid): Response
    {
        MonobankConnection::query()->where('webhook_uuid', $uuid)->firstOrFail();

        return response('', 200);
    }

    public function handle(Request $request, string $uuid): Response
    {
        $connection = MonobankConnection::query()->where('webhook_uuid', $uuid)->first();

        if ($connection) {
            $payload = $request->all();

            if (is_array($payload)) {
                ProcessMonobankWebhookJob::dispatch($connection->id, $payload);
            }
        }

        return response('', 200);
    }
}
