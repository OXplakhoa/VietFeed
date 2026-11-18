<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Moderation\ModerationAudit;
use App\StoryProcessing\StoryPipeline;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use MongoDB\Driver\Exception\ConnectionException;

class ProcessingController extends Controller
{
    public function index(StoryPipeline $pipeline, ModerationAudit $audit): View
    {
        // Gate 9b: the moderation section is independent of the Story overview (REVIEW-1 NIT-5).
        $data = ['moderation' => $this->moderationPart(fn () => $audit->overview(), 'overview')];
        try {
            return view('admin.processing', ['overview' => $pipeline->overview(), 'unavailable' => false] + $data);
        } catch (\Throwable $error) {
            $this->reportUnexpected($error, 'overview');

            return view('admin.processing', ['overview' => ['sections' => [], 'stories' => []], 'unavailable' => true] + $data);
        }
    }

    private function moderationPart(callable $read, string $operation): ?array
    {
        try {
            return $read();
        } catch (\Throwable $error) {
            for ($cause = $error; $cause !== null; $cause = $cause->getPrevious()) {
                if ($cause instanceof \RedisException || $cause instanceof ConnectionException) {
                    // Notice level: the Story section already reports store outages as warnings.
                    Log::notice('Moderation processing '.$operation.' unavailable.', ['exception' => $cause::class]);

                    return null;
                }
            }
            report($error);

            return null;
        }
    }

    public function retry(Request $request, StoryPipeline $pipeline, ModerationAudit $audit): RedirectResponse
    {
        $input = $request->only('section', 'id');
        $validator = Validator::make($input, [
            'section' => 'required|string|in:timeline,graph,moderation',
            'id' => ['required', 'string', 'max:50', 'regex:/^\d+-\d+$/D'],
        ]);
        if ($validator->fails()) {
            return redirect()->route('admin.processing.index')->with('error', 'Mục hoặc mã yêu cầu không hợp lệ.');
        }
        try {
            $stream = $input['section'] === 'moderation' ? $audit->stream() : $pipeline->stream($input['section']);
            $stream->replay($input['id']);
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
