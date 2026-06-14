@php
    $sanctions = $user->sanctions()->latest()->get();
    $activeSanction = $user->activeSanction();
@endphp

@if($activeSanction)
<div class="mb-4 p-4 position-relative overflow-hidden" style="background:linear-gradient(135deg, rgba(230,57,70,.12), var(--surface));border:1px solid rgba(230,57,70,.28);border-radius:14px">
    <div class="d-flex align-items-start gap-3 position-relative" style="z-index:1">
        <div style="width:44px;height:44px;border-radius:14px;background:rgba(230,57,70,.12);border:1px solid rgba(230,57,70,.25);display:flex;align-items:center;justify-content:center;flex-shrink:0;color:var(--accent)">
            <i class="bi bi-shield-exclamation" style="font-size:1.25rem"></i>
        </div>
        <div class="flex-fill">
            <h5 class="serif mb-1" style="color:var(--text)">{{ $activeSanction->typeLabel() }}</h5>
            <p class="mb-2" style="font-size:.88rem;color:var(--text-muted);line-height:1.65">
                {{ $activeSanction->reason }}
            </p>
            @if($activeSanction->expires_at)
            <div style="font-size:.82rem;color:var(--text)">
                <i class="bi bi-clock me-1" style="color:var(--accent)"></i>
                Hết hạn: {{ $activeSanction->expires_at->format('d/m/Y H:i') }}
                <span class="ms-1" style="color:var(--text-muted)">({{ $activeSanction->expires_at->diffForHumans() }})</span>
            </div>
            @elseif($activeSanction->type === 'permanent_ban')
            <div style="font-size:.82rem;color:var(--accent)">
                <i class="bi bi-infinity me-1"></i>Vĩnh viễn
            </div>
            @endif
        </div>
    </div>
</div>
@endif

@if($sanctions->isNotEmpty())
<div class="mb-4 p-4" style="background:var(--surface);border:1px solid var(--border);border-radius:12px">
    <h5 class="serif mb-3" style="color:var(--text)">Lịch sử xử lý</h5>

    <div class="d-flex flex-column gap-3">
        @foreach($sanctions as $sanction)
        <div class="p-3" style="background:var(--surface-alt);border:1px solid var(--border);border-radius:10px;border-left:4px solid {{ $sanction->typeColor() }}">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <div class="d-flex align-items-center gap-2">
                    <span class="badge rounded-pill" style="background:{{ $sanction->typeColor() }}20;color:{{ $sanction->typeColor() }};font-size:.75rem;border:1px solid {{ $sanction->typeColor() }}40">
                        {{ $sanction->typeLabel() }}
                    </span>
                    @if($sanction->isCurrentlyActive())
                        <span class="badge rounded-pill" style="background:rgba(34,197,94,.12);color:#22c55e;font-size:.75rem;border:1px solid rgba(34,197,94,.3)">Đang hiệu lực</span>
                    @else
                        <span class="badge rounded-pill" style="background:rgba(107,114,128,.12);color:var(--text-muted);font-size:.75rem;border:1px solid rgba(107,114,128,.3)">Hết hiệu lực</span>
                    @endif
                </div>
                <span style="font-size:.75rem;color:var(--text-muted)">{{ $sanction->created_at->format('d/m/Y') }}</span>
            </div>
            <p style="font-size:.85rem;color:var(--text);margin-bottom:.5rem">{{ $sanction->reason }}</p>
            @if($sanction->expires_at)
            <div style="font-size:.78rem;color:var(--text-muted)">
                <i class="bi bi-clock me-1"></i>Hết hạn: {{ $sanction->expires_at->format('d/m/Y H:i') }}
            </div>
            @endif

            {{-- Appeal section --}}
            @if($sanction->type !== 'warning' && $sanction->type !== 'permanent_ban' && $sanction->isCurrentlyActive())
                @if($sanction->appeal_status === 'none')
                    <div class="mt-3 pt-3" style="border-top:1px solid var(--border)">
                        <button type="button" class="btn btn-sm"
                                style="background:rgba(230,57,70,.08);color:var(--accent);border:1px solid rgba(230,57,70,.2);border-radius:8px;font-size:.8rem"
                                onclick="document.getElementById('appeal-form-{{ $sanction->id }}').classList.toggle('d-none')">
                            <i class="bi bi-file-earmark-text me-1"></i>Kháng cáo
                        </button>
                        <div id="appeal-form-{{ $sanction->id }}" class="d-none mt-2">
                            <form action="{{ route('sanctions.appeal', $sanction) }}" method="POST">
                                @csrf
                                <textarea name="appeal_message" class="vf-textarea mb-2" rows="3" required
                                          placeholder="Giải thích lý do bạn cho rằng biện pháp xử lý này không công bằng..."></textarea>
                                <div class="d-flex gap-2">
                                    <button type="submit" class="btn-accent" style="font-size:.8rem;padding:.35rem .9rem">
                                        Gửi kháng cáo
                                    </button>
                                    <button type="button" class="btn btn-sm"
                                            style="background:none;border:none;color:var(--text-muted);font-size:.8rem"
                                            onclick="document.getElementById('appeal-form-{{ $sanction->id }}').classList.add('d-none')">
                                        Hủy
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                @else
                    <div class="mt-3 pt-3" style="border-top:1px solid var(--border)">
                        <div class="d-flex align-items-center gap-2">
                            <span style="font-size:.8rem;color:var(--text-muted)">Trạng thái kháng cáo:</span>
                            <span class="badge rounded-pill" style="font-size:.75rem;
                                {{ $sanction->appeal_status === 'pending' ? 'background:rgba(245,158,11,.12);color:#f59e0b;border:1px solid rgba(245,158,11,.3)' : '' }}
                                {{ $sanction->appeal_status === 'approved' ? 'background:rgba(34,197,94,.12);color:#22c55e;border:1px solid rgba(34,197,94,.3)' : '' }}
                                {{ $sanction->appeal_status === 'denied' ? 'background:rgba(239,68,68,.12);color:#ef4444;border:1px solid rgba(239,68,68,.3)' : '' }}">
                                {{ $sanction->appealStatusLabel() }}
                            </span>
                        </div>
                        @if($sanction->appeal_message)
                        <div class="mt-2 p-2" style="background:var(--bg);border-radius:8px;font-size:.82rem;color:var(--text-muted)">
                            <strong style="color:var(--text)">Nội dung kháng cáo:</strong><br>
                            {{ $sanction->appeal_message }}
                        </div>
                        @endif
                    </div>
                @endif
            @endif
        </div>
        @endforeach
    </div>
</div>
@endif
