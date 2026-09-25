<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreSourceRequest;
use App\Http\Requests\Admin\UpdateSourceRequest;
use App\Jobs\FetchSourceFeedJob;
use App\Models\Category;
use App\Models\Source;
use App\Models\SourceFetchLog;
use App\Services\Rss\FeedIngestionService;
use App\Support\MongoCounts;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Schema;

class SourceController extends Controller
{
    public function index(Request $request)
    {
        // Gate 3: articles live on Mongo — withCount SQL subquery can't join; attach
        // native counts and sort/paginate in PHP (identical numbers and order).
        $query = Source::with('category');

        if ($request->filled('q')) {
            $query->where('name', 'LIKE', "%{$request->q}%");
        }

        $articlesBySource = MongoCounts::byField('source_id');

        $sort = $request->input('sort', 'name');
        $dir = $request->input('dir', 'asc') === 'desc';
        $sources = $query->get()
            ->each(fn ($source) => $source->articles_count = (int) ($articlesBySource[$source->id] ?? 0))
            ->sortBy(fn ($source) => match ($sort) {
                'articles_count' => $source->articles_count,
                'last_fetched_at' => optional($source->last_fetched_at)->timestamp ?? 0,
                default => $source->name,
            }, SORT_REGULAR, $dir)->values();

        $page = LengthAwarePaginator::resolveCurrentPage();
        $perPage = 20;
        $sources = new LengthAwarePaginator(
            $sources->forPage($page, $perPage),
            $sources->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );
        $sources->withQueryString();

        return view('admin.sources.index', compact('sources'));
    }

    public function health(Request $request)
    {
        $hasFetchLogsTable = Schema::hasTable('source_fetch_logs');

        $query = Source::with('category');

        if ($hasFetchLogsTable) {
            $query->with(['fetchLogs' => fn ($q) => $q->limit(5)]);
        }

        if ($request->filled('q')) {
            $query->where('name', 'LIKE', "%{$request->q}%");
        }

        if ($request->filled('category')) {
            $query->where('category_id', $request->integer('category'));
        }

        $sources = $query->get();

        $articlesBySource = MongoCounts::byField('source_id');
        $sources->each(fn ($source) => $source->articles_count = (int) ($articlesBySource[$source->id] ?? 0));

        if ($request->filled('status')) {
            $sources = $sources->filter(fn (Source $source) => $source->health_status === $request->string('status')->toString())->values();
        }

        $sort = $request->input('sort', 'name');
        $dir = $request->input('dir', 'asc') === 'desc';
        $sources = $sources->sortBy(function (Source $source) use ($sort) {
            return match ($sort) {
                'health_status' => $source->health_status,
                'last_successful_fetch_at' => optional($source->last_successful_fetch_at)->timestamp ?? 0,
                'consecutive_failures' => $source->consecutive_failures,
                default => $source->name,
            };
        }, SORT_REGULAR, $dir)->values();

        $page = LengthAwarePaginator::resolveCurrentPage();
        $perPage = 15;
        $paginated = new LengthAwarePaginator(
            $sources->forPage($page, $perPage),
            $sources->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        $summary = collect(['healthy', 'warning', 'failed', 'stale', 'critical', 'disabled', 'never_fetched'])
            ->mapWithKeys(fn ($status) => [$status => $sources->where('health_status', $status)->count()]);

        $recentLogs = $hasFetchLogsTable
            ? SourceFetchLog::with('source')->latest()->take(20)->get()
            : collect();
        $categories = Category::orderBy('name')->get();

        return view('admin.sources.health', [
            'sources' => $paginated,
            'summary' => $summary,
            'recentLogs' => $recentLogs,
            'categories' => $categories,
            'hasFetchLogsTable' => $hasFetchLogsTable,
        ]);
    }

    public function test(Source $source, FeedIngestionService $service)
    {
        if (! Schema::hasTable('source_fetch_logs')) {
            return back()->with('error', 'Thiếu bảng source_fetch_logs. Hãy chạy php artisan migrate trước.');
        }

        $log = $source->fetchLogs()->create([
            'type' => 'rss_test',
            'status' => 'running',
            'started_at' => now(),
        ]);

        $result = $service->testSource($source, $log);

        return back()->with('test_result', [
            'source' => $source->name,
            'status' => $result->status,
            'http_status' => $result->httpStatus,
            'items_found' => $result->itemsFound,
            'valid_items' => $result->validItems,
            'invalid_items' => $result->invalidItems,
            'missing_image_count' => $result->missingImageCount,
            'date_parse_error_count' => $result->dateParseErrorCount,
            'duration_ms' => $result->durationMs,
            'sample_items' => $result->sampleItems,
            'error_type' => $result->errorType,
            'error_message' => $result->errorMessage,
        ])->with('success', 'Đã chạy kiểm tra RSS cho nguồn tin.');
    }

    public function retry(Source $source)
    {
        if (! Schema::hasTable('source_fetch_logs')) {
            return back()->with('error', 'Thiếu bảng source_fetch_logs. Hãy chạy php artisan migrate trước.');
        }

        $log = $source->fetchLogs()->create([
            'type' => 'manual_retry',
            'status' => 'queued',
        ]);

        FetchSourceFeedJob::dispatch($source->id, $log->id);

        return back()->with('success', 'Đã đưa yêu cầu retry vào hàng đợi.');
    }

    public function toggleActive(Source $source)
    {
        $source->update(['is_active' => ! $source->is_active]);

        return back()->with('success', $source->is_active ? 'Đã bật nguồn tin.' : 'Đã tắt nguồn tin.');
    }

    public function create()
    {
        $categories = Category::orderBy('name')->get();

        return view('admin.sources.create', compact('categories'));
    }

    public function store(StoreSourceRequest $request)
    {
        Source::create($request->validated());

        return redirect()->route('admin.sources.index')
            ->with('success', 'Đã thêm nguồn tin mới.');
    }

    public function show(Source $source)
    {
        $source->load('category');
        $articles = $source->articles()
            ->withCount('bookmarks')
            ->latest('published_at')
            ->paginate(15);

        $hasFetchLogsTable = Schema::hasTable('source_fetch_logs');
        $recentLogs = $hasFetchLogsTable
            ? $source->fetchLogs()->latest()->take(15)->get()
            : collect();

        return view('admin.sources.show', compact('source', 'articles', 'recentLogs', 'hasFetchLogsTable'));
    }

    public function edit(Source $source)
    {
        $categories = Category::orderBy('name')->get();

        return view('admin.sources.edit', compact('source', 'categories'));
    }

    public function update(UpdateSourceRequest $request, Source $source)
    {
        $source->update($request->validated());

        return redirect()->route('admin.sources.index')
            ->with('success', 'Đã cập nhật nguồn tin.');
    }

    public function destroy(Source $source)
    {
        $source->delete();

        return redirect()->route('admin.sources.index')
            ->with('success', 'Đã xóa nguồn tin.');
    }
}
