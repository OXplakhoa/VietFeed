<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Story;
use App\StoryLens\Brief\BriefEvidence;
use App\StoryLens\Brief\Briefs;
use App\StoryLens\Brief\BriefStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class StoryBriefController extends Controller
{
    public function index(Briefs $briefs): View
    {
        $recent = Story::query()->select(['_id', 'title', 'version', 'article_count', 'article_ids', 'brief.state', 'brief.story_version', 'brief.published_id', 'brief.lease_until'])
            ->latest()->orderByDesc('_id')->limit(Briefs::RECENT_LIMIT)->get();
        $presented = $briefs->presentStories($recent->all());
        $stories = $recent->map(fn (Story $story) => [
            'id' => $story->id,
            'title' => $story->title,
            'version' => $story->version ?? 0,
            'articles' => $story->article_count ?? count($story->article_ids ?? []),
            'state' => $presented[$story->id]['state'],
            'published' => match ($presented[$story->id]['kind']) {
                'ai' => 'AI',
                'extractive' => 'Trích từ nguồn',
                default => '—',
            },
        ]);

        return view('admin.stories.index', compact('stories'));
    }

    public function show(string $story, BriefStore $store): View
    {
        $story = $store->settle($story);
        abort_if($story === null, 404);
        $evidence = BriefEvidence::read($story);
        $state = Briefs::state($story);
        $brief = in_array($state, ['none', 'unavailable'], true) ? [] : $story->brief;
        $expired = $state === 'failed' && $brief['state'] === 'generating';
        $candidate = $store->output($story, $brief['candidate_id'] ?? null, 'candidate');
        $points = $candidate ? Briefs::cite($candidate['points'], $evidence) : null;
        $history = $store->history($story, Briefs::RECENT_LIMIT);

        return view('admin.stories.show', compact('story', 'evidence', 'state', 'brief', 'candidate', 'points', 'history', 'expired'));
    }

    public function accept(Request $request, string $story, BriefStore $store): RedirectResponse
    {
        return $this->review($request, $story, $store, accept: true);
    }

    public function reject(Request $request, string $story, BriefStore $store): RedirectResponse
    {
        return $this->review($request, $story, $store, accept: false);
    }

    public function retry(string $story, Briefs $briefs): RedirectResponse
    {
        $story = Story::findOrFail($story);
        $queued = $briefs->queue($story->id, (int) ($story->version ?? 0));

        return redirect()->route('admin.stories.show', $story->id)->with(
            $queued ? 'success' : 'error',
            $queued ? 'Đã đưa yêu cầu tạo tóm tắt vào hàng đợi.' : 'Chưa thể đưa yêu cầu vào hàng đợi. Vui lòng tải lại và thử lại.',
        );
    }

    private function review(Request $request, string $story, BriefStore $store, bool $accept): RedirectResponse
    {
        Story::findOrFail($story);
        $input = $request->validate(['candidate_id' => 'required|uuid', 'story_version' => 'required|integer|min:0', 'note' => 'nullable|string|max:500']);
        $reviewed = $store->review($story, (int) $input['story_version'], $input['candidate_id'],
            (string) $request->user()->getAuthIdentifier(), $accept, $input['note'] ?? null);

        return redirect()->route('admin.stories.show', $story)->with(
            $reviewed ? 'success' : 'error',
            $reviewed ? ($accept ? 'Đã duyệt tóm tắt AI.' : 'Đã từ chối tóm tắt AI.')
                : 'Bản nháp không còn là bản hiện tại của Story. Vui lòng tải lại trang.',
        );
    }
}
