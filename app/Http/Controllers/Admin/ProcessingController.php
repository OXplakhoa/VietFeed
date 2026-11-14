<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\StoryProcessing\StoryPipeline;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use MongoDB\Driver\Exception\ConnectionException;

class ProcessingController extends Controller
{
    public function index(StoryPipeline $pipeline): View
    {
        try {
            return view('admin.processing', ['overview' => $pipeline->overview(), 'unavailable' => false]);
        } catch (\Throwable $error) {
            $this->reportUnexpected($error, 'overview');

            return view('admin.processing', ['overview' => ['sections' => [], 'stories' => []], 'unavailable' => true]);
        }
    }

    public function retry(Request $request, StoryPipeline $pipeline): RedirectResponse
    {
        $input = $request->only('section', 'id');
        $validator = Validator::make($input, [
            'section' => 'required|string|in:timeline,graph',
            'id' => ['required', 'string', 'max:50', 'regex:/^\d+-\d+$/D'],
        ]);
        if ($validator->fails()) {
            return redirect()->route('admin.processing.index')->with('error', 'Mục hoặc mã yêu cầu không hợp lệ.');
        }
        try {
            $pipeline->stream($input['section'])->replay($input['id']);
        } catch (\InvalidArgumentException) {
            // replay() uses this for missing entries, including an already replayed ID.
            return redirect()->route('admin.processing.index')->with('error', 'Yêu cầu không còn trong danh sách.');
        } catch (\Throwable $error) {
            $this->reportUnexpected($error, 'retry');

            return redirect()->route('admin.processing.index')->with('error', 'Không thể đưa yêu cầu vào hàng đợi. Hãy kiểm tra lại danh sách.');
        }

        return redirect()->route('admin.processing.index')->with('success', 'Đã đưa yêu cầu vào hàng đợi. Đang chờ bộ xử lý.');
    }

    private function reportUnexpected(\Throwable $error, string $operation): void
    {
        // RedisResult wraps driver failures; inspect the chain without logging payloads.
        for ($cause = $error; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof \RedisException || $cause instanceof ConnectionException) {
                Log::warning('Story processing '.$operation.' unavailable.', ['exception' => $cause::class]);

                return;
            }
        }
        report($error);
    }
}
