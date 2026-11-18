<?php

namespace App\Http\Controllers;

use App\Interaction\InteractionToggle;
use App\Models\Boost;
use Illuminate\Http\Request;

class BoostController extends Controller
{
    public function __construct(private InteractionToggle $toggle) {}

    public function toggle(Request $request)
    {
        if (! $request->user()->canBoost()) {
            return response()->json([
                'error' => 'restricted',
                'message' => 'Tài khoản đang bị hạn chế nên không thể đề xuất bài viết.',
            ], 403);
        }

        $request->validate(['article_id' => 'required|string|exists:mongodb.articles,_id']);

        return response()->json($this->toggle->run(Boost::class, (string) $request->user()->getKey(), $request->string('article_id')->toString()));
    }
}
