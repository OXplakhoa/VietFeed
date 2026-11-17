<?php

namespace App\Moderation;

use App\Models\Article;
use App\Models\Comment;
use App\Models\Report;
use App\Models\Sanction;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use MongoDB\BSON\Regex;

/**
 * Read side of moderation. Cross-store rule (Gate 7 C1): never whereHas/withCount across SQL and Mongo;
 * resolve ids in one query per store and join in PHP, batched per page.
 */
class ModerationQueries
{
    /** Inbox: one row per reported comment, newest activity first. */
    public function inbox(?string $status, ?string $reason, ?string $search, int $perPage = 20): LengthAwarePaginator
    {
        $base = Report::query();
        if ($status) {
            $base->where('status', $status);
        }
        if ($reason) {
            $base->where('reason', $reason);
        }
        if ($search !== null && $search !== '') {
            $like = $this->like($search);
            $reporterIds = $this->userIdsByName($search);
            $base->where(function ($w) use ($like, $reporterIds) {
                $w->whereRaw("comment_body like ? escape '!'", [$like])
                    ->orWhereRaw("description like ? escape '!'", [$like]);
                if ($reporterIds) {
                    $w->orWhereIn('reporter_id', $reporterIds);
                }
            });
        }

        $page = $base->selectRaw('comment_id, max(id) as latest_report_id, max(created_at) as latest_at')
            ->groupBy('comment_id')
            ->orderByDesc('latest_at')->orderByDesc('latest_report_id')
            ->paginate($perPage);

        $rows = $page->getCollection();
        $commentIds = $rows->pluck('comment_id')->all();
        $totals = Report::selectRaw('comment_id, count(*) as reports_count, sum(case when status = ? then 1 else 0 end) as pending_count', ['pending'])
            ->whereIn('comment_id', $commentIds)->groupBy('comment_id')->get()->keyBy('comment_id');
        $latest = Report::whereIn('id', $rows->pluck('latest_report_id')->all())->get()->keyBy('id');
        $live = Comment::whereIn('id', $commentIds)->get(['id', 'is_hidden'])->keyBy('id');
        $users = $this->usersById($latest->pluck('comment_user_id')->merge($latest->pluck('reporter_id'))->all());
        $articles = $this->articlesById($latest->pluck('comment_article_id')->all());

        $page->setCollection($rows->map(function ($row) use ($totals, $latest, $live, $users, $articles) {
            $report = $latest[$row->latest_report_id];
            $total = $totals[$row->comment_id];

            return (object) [
                'comment_id' => (int) $row->comment_id,
                'latest_report' => $report,
                'reports_count' => (int) $total->reports_count,
                'pending_count' => (int) $total->pending_count,
                'status' => (int) $total->pending_count > 0 ? 'pending' : $report->status,
                'body' => $report->comment_body,
                'author' => $users[$report->comment_user_id] ?? null,
                'reporter' => $users[$report->reporter_id] ?? null,
                'article' => $articles[$report->comment_article_id] ?? null,
                'comment_state' => $this->commentState($live[$row->comment_id] ?? null),
                'latest_at' => $row->latest_at,
            ];
        }));

        return $page;
    }

    public function pendingReportCount(): int
    {
        return Report::pending()->count();
    }

    /** Everything the case page needs; tolerates a deleted comment, article or author. */
    public function caseFor(Report $report): array
    {
        $comment = Comment::find($report->comment_id);
        $reports = Report::where('comment_id', $report->comment_id)->with(['reporter', 'admin', 'sanction'])->latest()->latest('id')->get();
        $author = $report->comment_user_id ? User::find($report->comment_user_id) : null;
        $history = $report->comment_user_id
            ? Sanction::where('user_id', $report->comment_user_id)->with('admin')->latest()->latest('id')->get()
            : collect();

        return [
            'report' => $report,
            'reports' => $reports,
            'pendingCount' => $reports->where('status', 'pending')->count(),
            'pendingReport' => $reports->firstWhere('status', 'pending'),
            'comment' => $comment,
            'commentState' => $this->commentState($comment),
            'author' => $author,
            'history' => $history,
            'article' => $report->comment_article_id ? Article::find($report->comment_article_id) : null,
            'resolution' => $reports->first(fn (Report $r) => $r->sanction_id)?->sanction,
        ];
    }

    public function sanctions(?string $type, ?string $status, ?string $search, int $perPage = 20): LengthAwarePaginator
    {
        $query = Sanction::with(['user', 'admin'])->latest()->latest('id');

        if ($type) {
            $query->where('type', $type);
        }
        match ($status) {
            'active' => $query->active()->whereNull('revoked_at'),
            'expired' => $query->expired(),
            'revoked' => $query->revoked(),
            'pending_appeal' => $query->pendingAppeals(),
            default => null,
        };
        if ($search !== null && $search !== '') {
            $like = $this->like($search);
            $userIds = $this->userIdsByName($search);
            $query->where(function ($w) use ($like, $userIds) {
                $w->whereRaw("reason like ? escape '!'", [$like]);
                if ($userIds) {
                    $w->orWhereIn('user_id', $userIds);
                }
            });
        }

        return $query->paginate($perPage);
    }

    public function sanctionDetail(Sanction $sanction): array
    {
        $sanction->load(['user', 'admin', 'revoker', 'report.reporter']);
        $report = $sanction->report;
        $comment = $report ? Comment::find($report->comment_id) : null;
        $hidden = Comment::where('hidden_by_sanction_id', $sanction->id)->exists();

        return [
            'sanction' => $sanction,
            'report' => $report,
            'comment' => $comment,
            'commentState' => $this->commentState($comment),
            'article' => $report?->comment_article_id ? Article::find($report->comment_article_id) : null,
            'author' => $report?->comment_user_id ? User::find($report->comment_user_id) : null,
            'hidesComment' => $hidden,
        ];
    }

    public function pendingAppealsCount(): int
    {
        return Sanction::pendingAppeals()->count();
    }

    /** @return 'visible'|'hidden'|'deleted' */
    private function commentState(?Comment $comment): string
    {
        return match (true) {
            $comment === null => 'deleted',
            (bool) $comment->is_hidden => 'hidden',
            default => 'visible',
        };
    }

    private function like(string $value): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value).'%';
    }

    /** @return list<string> */
    private function userIdsByName(string $search): array
    {
        return User::where('name', 'regex', new Regex(preg_quote($search, '/'), 'i'))
            ->limit(500)->get(['_id'])->map(fn (User $u) => (string) $u->id)->all();
    }

    /** @return Collection<string, User> */
    private function usersById(array $ids): Collection
    {
        $ids = array_values(array_unique(array_filter($ids)));

        return $ids ? User::whereIn('_id', $ids)->get()->keyBy(fn (User $u) => (string) $u->id) : collect();
    }

    /** @return Collection<string, Article> */
    private function articlesById(array $ids): Collection
    {
        $ids = array_values(array_unique(array_filter($ids)));

        return $ids ? Article::whereIn('_id', $ids)->get()->keyBy(fn (Article $a) => (string) $a->id) : collect();
    }
}
