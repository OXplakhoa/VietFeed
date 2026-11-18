<?php

namespace App\Moderation;

use App\Models\Article;
use App\Models\Comment;
use App\Models\Report;
use App\Models\Sanction;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use MongoDB\BSON\Regex;

/**
 * Read side of moderation. Gate 9b: reports, sanctions and cases on MongoDB (Gate 9.5: comments too),
 * review queue/counters on Redis. Cross-store rule (Gate 7 C1): resolve ids in one query per
 * store and join in PHP, batched per page. Views receive Mongo models with relations pre-set
 * (no lazy loading) or plain row objects.
 */
class ModerationQueries
{
    private const TYPE_MAP = ['root' => 'array', 'document' => 'array', 'array' => 'array'];

    public function __construct(
        private ModerationQueue $queue,
        private ModerationAudit $audit,
        private ModerationCases $cases,
        private SanctionSnapshot $snapshot,
    ) {}

    /** Inbox: one row per reported comment, newest activity first (9a rule 5). */
    public function inbox(?string $status, ?string $reason, ?string $search, int $perPage = 20): LengthAwarePaginatorContract
    {
        $match = [];
        if ($status) {
            $match['status'] = $status;
        }
        if ($reason) {
            $match['reason'] = $reason;
        }
        if ($search !== null && $search !== '') {
            $regex = new Regex(preg_quote($search, '/'), 'i');
            $or = [['comment_body' => $regex], ['description' => $regex]];
            if ($reporterIds = $this->userIdsByName($search)) {
                $or[] = ['reporter_id' => ['$in' => $reporterIds]];
            }
            $match['$or'] = $or;
        }

        $page = Paginator::resolveCurrentPage();
        $result = $this->collection('reports')->aggregate([
            ['$match' => (object) $match],
            ['$group' => ['_id' => '$comment_id', 'latest_at' => ['$max' => '$created_at'], 'latest_report_id' => ['$max' => '$_id']]],
            ['$sort' => ['latest_at' => -1, 'latest_report_id' => -1]],
            ['$facet' => [
                'total' => [['$count' => 'n']],
                'rows' => [['$skip' => ($page - 1) * $perPage], ['$limit' => $perPage]],
            ]],
        ])->toArray()[0];
        $rows = $result['rows'];

        return $this->paginate($this->rows(array_column($rows, 'latest_report_id')), $result['total'][0]['n'] ?? 0, $perPage, $page);
    }

    /**
     * Pending cases in priority order from the Redis queue; MongoDB answers (same order) when the
     * projection is missing, evicted or unreachable, and the queue is rebuilt lazily.
     *
     * @return array{cases: LengthAwarePaginatorContract, source: 'redis'|'mongodb'}
     */
    public function priorityQueue(int $perPage = 20): array
    {
        $page = Paginator::resolveCurrentPage();
        $slice = $this->queue->page($page, $perPage);
        $source = 'redis';
        if ($slice !== null) {
            $cases = $this->collection('moderation_cases')->find(['_id' => ['$in' => $slice['ids']]])->toArray();
            $byId = collect($cases)->keyBy(fn ($c) => (string) $c['_id']);
            $ordered = [];
            foreach ($slice['ids'] as $id) {
                $case = $byId[$id] ?? null;
                if ($case === null || $case['status'] !== 'pending' || $case['pending_count'] < 1) {
                    // Stale member: drop it at the version we just read; the projection self-heals.
                    $this->queue->remove($id, $case === null ? PHP_INT_MAX : (int) $case['version']);

                    continue;
                }
                $ordered[] = $case;
            }
            $total = $slice['total'];
        } else {
            $source = 'mongodb';
            $filter = ['status' => 'pending', 'pending_count' => ['$gt' => 0]];
            $ordered = $this->collection('moderation_cases')->find($filter, [
                'sort' => ['pending_count' => -1, 'severity' => -1, 'round_started_at' => 1, '_id' => 1],
                'skip' => ($page - 1) * $perPage, 'limit' => $perPage,
            ])->toArray();
            $total = $this->collection('moderation_cases')->countDocuments($filter);
            $this->queue->rebuildQuietly();
        }

        // Latest pending report of each case, in one query.
        $latest = $this->collection('reports')->aggregate([
            ['$match' => ['case_id' => ['$in' => array_map(fn ($c) => (string) $c['_id'], $ordered)], 'status' => 'pending']],
            ['$group' => ['_id' => '$case_id', 'latest_report_id' => ['$max' => '$_id']]],
        ])->toArray();
        $latestByCase = collect($latest)->pluck('latest_report_id', '_id');
        $rows = $this->rows(array_values(array_filter(array_map(fn ($c) => $latestByCase[(string) $c['_id']] ?? null, $ordered))));
        $rows = $rows->map(function ($row) use ($ordered) {
            $case = collect($ordered)->firstWhere('comment_id', $row->comment_id);
            $row->priority = ['reporters' => (int) $case['pending_count'], 'severity' => (int) $case['severity']];

            return $row;
        });

        return ['cases' => $this->paginate($rows, $total, $perPage, $page), 'source' => $source];
    }

