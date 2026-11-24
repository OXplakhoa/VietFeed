<?php

namespace App\Http\Controllers;

use App\Accounts\AccountLifecycle;
use App\Authorization\UserAuthorization;
use App\Http\Requests\ProfileUpdateRequest;
use App\Services\ReadingPassService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request, UserAuthorization $authorization): RedirectResponse
    {
        $authorization->updateProfile($request->user(), $request->validated());

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    public function readingPass(Request $request, ReadingPassService $readingPass): View
    {
        return view('profile.reading-pass', [
            'user' => $request->user(),
            'allowance' => $readingPass->allowance($request->user()),
        ]);
    }

    public function updatePreferences(Request $request): RedirectResponse
    {
        $request->validate([
            'categories' => 'array',
            'categories.*' => Rule::exists('mongodb.categories', '_id')->where(fn ($query) => $query->where('is_active', true)),
        ]);

        $request->user()->update(['favorite_category_ids' => array_values($request->categories ?? [])]);

        return back()->with('status', 'preferences-updated');
    }

    /**
     * Schedule the account for deletion (recoverable until the purge date).
     */
    public function destroy(Request $request, AccountLifecycle $lifecycle): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        $lifecycle->request($user, $user, AccountLifecycle::ORIGIN_SELF);
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/')->with('success', 'Đã lên lịch xóa tài khoản. Bạn có thể khôi phục trong '.AccountLifecycle::recoveryDays().' ngày bằng cách đăng nhập lại.');
    }
}
