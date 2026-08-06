<x-filament-panels::page>
    @php
        /** @var \App\Models\OperationsItem $record */
        $item = $record;
        $priority = $item->priorityEnum();
        $status = $item->statusEnum();
        $type = $item->typeEnum();
    @endphp

    <div style="display:grid;grid-template-columns:1fr 320px;gap:1.5rem" data-testid="ops-item-view">
        <div>
            <div style="background:#0f172a;color:#e2e8f0;border-radius:.75rem;padding:1.25rem">
                <div style="display:flex;gap:.5rem;align-items:center;margin-bottom:.5rem;flex-wrap:wrap">
                    <span style="background:{{ $priority->color() === 'danger' ? '#dc2626' : ($priority->color() === 'warning' ? '#d97706' : ($priority->color() === 'primary' ? '#2563eb' : '#475569')) }};padding:.15rem .55rem;border-radius:9999px;font-size:.75rem;font-weight:600;text-transform:uppercase" data-testid="ops-item-priority">
                        {{ $priority->label() }}
                    </span>
                    <span style="background:#334155;padding:.15rem .55rem;border-radius:9999px;font-size:.75rem" data-testid="ops-item-status">
                        {{ $status->label() }}
                    </span>
                    @if($item->escalation_level > 0)
                        <span style="background:#7c2d12;padding:.15rem .55rem;border-radius:9999px;font-size:.75rem" data-testid="ops-item-escalated">
                            Escalated ×{{ $item->escalation_level }}
                        </span>
                    @endif
                    <span style="color:#94a3b8;font-size:.75rem;margin-left:auto" data-testid="ops-item-type">{{ $type?->label() }}</span>
                </div>
                <h2 style="font-size:1.35rem;font-weight:600;margin:0 0 .35rem" data-testid="ops-item-title">{{ $item->title }}</h2>
                @if($item->summary)
                    <p style="margin:0;color:#cbd5e1" data-testid="ops-item-summary">{{ $item->summary }}</p>
                @endif
            </div>

            @if($item->subject)
                <div style="margin-top:1.25rem;padding:1rem;border:1px solid #e2e8f0;border-radius:.5rem" data-testid="ops-item-subject">
                    <div style="font-size:.75rem;text-transform:uppercase;letter-spacing:.08em;color:#64748b;margin-bottom:.5rem">Related record</div>
                    <div><strong>{{ class_basename($item->subject_type) }}</strong> #{{ $item->subject_id }}</div>
                </div>
            @endif

            <div style="margin-top:1.5rem" data-testid="ops-item-audit">
                <h3 style="font-size:.9rem;text-transform:uppercase;letter-spacing:.08em;color:#64748b;margin:0 0 .75rem">Audit history</h3>
                <ol style="list-style:none;padding:0;margin:0;border-left:2px solid #e2e8f0">
                    @foreach($item->events()->orderBy('id')->get() as $ev)
                        <li style="padding:.6rem 0 .6rem 1rem;position:relative" data-testid="ops-item-audit-row">
                            <span style="position:absolute;left:-6px;top:1.05rem;width:10px;height:10px;border-radius:9999px;background:#0f172a"></span>
                            <div style="display:flex;justify-content:space-between;gap:1rem;font-size:.85rem">
                                <div>
                                    <strong>{{ $ev->action }}</strong>
                                    @if($ev->actor_user_id)
                                        <span style="color:#64748b">by {{ optional($ev->actor)->email ?? 'user #'.$ev->actor_user_id }}</span>
                                    @else
                                        <span style="color:#64748b">(system)</span>
                                    @endif
                                </div>
                                <span style="color:#94a3b8">{{ $ev->created_at?->diffForHumans() }}</span>
                            </div>
                            @if(! empty($ev->payload))
                                <pre style="margin:.35rem 0 0;background:#f1f5f9;padding:.5rem;border-radius:.35rem;font-size:.75rem;overflow:auto">{{ json_encode($ev->payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</pre>
                            @endif
                        </li>
                    @endforeach
                </ol>
            </div>
        </div>

        <aside style="background:#f8fafc;border-radius:.75rem;padding:1rem;font-size:.85rem" data-testid="ops-item-sidebar">
            <dl style="display:grid;grid-template-columns:110px 1fr;gap:.35rem 1rem;margin:0">
                <dt style="color:#64748b">Opened</dt>
                <dd style="margin:0">{{ $item->created_at?->diffForHumans() }}</dd>
                <dt style="color:#64748b">First viewed</dt>
                <dd style="margin:0" data-testid="ops-item-first-viewed">{{ $item->first_viewed_at?->diffForHumans() ?? '—' }}</dd>
                <dt style="color:#64748b">Due</dt>
                <dd style="margin:0">{{ $item->due_at?->diffForHumans() ?? '—' }}</dd>
                <dt style="color:#64748b">Assigned</dt>
                <dd style="margin:0">{{ optional($item->assignedTo)->email ?? '—' }}</dd>
                <dt style="color:#64748b">Escalation</dt>
                <dd style="margin:0">Level {{ $item->escalation_level ?? 0 }}</dd>
                <dt style="color:#64748b">Resolved</dt>
                <dd style="margin:0">{{ $item->resolved_at?->diffForHumans() ?? '—' }}</dd>
            </dl>

            @if($item->resolution_notes)
                <div style="margin-top:1rem;padding-top:1rem;border-top:1px solid #e2e8f0">
                    <div style="font-size:.75rem;text-transform:uppercase;letter-spacing:.08em;color:#64748b">Resolution notes</div>
                    <p style="white-space:pre-wrap;margin:.35rem 0 0" data-testid="ops-item-resolution-notes">{{ $item->resolution_notes }}</p>
                </div>
            @endif
        </aside>
    </div>
</x-filament-panels::page>
