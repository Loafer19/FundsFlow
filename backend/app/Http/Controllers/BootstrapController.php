<?php

namespace App\Http\Controllers;

use App\Actions\Bootstrap\LoadBootstrapDataAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BootstrapController extends Controller
{
    public function __construct(
        private readonly LoadBootstrapDataAction $loadBootstrapData,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        return response()->json(
            $this->loadBootstrapData->execute($request->user()),
            200,
            [],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }
}
