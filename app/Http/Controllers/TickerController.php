<?php

namespace App\Http\Controllers;

use App\Services\TickerService;
use Illuminate\Http\JsonResponse;

class TickerController extends Controller
{
    public function __construct(private readonly TickerService $ticker) {}

    public function index(): JsonResponse
    {
        return response()->json($this->ticker->fetch());
    }
}
