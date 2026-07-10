<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Internal\SyncResultRequest;
use App\Services\SyncResultService;
use Illuminate\Http\JsonResponse;

class SyncResultController extends Controller
{
    public function store(SyncResultRequest $request, SyncResultService $service): JsonResponse
    {
        return response()->json($service->store($request->validated()));
    }
}
