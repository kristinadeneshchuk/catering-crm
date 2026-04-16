<div x-data="kitchenBell()" x-init="initEcho($wire)" class="relative flex items-center">


    {{-- Bell icon with badge --}}
    <div class="relative" style="display:inline-block;">
        <a href="{{ route('filament.admin.pages.kitchen-notifications') }}"
           class="flex items-center justify-center w-9 h-9 rounded-xl transition-all
                  {{ $unreadCount > 0 ? 'bg-rose-500/20 border border-rose-500/50 hover:bg-rose-500/30' : 'bg-zinc-800 border border-white/10 hover:bg-zinc-700' }}"
           title="Сповіщення кухні">
            <svg class="w-5 h-5 {{ $unreadCount > 0 ? 'text-rose-400' : 'text-zinc-400' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
            </svg>
        </a>
        @if($unreadCount > 0)
            <span style="position:absolute; top:-8px; right:-8px; min-width:20px; height:20px; padding:0 4px; background:#ef4444; color:#fff; font-size:11px; font-weight:900; border-radius:999px; display:flex; align-items:center; justify-content:center; box-shadow:0 0 10px rgba(239,68,68,0.9); border:2px solid #18181b; z-index:10; pointer-events:none;">
                {{ $unreadCount > 99 ? '99+' : $unreadCount }}
            </span>
        @endif
    </div>

    {{-- Toast notifications --}}
    @teleport('body')
    <div style="position:fixed; bottom:24px; right:24px; z-index:99999; display:flex; flex-direction:column; gap:12px; pointer-events:none; width:360px; max-width:calc(100vw - 32px);">
        @foreach($latestToasts as $index => $toast)
        @php
            if ($toast['has_exclusions']) {
                $cardStyle   = 'background:linear-gradient(135deg,#3b0114 0%,#18181b 100%); border:1px solid rgba(239,68,68,0.6);';
                $barColor    = '#ef4444';
                $iconBg      = 'rgba(239,68,68,0.15)';
                $labelColor  = '#f87171';
                $labelText   = '⚠ Є алергени!';
                $icon        = '⚠️';
            } elseif ($toast['type'] === 'new_client') {
                $cardStyle   = 'background:linear-gradient(135deg,#052e16 0%,#18181b 100%); border:1px solid rgba(16,185,129,0.5);';
                $barColor    = '#10b981';
                $iconBg      = 'rgba(16,185,129,0.15)';
                $labelColor  = '#34d399';
                $labelText   = 'Новий клієнт';
                $icon        = '🆕';
            } else {
                $cardStyle   = 'background:linear-gradient(135deg,#0c1a3a 0%,#18181b 100%); border:1px solid rgba(59,130,246,0.5);';
                $barColor    = '#3b82f6';
                $iconBg      = 'rgba(59,130,246,0.15)';
                $labelColor  = '#60a5fa';
                $labelText   = 'Продовження';
                $icon        = '🔄';
            }
        @endphp
        <div style="{{ $cardStyle }} border-radius:16px; overflow:hidden; box-shadow:0 25px 50px rgba(0,0,0,0.6); pointer-events:auto; position:relative;"
             x-show="true"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0 translate-y-4 scale-95"
             x-transition:enter-end="opacity-100 translate-y-0 scale-100">

            {{-- Colored top bar --}}
            <div style="height:3px; width:100%; background:{{ $barColor }};"></div>

            <div style="padding:16px;">
                {{-- Header row --}}
                <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:12px; margin-bottom:12px;">
                    <div style="display:flex; align-items:center; gap:10px;">
                        <div style="width:40px; height:40px; border-radius:10px; background:{{ $iconBg }}; display:flex; align-items:center; justify-content:center; flex-shrink:0; font-size:20px;">
                            {{ $icon }}
                        </div>
                        <div>
                            <p style="font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.08em; color:{{ $labelColor }}; margin:0 0 2px;">
                                {{ $labelText }}
                            </p>
                            <p style="color:#fff; font-weight:900; font-size:17px; line-height:1.2; margin:0;">{{ $toast['client_name'] }}</p>
                        </div>
                    </div>
                    <button wire:click="dismissToast({{ $index }})"
                            style="flex-shrink:0; width:24px; height:24px; border-radius:8px; background:rgba(255,255,255,0.1); border:none; cursor:pointer; display:flex; align-items:center; justify-content:center; color:#a1a1aa;">
                        <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                {{-- Info grid --}}
                <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:8px;">
                    <div style="background:rgba(255,255,255,0.07); border-radius:10px; padding:10px; text-align:center;">
                        <p style="font-size:9px; color:#71717a; text-transform:uppercase; font-weight:600; letter-spacing:0.05em; margin:0 0 2px;">Ккал</p>
                        <p style="color:#fff; font-weight:700; font-size:15px; margin:0;">{{ $toast['calories'] }}</p>
                    </div>
                    <div style="background:rgba(255,255,255,0.07); border-radius:10px; padding:10px; text-align:center;">
                        <p style="font-size:9px; color:#71717a; text-transform:uppercase; font-weight:600; letter-spacing:0.05em; margin:0 0 2px;">Днів</p>
                        <p style="color:#fff; font-weight:700; font-size:15px; margin:0;">{{ $toast['duration'] }}</p>
                    </div>
                    <div style="background:rgba(255,255,255,0.07); border-radius:10px; padding:10px; text-align:center;">
                        <p style="font-size:9px; color:#71717a; text-transform:uppercase; font-weight:600; letter-spacing:0.05em; margin:0 0 2px;">Старт</p>
                        <p style="color:#fff; font-weight:700; font-size:13px; margin:0;">{{ $toast['start_date'] }}</p>
                    </div>
                </div>

                @if($toast['project'])
                <div style="margin-top:8px; display:flex; align-items:center; gap:6px;">
                    <span style="font-size:12px; color:#71717a;">Проєкт:</span>
                    <span style="font-size:12px; font-weight:600; color:#d4d4d8;">{{ $toast['project'] }}</span>
                </div>
                @endif
            </div>

            {{-- Progress bar (auto-dismiss timer) --}}
            <div style="height:3px; background:rgba(255,255,255,0.05);">
                <div style="height:100%; background:{{ $barColor }}; transform-origin:left;"
                     x-data="{ width: 100 }"
                     x-init="setTimeout(() => { width = 0 }, 100)"
                     :style="`width: ${width}%; transition: width 20s linear`"></div>
            </div>
        </div>
        @endforeach
    </div>
    @endteleport