    public function pendingCaseCount(): int
    {
        return $this->queue->pendingCases();
    }

    public function findReport(string $id): ?Report
    {
        return Str::isUuid($id) ? Report::find($id) : null;
    }

    public function findSanction(string $id): ?Sanction
    {
        return Str::isUuid($id) ? Sanction::find($id) : null;
    }

    /** Everything the case page needs; tolerates a deleted comment, article or author. */
    public function caseFor(Report $report): array
    {
        if ($report->case_id) {
            // Converge an interrupted decision before showing the case (idempotent).
            $this->cases->settle((string) $report->case_id);
            $report = Report::find($report->id);
        }
        $comment = Comment::find($report->comment_id);
        $reports = Report::where('comment_id', $report->comment_id)->orderByDesc('created_at')->orderByDesc('_id')->get();
        $sanctionIds = $reports->pluck('sanction_id')->filter()->unique()->values()->all();
        $sanctions = $sanctionIds ? Sanction::whereIn('_id', $sanctionIds)->get()->keyBy(fn (Sanction $s) => (string) $s->id) : collect();
        $history = $report->comment_user_id
            ? Sanction::where('user_id', (string) $report->comment_user_id)->orderByDesc('created_at')->orderByDesc('_id')->get()
            : collect();
        $users = $this->usersById(array_merge(
            $reports->pluck('reporter_id')->all(), $reports->pluck('admin_id')->all(), [$report->comment_user_id],
        ));
        foreach ($reports as $r) {
            $r->setRelation('reporter', $users[$r->reporter_id] ?? null);
            $r->setRelation('admin', $users[$r->admin_id] ?? null);
            $r->setRelation('sanction', $sanctions[$r->sanction_id] ?? null);
        }
        $report = $reports->firstWhere('id', $report->id) ?? $report;
        // Sanctions issued for this case whose claim was lost (request stalled past the repair lease).
        $orphanIds = $report->case_id ? ($this->cases->find((string) $report->case_id)['orphaned_sanction_ids'] ?? []) : [];
        $orphaned = $orphanIds ? Sanction::whereIn('_id', array_values($orphanIds))->get() : collect();

        return [
            'report' => $report,
            'reports' => $reports,
            'pendingCount' => $reports->where('status', 'pending')->count(),
            'pendingReport' => $reports->firstWhere('status', 'pending'),
            'comment' => $comment,
            'commentState' => $this->commentState($comment),
            'author' => $report->comment_user_id ? ($users[$report->comment_user_id] ?? null) : null,
            'history' => $history,
            'article' => $report->comment_article_id ? Article::find($report->comment_article_id) : null,
            'resolution' => $reports->first(fn (Report $r) => $r->sanction_id)?->sanction,
            'orphaned' => $orphaned,
            'timeline' => $report->case_id ? $this->timeline((string) $report->case_id) : ['state' => 'pending', 'events' => [], 'expected' => 0],
        ];
    }

    /**
     * Cassandra decision history of one audit partition with actor names.
     *
     * @return array{state: string, events: list<array>, expected: int}
     */
    public function timeline(string $caseId): array
    {
        $timeline = $this->audit->timeline($caseId);
        $names = $this->usersById(array_column($timeline['events'], 'actor_id'));
        foreach ($timeline['events'] as &$event) {
            $event['actor_name'] = isset($event['actor_id']) ? ($names[$event['actor_id']] ?? null)?->name : null;
        }
        unset($event);

        return $timeline;
    }

    public function sanctions(?string $type, ?string $status, ?string $search, int $perPage = 20): LengthAwarePaginatorContract
    {
        $query = Sanction::query()->orderByDesc('created_at')->orderByDesc('_id');

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
            $regex = new Regex(preg_quote($search, '/'), 'i');
            $userIds = $this->userIdsByName($search);
            $query->where(function ($w) use ($regex, $userIds) {
                $w->where('reason', 'regex', $regex);
                if ($userIds) {
                    $w->orWhereIn('user_id', $userIds);
                }
            });
        }

        $page = $query->paginate($perPage);
        $users = $this->usersById($page->getCollection()->pluck('user_id')->merge($page->getCollection()->pluck('admin_id'))->all());
        foreach ($page->getCollection() as $sanction) {
            $sanction->setRelation('user', $users[$sanction->user_id] ?? null);
            $sanction->setRelation('admin', $users[$sanction->admin_id] ?? null);
        }

