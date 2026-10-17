<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <title>Індивідуальні заміни {{ $date }}</title>
    <style>
        @page { size: A4 landscape; margin: 8mm; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: -apple-system, Segoe UI, Roboto, DejaVu Sans, sans-serif; color: #111827; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        #src { position: absolute; left: -10000px; top: 0; width: 281mm; }
        #src > div { display: block !important; }
        /* Аркуш = A4 горизонтально мінус поля */
        .sheet { position: relative; width: 281mm; height: 193mm; overflow: hidden; break-after: page; page-break-after: always; }
        .sheet:last-child { break-after: auto; page-break-after: auto; }
        .sheet-head { height: 7mm; display: flex; justify-content: space-between; align-items: baseline; border-bottom: 2px solid #7c3aed; font-size: 12px; font-weight: 800; color: #5b21b6; }
        .slot { position: absolute; }
        .slot > .ind-card { transform-origin: top left; margin: 0 !important; }
        @media screen { body { background: #e5e7eb; padding: 10px; } .sheet { background: #fff; margin: 0 auto 10px; padding: 0; box-shadow: 0 1px 4px rgba(0,0,0,.2); } }
    </style>
</head>
<body>
@php
    $blocks = [];
    foreach ($reportByPlan as $planBlock) {
        foreach ($planBlock['report'] as $t) {
            if (!empty($t['is_ind_block']) && !empty($t['dishes'])) $blocks[] = $t;
        }
    }
    $dayLabel = \Carbon\Carbon::parse($targetDate)->format('d.m');
@endphp

@if(empty($blocks))
    <p style="padding:20px;">На {{ $dayLabel }} індивідуальних страв немає.</p>
@else
    {{-- Джерело: картки малюються поза екраном, скрипт розкладає їх по аркушах --}}
    <div id="src">
        @foreach($blocks as $b)
            @include('print.partials.ind-block', ['block' => $b])
        @endforeach
    </div>
    <div id="out"></div>

    <script>
    (function () {
        var mm = document.createElement('div');
        mm.style.cssText = 'position:absolute;width:100mm;height:100mm;left:-9999px';
        document.body.appendChild(mm);
        var px = mm.offsetWidth / 100; mm.remove();

        var W = 281 * px, H = (193 - 9) * px, GAP = 2.5 * px, COLS = 3;
        var colW = (W - GAP * (COLS - 1)) / COLS;
        var out = document.getElementById('out');
        var title = 'Індивідуальні заміни · фасування на {{ $dayLabel }} <span style="font-weight:600;font-size:10px;color:#6d28d9;">· «сирих» = закладка сирих продуктів, не вага готового боксу</span>';
        var sheets = [], cur, cols;

        function newSheet() {
            var s = document.createElement('div'); s.className = 'sheet';
            s.innerHTML = '<div class="sheet-head"><span>' + title + '</span><span class="pg"></span></div>';
            var body = document.createElement('div'); body.style.cssText = 'position:relative;height:' + H + 'px;margin-top:' + (2 * px) + 'px';
            s.appendChild(body); out.appendChild(s); sheets.push(s);
            cur = body; cols = [0, 0, 0];
        }

        // Міряємо картку на потрібній ширині; якщо не влазить — стискаємо цілою, а не ріжемо.
        function measure(card, w) {
            card.style.width = w + 'px'; card.style.minWidth = '0'; card.style.flex = 'none';
            var cw = Math.max(card.scrollWidth, w), h = card.offsetHeight;
            var s = Math.min(1, w / cw, H / h);
            return { s: s, w: w, h: h * s, inner: cw };
        }

        function place(card, m, x, y) {
            var slot = document.createElement('div'); slot.className = 'slot';
            slot.style.cssText = 'left:' + x + 'px;top:' + y + 'px;width:' + m.w + 'px;height:' + m.h + 'px';
            if (m.s < 1) { card.style.width = (m.w / m.s) + 'px'; card.style.transform = 'scale(' + m.s + ')'; }
            slot.appendChild(card); cur.appendChild(slot);
        }

        newSheet();
        Array.prototype.slice.call(document.querySelectorAll('#src .ind-card')).forEach(function (card) {
            // Спершу пробуємо в колонку (третина аркуша); не влазить по ширині — на всю ширину.
            var m = measure(card, colW), wide = m.inner > colW + 1;
            if (wide) m = measure(card, W);
            if (wide) {
                var y = Math.max.apply(null, cols); if (y > 0) y += GAP;
                if (y + m.h > H + 0.5) { newSheet(); y = 0; }
                place(card, m, 0, y); cols = [y + m.h, y + m.h, y + m.h];
            } else {
                var i = cols.indexOf(Math.min.apply(null, cols)), yy = cols[i] > 0 ? cols[i] + GAP : 0;
                if (yy + m.h > H + 0.5) { newSheet(); i = 0; yy = 0; }
                place(card, m, i * (colW + GAP), yy); cols[i] = yy + m.h;
            }
        });
        document.getElementById('src').remove();
        sheets.forEach(function (s, i) { s.querySelector('.pg').textContent = 'аркуш ' + (i + 1) + ' з ' + sheets.length; });
        setTimeout(function () { window.print(); }, 400);
    })();
    </script>
@endif
</body>
</html>
