<?php

namespace App\Http\Controllers\Admin;

use App\Authorization\AuthorizationAudit;
use App\Authorization\Permissions;
use App\Models\User;
use Illuminate\View\View;

final class AuthorizationAuditController
{
    public function __invoke(AuthorizationAudit $audit): View
    {
        $events = $audit->recent();
        $people = [];
        if ($events) {
            $ids = array_unique([...array_column($events, 'actor_id'), ...array_column($events, 'target_id')]);
            $users = User::whereIn('_id', $ids)->get(['_id', 'name'])->keyBy('id');
            foreach ($ids as $id) {
                $user = $users->get($id);
                $people[$id] = $user ? ($user->name ?: $id) : (strlen($id) === 24 && ctype_xdigit($id) ? 'Người dùng đã xóa' : $id);
            }
            foreach ($events as &$event) {
                $event['changes'] = $this->changes($event);
            }
            unset($event);
        }

        return view('admin.authorization.audit', compact('events', 'people'));
    }

    private function changes(array $event): array
    {
        $before = (array) json_decode($event['before_state'], true);
        $after = json_decode($event['after_state'], true);
        if ($after === null) {
            return ['Đã xóa tài khoản'];
        }

        $changes = [];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $field) {
            $old = $before[$field] ?? null;
            $new = $after[$field] ?? null;
            if ($old === $new) {
                continue;
            }
            $label = match ($field) {
                'role' => 'Vai trò',
                'permissions' => 'Quyền',
                'markers' => 'Dấu xử lý',
                'super_admin_ids' => 'Thành viên Super Admin',
                'bootstrap_candidate_id' => 'Ứng viên khởi tạo',
                default => $field,
            };
            $changes[] = $label.': '.$this->displayValue($field, $old).' → '.$this->displayValue($field, $new);
        }

        return $changes;
    }

    private function displayValue(string $field, mixed $value): string
    {
        if ($field === 'role') {
            return match ($value) {
                Permissions::USER => 'Người dùng',
                Permissions::ADMIN => 'Quản trị viên',
                Permissions::SUPER_ADMIN => 'Super Admin',
                default => is_string($value) ? $value : '—',
            };
        }
        if (is_array($value)) {
            $value = implode(', ', array_filter($value, 'is_scalar'));
        }

        return match (true) {
            is_bool($value) => $value ? 'Có' : 'Không',
            is_scalar($value) && $value !== '' => (string) $value,
            default => '—',
        };
    }
}
