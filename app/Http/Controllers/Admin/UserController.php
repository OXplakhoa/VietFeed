<?php

namespace App\Http\Controllers\Admin;

use App\Billing\BillingGateway;
use App\Http\Controllers\Controller;
use App\Models\ArticleUnlock;
use App\Models\Bookmark;
use App\Models\Comment;
use App\Models\User;
use App\Services\ReadingPassService;
use App\Support\MongoCounts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class UserController extends Controller
{
    public function index(Request $request, ReadingPassService $readingPass, BillingGateway $billing)
    {
        $query = User::query()->latest();

        if ($request->filled('q')) {
            $q = $request->q;
            $query->where(fn ($qb) => $qb
                ->where('name', 'LIKE', "%{$q}%")
                ->orWhere('email', 'LIKE', "%{$q}%")
            );
        }

        if ($request->string('plan')->toString() === 'pro') {
            $query->whereIn('_id', $billing->proUserIds());
        }

        $users = $query->paginate(20)->withQueryString();
        $billing->primeEntitlements($users->getCollection());
        $pageIds = array_map('strval', $users->getCollection()->modelKeys());
        $counts = [
            'comments' => MongoCounts::group(Comment::class, 'user_id', $pageIds),
            'bookmarks' => MongoCounts::group(Bookmark::class, 'user_id', $pageIds),
            'article_unlocks' => ArticleUnlock::whereIn('user_id', $pageIds)->select('user_id')
                ->selectRaw('COUNT(*) as n')->groupBy('user_id')->pluck('n', 'user_id'),
        ];
        foreach ($counts as $name => $byUser) {
            foreach ($users as $user) {
                $user->{$name.'_count'} = (int) ($byUser[$user->id] ?? 0);
            }
        }
        $users->getCollection()->each(function (User $user) use ($readingPass) {
            $user->reading_pass_allowance = $readingPass->allowance($user);
        });

        return view('admin.users.index', compact('users'));
    }

    public function edit(User $user)
    {
        return view('admin.users.edit', compact('user'));
    }

    public function update(Request $request, User $user)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:mongodb.users,email,'.$user->getKey().',_id',
            'role' => 'required|in:user,admin',
        ], [
            'name.required' => 'Tên là bắt buộc.',
            'email.required' => 'Email là bắt buộc.',
            'email.unique' => 'Email đã được sử dụng.',
            'role.required' => 'Vui lòng chọn vai trò.',
            'role.in' => 'Vai trò không hợp lệ.',
        ]);

        $user->update($request->only(['name', 'email', 'role']));

        return redirect()->route('admin.users.index')
            ->with('success', 'Đã cập nhật thông tin người dùng.');
    }

    public function destroy(User $user)
    {
        if ($user->is(Auth::user())) {
            return back()->with('error', 'Không thể xóa tài khoản đang đăng nhập.');
        }

        $user->delete();

        return back()->with('success', 'Đã xóa người dùng.');
    }
}
