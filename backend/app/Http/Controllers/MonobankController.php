<?php

namespace App\Http\Controllers;

use App\Actions\Monobank\ConnectMonobankAction;
use App\Actions\Monobank\DisconnectMonobankAction;
use App\Actions\Monobank\UpdateMonobankSettingsAction;
use App\Exceptions\MonobankException;
use App\Http\Resources\MonobankConnectionResource;
use App\Jobs\SyncMonobankConnectionJob;
use App\Services\Monobank\MonobankClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MonobankController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $connection = $request->user()->monobankConnection()?->with('accounts')->first();

        if (!$connection) {
            return response()->json(['connected' => false]);
        }

        return (new MonobankConnectionResource($connection))->response();
    }

    public function connect(Request $request, ConnectMonobankAction $connect): JsonResponse
    {
        $data = $request->validate([
            'token' => 'required|string|min:8|max:512',
        ]);

        try {
            $connection = $connect->execute($request->user(), $data['token']);
        } catch (MonobankException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return (new MonobankConnectionResource($connection))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, UpdateMonobankSettingsAction $update): JsonResponse
    {
        $data = $request->validate([
            'notify' => 'sometimes|boolean',
            'accounts' => 'sometimes|array',
            'accounts.*.id' => 'required|integer',
            'accounts.*.enabled' => 'sometimes|boolean',
            'accounts.*.tag_id' => 'nullable|integer',
            'jars' => 'sometimes|array',
            'jars.*.id' => 'required|integer',
            'jars.*.track_as_goal' => 'sometimes|boolean',
        ]);

        try {
            $connection = $update->execute($request->user(), $data);
        } catch (MonobankException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return (new MonobankConnectionResource($connection))->response();
    }

    public function sync(Request $request, MonobankClient $client): JsonResponse
    {
        $connection = $request->user()->monobankConnection;

        if (!$connection) {
            return response()->json(['message' => 'Monobank is not connected.'], 404);
        }

        $wait = $client->secondsUntilReady($connection->id);

        if ($wait > 0) {
            return response()->json([
                'message' => "Monobank allows one refresh per minute. Try again in {$wait} seconds.",
            ], 429)->header('Retry-After', (string) $wait);
        }

        $connection->forceFill([
            'status' => \App\Models\MonobankConnection::STATUS_SYNCING,
            'last_error' => null,
        ])->save();

        SyncMonobankConnectionJob::dispatch($connection->id, false)->afterResponse();

        return (new MonobankConnectionResource($connection->fresh('accounts')))->response();
    }

    public function destroy(Request $request, DisconnectMonobankAction $disconnect): JsonResponse
    {
        $disconnect->execute($request->user());

        return response()->json(['connected' => false, 'message' => 'Monobank disconnected.']);
    }
}
