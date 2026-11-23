<?php

namespace App\Http\Controllers\Admin;

use App\Accounts\AccountLifecycle;
use App\Authorization\Permissions;
use App\Authorization\UserAuthorization;
use App\Billing\BillingGateway;
use App\Http\Controllers\Controller;
use App\Models\ArticleUnlock;
use App\Models\Bookmark;
use App\Models\Comment;
use App\Models\User;
use App\ReadingPass\UnlockLedger;
use App\Services\ReadingPassService;
use App\Support\MongoCounts;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
            'article_unlocks' => collect(ArticleUnlock::raw(fn ($collection) => $collection->aggregate([
                ['$match' => ['user_id' => ['$in' => $pageIds], 'state' => UnlockLedger::GRANTED]],
                ['$group' => ['_id' => '$user_id', 'n' => ['$sum' => 1]]],
            ])))->pluck('n', 'id'),
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

    public function edit(Request $request, User $user, UserAuthorization $authorization)
    {
        $authorization->authorizeManagement($request->user(), $user);

        return view('admin.users.edit', compact('user'));
    }

    public function update(Request $request, User $user, UserAuthorization $authorization)
    {
        $changesAuthorization = $request->hasAny(['role', 'permissions']);
        $authorization->authorizeManagement($request->user(), $user, $changesAuthorization);

        $role = $request->input('role', $user->role);
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'lowercase', 'email', Rule::unique('mongodb.users', 'email')->ignore($user->getKey(), '_id')],
            'role' => ['sometimes', 'required', Rule::in(Permissions::ROLES)],
            'permissions' => ['sometimes', 'array', 'list', 'max:'.count(Permissions::ALL), Rule::prohibitedIf($role !== Permissions::ADMIN)],
            'permissions.*' => ['required', 'string', 'distinct', Rule::in(Permissions::ALL)],
        ], [
            'name.required' => 'Tên là bắt buộc.',
            'email.required' => 'Email là bắt buộc.',
            'email.unique' => 'Email đã được sử dụng.',
            'role.required' => 'Vui lòng chọn vai trò.',
            'role.in' => 'Vai trò không hợp lệ.',
        ]);

        $authorization->edit($request->user(), $user, ['name' => $data['name'], 'email' => $data['email']],
            $changesAuthorization ? $role : null, $data['permissions'] ?? []);

        $destination = 'admin.users.index';
        if ($user->is($request->user()) && $user->isSuperAdmin() && $role !== Permissions::SUPER_ADMIN) {
            $destination = $role === Permissions::ADMIN ? 'admin.dashboard' : 'home';
        }

        return redirect()->route($destination)->with('success', 'Đã cập nhật thông tin người dùng.');
    }

    public function destroy(Request $request, User $user, UserAuthorization $authorization, AccountLifecycle $lifecycle)
    {
        $authorization->authorizeManagement($request->user(), $user);
        $lifecycle->request($request->user(), $user, AccountLifecycle::ORIGIN_ADMIN);

        return back()->with('success', 'Đã lên lịch xóa người dùng. Tài khoản bị khóa ngay và chỉ quản trị viên có thể khôi phục trong '.AccountLifecycle::recoveryDays().' ngày.');
    }

    public function recover(Request $request, User $user, UserAuthorization $authorization, AccountLifecycle $lifecycle)
    {
        $authorization->authorizeManagement($request->user(), $user);
        $lifecycle->recover((string) $user->id, byAdmin: true);

        return back()->with('success', 'Đã khôi phục tài khoản. Gói Pro (nếu có) đã bị hủy và người dùng cần đăng ký lại.');
    }
}
