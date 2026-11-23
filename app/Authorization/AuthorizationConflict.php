<?php

namespace App\Authorization;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class AuthorizationConflict extends HttpException
{
    public function __construct(string $message = 'Tài khoản vừa thay đổi. Vui lòng thử lại.')
    {
        parent::__construct(409, $message);
    }

    public function render(Request $request): ?RedirectResponse
    {
        if ($request->expectsJson()) {
            return null;
        }

        return back()->withInput($request->only(['name', 'email', 'role', 'permissions', 'permissions_form']))
            ->with('error', $this->getMessage());
    }
}