        return $page;
    }

    public function sanctionDetail(Sanction $sanction): array
    {
        $report = $sanction->report_id ? Report::find($sanction->report_id) : null;
        $users = $this->usersById([$sanction->user_id, $sanction->admin_id, $sanction->revoked_by, $report?->reporter_id, $report?->comment_user_id]);
        $sanction->setRelation('user', $users[$sanction->user_id] ?? null);
        $sanction->setRelation('admin', $users[$sanction->admin_id] ?? null);
        $sanction->setRelation('revoker', $users[$sanction->revoked_by] ?? null);
        $sanction->setRelation('report', $report);
        $report?->setRelation('reporter', $users[$report->reporter_id] ?? null);
        $comment = $report ? Comment::find($report->comment_id) : null;

        return [
            'sanction' => $sanction,
            'report' => $report,
            'comment' => $comment,
            'commentState' => $this->commentState($comment),
            'article' => $report?->comment_article_id ? Article::find($report->comment_article_id) : null,
            'author' => $report?->comment_user_id ? ($users[$report->comment_user_id] ?? null) : null,
            'hidesComment' => Comment::where('hidden_by_sanction_id', (string) $sanction->id)->exists(),
            'timeline' => $this->timeline((string) ($sanction->audit_case_id ?? $sanction->id)),
        ];
    }

    public function pendingAppealsCount(): int
    {
        return $this->queue->pendingAppeals();
    }

    /**
     * The one query behind every `User` sanction helper, memoised per HTTP request.
     *
     * @return Collection<int, Sanction> currently active sanctions, newest first
     */
    public function activeSanctionsFor(string $userId): Collection
    {
        return $this->snapshot->activeFor($userId);
    }

    /** @return Collection<int, Sanction> */
    public function sanctionHistoryFor(string $userId): Collection
    {
        return Sanction::where('user_id', $userId)->orderByDesc('created_at')->orderByDesc('_id')->get();
    }

    /** Build inbox rows for the given latest-report ids (batched: one query per store). */
    private function rows(array $latestReportIds): Collection
    {
        $latest = $latestReportIds ? Report::whereIn('_id', $latestReportIds)->get()->keyBy(fn (Report $r) => (string) $r->id) : collect();
        $commentIds = $latest->pluck('comment_id')->unique()->values()->all();
        $totals = $commentIds ? collect($this->collection('reports')->aggregate([
            ['$match' => ['comment_id' => ['$in' => $commentIds]]],
            ['$group' => ['_id' => '$comment_id', 'reports_count' => ['$sum' => 1], 'pending_count' => ['$sum' => ['$cond' => [['$eq' => ['$status', 'pending']], 1, 0]]]]],
        ])->toArray())->keyBy('_id') : collect();
        $live = $commentIds ? Comment::whereIn('_id', array_map('strval', $commentIds))->get(['_id', 'is_hidden'])->keyBy(fn (Comment $c) => (string) $c->id) : collect();
        $users = $this->usersById($latest->pluck('comment_user_id')->merge($latest->pluck('reporter_id'))->all());
        $articles = $this->articlesById($latest->pluck('comment_article_id')->all());

        return collect($latestReportIds)->map(function ($id) use ($latest, $totals, $live, $users, $articles) {
            $report = $latest[(string) $id];
            $total = $totals[$report->comment_id];

            return (object) [
                'comment_id' => (string) $report->comment_id,
                'latest_report' => $report,
                'reports_count' => (int) $total['reports_count'],
                'pending_count' => (int) $total['pending_count'],
                'status' => (int) $total['pending_count'] > 0 ? 'pending' : $report->status,
                'body' => $report->comment_body,
                'author' => $users[$report->comment_user_id] ?? null,
                'reporter' => $users[$report->reporter_id] ?? null,
                'article' => $articles[$report->comment_article_id] ?? null,
                'comment_state' => $this->commentState($live[$report->comment_id] ?? null),
                'latest_at' => $report->created_at,
            ];
        })->values();
    }

    private function paginate(Collection $items, int $total, int $perPage, int $page): LengthAwarePaginatorContract
    {
        return new LengthAwarePaginator($items, $total, $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(), 'pageName' => 'page',
        ]);
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

    /** @return list<string> */
    private function userIdsByName(string $search): array
    {
        return User::where('name', 'regex', new Regex(preg_quote($search, '/'), 'i'))
            ->limit(500)->get(['_id'])->map(fn (User $u) => (string) $u->id)->all();
    }

    /** @return Collection<string, User> */
    private function usersById(array $ids): Collection
    {
        $ids = array_values(array_unique(array_filter(array_map(fn ($id) => $id === null ? null : (string) $id, $ids))));

        return $ids ? User::whereIn('_id', $ids)->get()->keyBy(fn (User $u) => (string) $u->id) : collect();
    }

    /** @return Collection<string, Article> */
    private function articlesById(array $ids): Collection
    {
        $ids = array_values(array_unique(array_filter($ids)));

        return $ids ? Article::whereIn('_id', $ids)->get()->keyBy(fn (Article $a) => (string) $a->id) : collect();
    }

    private function collection(string $name): \MongoDB\Collection
    {
        return DB::connection('mongodb')->getMongoDB()->selectCollection($name, ['typeMap' => self::TYPE_MAP]);
    }
}
