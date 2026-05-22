<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $query = User::withCount(['comments', 'bookmarks'])->latest();

        if ($request->filled('q')) {
            $q = $request->q;
            $query->where(fn ($qb) => $qb
                ->where('name', 'LIKE', "%{$q}%")
                ->orWhere('email', 'LIKE', "%{$q}%")
            );
        }

        $users = $query->paginate(20)->withQueryString();

        return view('admin.users.index', compact('users'));
    }

    public function edit(User $user)
    {
        return view('admin.users.edit', compact('user'));
    }

    public function update(Request $request, User $user)
    {
        $request->validate([
            'name'  => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . $user->id,
            'role'  => 'required|in:user,admin',
        ], [
            'name.required'   => 'Tên là bắt buộc.',
            'email.required'  => 'Email là bắt buộc.',
            'email.unique'    => 'Email đã được sử dụng.',
            'role.required'   => 'Vui lòng chọn vai trò.',
            'role.in'         => 'Vai trò không hợp lệ.',
        ]);

        $user->update($request->only(['name', 'email', 'role']));

        return redirect()->route('admin.users.index')
            ->with('success', 'Đã cập nhật thông tin người dùng.');
    }

    public function destroy(User $user)
    {
        if ($user->is(auth()->user())) {
            return back()->with('error', 'Không thể xóa tài khoản đang đăng nhập.');
        }

        $user->delete();

        return back()->with('success', 'Đã xóa người dùng.');
    }
}
