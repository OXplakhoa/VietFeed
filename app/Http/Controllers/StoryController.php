<?php

namespace App\Http\Controllers;

use App\StoryLens\StoryLens;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

class StoryController extends Controller
{
    public function show(string $story, StoryLens $lens): View
    {
        return view('stories.show', ['lens' => $lens->readForPage($story)]);
    }

    public function sections(string $story, StoryLens $lens): JsonResponse
    {
        return response()->json($lens->readForPage($story))->header('Cache-Control', 'no-store');
    }
}
