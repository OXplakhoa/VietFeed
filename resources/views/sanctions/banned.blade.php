<!DOCTYPE html>
<html lang="vi">
<head>
    <script>
        (function () {
            try {
                var t = localStorage.getItem('vf-theme') || 'dark';
                document.documentElement.setAttribute('data-theme', t);
            } catch (e) {
                document.documentElement.setAttribute('data-theme', 'dark');
            }
        })();
    </script>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Tài khoản bị cấm — VietFeed</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Be+Vietnam+Pro:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    @vite(['resources/css/app.css'])
    <style>
        body { background: var(--bg); min-height: 100vh; display: flex; align-items: center; justify-content: center; }
    </style>
</head>
<body>
    <div class="text-center px-3" style="max-width:480px">
        <div class="mb-4" style="width:80px;height:80px;border-radius:24px;background:rgba(230,57,70,.12);border:1px solid rgba(230,57,70,.25);display:flex;align-items:center;justify-content:center;margin:0 auto;color:var(--accent)">
            <i class="bi bi-shield-slash" style="font-size:2.5rem"></i>
        </div>
        <h1 class="serif mb-3" style="color:var(--text);font-size:1.8rem">Tài khoản bị cấm</h1>
        <p style="color:var(--text-muted);font-size:.95rem;line-height:1.7;margin-bottom:1.5rem">
            Tài khoản của bạn đã bị cấm vĩnh viễn do vi phạm nghiêm trọng quy định cộng đồng VietFeed.
            Bạn không thể đăng nhập hoặc sử dụng bất kỳ tính năng nào.
        </p>
        @if($sanction ?? null)
        <div class="p-3 mb-4 text-start" style="background:var(--surface);border:1px solid var(--border);border-radius:12px">
            <div style="font-size:.8rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:.5rem">Lý do</div>
            <div style="font-size:.9rem;color:var(--text)">{{ $sanction->reason }}</div>
        </div>
        @endif
        @if(($sanction ?? null) && $sanction->appeal_status === 'none')
        <form action="{{ route('sanctions.appeal', $sanction) }}" method="POST" class="mb-4 text-start">
            @csrf
            <label class="vf-label" for="appeal_message">Kháng cáo</label>
            <textarea id="appeal_message" name="appeal_message" class="vf-textarea mb-2" rows="3" required minlength="10" maxlength="2000"
                      placeholder="Giải thích lý do bạn cho rằng biện pháp xử lý này không công bằng..."></textarea>
            @error('appeal_message') <div style="color:#ef4444;font-size:.8rem" class="mb-2">{{ $message }}</div> @enderror
            <button type="submit" class="btn-accent" style="font-size:.85rem">Gửi kháng cáo</button>
        </form>
        @elseif($sanction ?? null)
        <div class="p-3 mb-4 text-start" style="background:var(--surface);border:1px solid var(--border);border-radius:12px;font-size:.85rem;color:var(--text)">
            Trạng thái kháng cáo: <strong>{{ $sanction->appealStatusLabel() }}</strong>
        </div>
        @endif
        @if(session('success'))<div class="mb-3" style="color:#22c55e;font-size:.85rem">{{ session('success') }}</div>@endif
        @if(session('error'))<div class="mb-3" style="color:#ef4444;font-size:.85rem">{{ session('error') }}</div>@endif
        <form action="{{ route('logout') }}" method="POST">
            @csrf
            <button type="submit" style="background:var(--surface);color:var(--text);border:1px solid var(--border);border-radius:8px;padding:.6rem 1.4rem;font-size:.875rem;cursor:pointer">
                <i class="bi bi-box-arrow-right me-1"></i>Đăng xuất
            </button>
        </form>
    </div>
</body>
</html>