</div>

<script>
function kitchenBell() {
    return {
        audioCtx: null,

        initAudioCtx() {
            // Create AudioContext immediately, then resume on first any user interaction
            try {
                this.audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            } catch(e) {}

            const unlock = () => {
                if (this.audioCtx) {
                    if (this.audioCtx.state === 'suspended') {
                        this.audioCtx.resume();
                    }
                } else {
                    try { this.audioCtx = new (window.AudioContext || window.webkitAudioContext)(); } catch(e) {}
                }
                document.removeEventListener('click',    unlock);
                document.removeEventListener('keydown',  unlock);
                document.removeEventListener('touchstart', unlock);
            };
            document.addEventListener('click',     unlock);
            document.addEventListener('keydown',   unlock);
            document.addEventListener('touchstart', unlock);
        },

        playSound() {
            try {
                const ctx = this.audioCtx || new (window.AudioContext || window.webkitAudioContext)();

                const doPlay = () => {
                    const t = ctx.currentTime;
                    const play = (freq, start, dur, vol = 0.35) => {
                        const osc  = ctx.createOscillator();
                        const gain = ctx.createGain();
                        osc.connect(gain);
                        gain.connect(ctx.destination);
                        osc.type = 'sine';
                        osc.frequency.setValueAtTime(freq, t + start);
                        gain.gain.setValueAtTime(0, t + start);
                        gain.gain.linearRampToValueAtTime(vol, t + start + 0.02);
                        gain.gain.setValueAtTime(vol, t + start + dur - 0.05);
                        gain.gain.exponentialRampToValueAtTime(0.001, t + start + dur);
                        osc.start(t + start);
                        osc.stop(t + start + dur);
                    };
                    // Pleasant ding-dong
                    play(1318, 0.0, 0.35);
                    play(1046, 0.3, 0.25);
                    play(1174, 0.5, 0.45);
                };

                if (ctx.state === 'suspended') {
                    ctx.resume().then(doPlay);
                } else {
                    doPlay();
                }
            } catch(e) {}
        },

        initEcho(wire) {
            this.initAudioCtx();
            if (!window.Echo) return;
            window.Echo.channel('kitchen')
                .listen('.order.notification', (data) => {
                    this.playSound();
                    wire.dispatch('kitchen-notification-received', data);
                    // Auto-dismiss after 20 seconds
                    setTimeout(() => wire.call('dismissToast', 0), 20000);
                });
        }
    }
}
</script>
