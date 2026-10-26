<!doctype html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#166534">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Каса">
    <link rel="manifest" href="{{ route('cash-app.manifest') }}">
    <link rel="apple-touch-icon" href="/cash-icons/icon-180.png">
    <link rel="icon" href="/cash-icons/icon-192.png">
    <title>Каса</title>
    <style>
        :root {
            --bg: #f3f5f4; --card: #ffffff; --text: #111816; --muted: #66706c; --line: #e3e7e5;
            --green: #15803d; --green-bg: #dcfce7; --red: #dc2626; --red-bg: #fee2e2;
            --blue: #2563eb; --blue-bg: #dbeafe; --accent: #166534; --chip: #eef1ef;
            --shadow: 0 1px 2px rgba(0,0,0,.06), 0 4px 16px rgba(0,0,0,.04);
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0f1412; --card: #171e1b; --text: #eef3f0; --muted: #94a19b; --line: #26302c;
                --green: #4ade80; --green-bg: #12321f; --red: #f87171; --red-bg: #3a1717;
                --blue: #60a5fa; --blue-bg: #172a46; --accent: #22c55e; --chip: #222b27;
                --shadow: none;
            }
        }
        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        html, body { margin: 0; background: var(--bg); color: var(--text);
            font: 16px/1.4 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        body { padding: env(safe-area-inset-top) 0 calc(env(safe-area-inset-bottom) + 24px); }
        button, input, select, textarea { font: inherit; color: inherit; }
        .wrap { max-width: 560px; margin: 0 auto; padding: 12px 16px; }

        header { display: flex; align-items: center; gap: 8px; padding-top: 4px; }
        header select { flex: 1; min-width: 0; border: 0; background: transparent; font-size: 20px; font-weight: 700;
            padding: 6px 0; appearance: none; -webkit-appearance: none; }
        .icon-btn { border: 0; background: var(--card); width: 40px; height: 40px; border-radius: 12px;
            box-shadow: var(--shadow); font-size: 18px; flex: none; }
        .who { color: var(--muted); font-size: 13px; }

        .offline { display: none; background: var(--red-bg); color: var(--red); padding: 8px 12px; border-radius: 12px;
            margin-top: 8px; font-size: 14px; }
        body.is-offline .offline { display: block; }

        .balance { background: var(--accent); color: #fff; border-radius: 20px; padding: 18px 18px 14px; margin-top: 10px; }
        .balance .label { opacity: .85; font-size: 14px; }
        .balance .sum { font-size: 34px; font-weight: 800; letter-spacing: -.5px; margin: 2px 0 10px; }
        .balance .row { display: flex; gap: 16px; font-size: 13px; opacity: .92; flex-wrap: wrap; }

        .actions { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin: 14px 0 6px; }
        .act { border: 0; border-radius: 16px; padding: 16px 10px; font-weight: 700; font-size: 17px; box-shadow: var(--shadow); }
        .act.in { background: var(--green-bg); color: var(--green); }
        .act.out { background: var(--red-bg); color: var(--red); }
        .act.tr, .act.rc { background: var(--card); color: var(--text); font-size: 15px; padding: 12px 10px; }

        .day { color: var(--muted); font-size: 13px; font-weight: 600; margin: 18px 4px 6px; text-transform: uppercase; letter-spacing: .03em; }
        .list { background: var(--card); border-radius: 16px; box-shadow: var(--shadow); overflow: hidden; }
        .op { display: flex; width: 100%; gap: 10px; align-items: center; text-align: left; border: 0; background: none;
            padding: 12px 14px; border-top: 1px solid var(--line); }
        .op:first-child { border-top: 0; }
        .op .main { flex: 1; min-width: 0; }
        .op .cat { font-weight: 600; font-size: 15px; }
        .op .det { color: var(--muted); font-size: 13px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .op .right { text-align: right; flex: none; }
        .op .amt { font-weight: 700; font-variant-numeric: tabular-nums; }
        .op .bal { color: var(--muted); font-size: 12px; font-variant-numeric: tabular-nums; }
        .plus { color: var(--green); } .minus { color: var(--red); } .neutral { color: var(--blue); }
        .more { width: 100%; margin-top: 12px; padding: 12px; border: 0; border-radius: 14px; background: var(--card); color: var(--muted); }
        .empty { text-align: center; color: var(--muted); padding: 32px 12px; }

        /* Аркуш знизу */
        .sheet-bg { position: fixed; inset: 0; background: rgba(0,0,0,.45); display: none; z-index: 10; }
        .sheet { position: fixed; left: 0; right: 0; bottom: 0; z-index: 11; background: var(--card); border-radius: 22px 22px 0 0;
            max-height: 92vh; overflow: auto; padding: 8px 16px calc(env(safe-area-inset-bottom) + 16px);
            transform: translateY(105%); transition: transform .22s ease; max-width: 560px; margin: 0 auto; }
        body.sheet-open .sheet-bg { display: block; }
        body.sheet-open .sheet { transform: none; }
        body.sheet-open { overflow: hidden; }
        .grab { width: 40px; height: 5px; border-radius: 3px; background: var(--line); margin: 4px auto 10px; }
        .sheet h2 { margin: 0 0 12px; font-size: 20px; }
        .field { margin: 12px 0; }
        .field > label { display: block; font-size: 13px; color: var(--muted); margin-bottom: 6px; }
        .field input, .field select, .field textarea { width: 100%; border: 1px solid var(--line); background: var(--bg);
            border-radius: 12px; padding: 12px; outline: none; }
        .field input:focus, .field select:focus, .field textarea:focus { border-color: var(--accent); }
        .amount-input { font-size: 30px !important; font-weight: 800; text-align: center; font-variant-numeric: tabular-nums; }
        .chips { display: flex; flex-wrap: wrap; gap: 8px; }
        .chip { border: 1px solid transparent; background: var(--chip); border-radius: 999px; padding: 9px 13px; font-size: 14px; }
        .chip small { display: block; font-size: 11px; color: var(--muted); }
        .chip.on { background: var(--accent); color: #fff; }
        .chip.on small { color: rgba(255,255,255,.8); }
        .hint { font-size: 13px; color: var(--muted); margin-top: 6px; }
        .err { font-size: 13px; color: var(--red); margin-top: 6px; }
        .row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .save { width: 100%; border: 0; border-radius: 16px; padding: 16px; font-weight: 800; font-size: 17px;
            background: var(--accent); color: #fff; margin-top: 8px; }
        .save.red { background: var(--red); }
        .save:disabled { opacity: .55; }
        .ghost { width: 100%; border: 1px solid var(--line); border-radius: 14px; padding: 13px; background: transparent; margin-top: 8px; font-weight: 600; }
        .ghost.danger { color: var(--red); }
        .results { border: 1px solid var(--line); border-radius: 12px; margin-top: 6px; overflow: hidden; }
        .results button { display: block; width: 100%; text-align: left; border: 0; border-top: 1px solid var(--line); background: none; padding: 11px 12px; font-size: 14px; }
        .results button:first-child { border-top: 0; }
        .results button.on { background: var(--green-bg); }
        .kv { display: grid; grid-template-columns: auto 1fr; gap: 6px 14px; font-size: 15px; margin: 6px 0 10px; }
        .kv dt { color: var(--muted); } .kv dd { margin: 0; text-align: right; word-break: break-word; }
        .big { font-size: 32px; font-weight: 800; text-align: center; margin: 4px 0 10px; font-variant-numeric: tabular-nums; }
        .hist { border: 1px solid var(--line); border-radius: 12px; padding: 10px 12px; margin-top: 10px; font-size: 14px; }
        .hist b { display: block; margin-bottom: 4px; }
        .hist .ch { display: flex; justify-content: space-between; gap: 8px; border-top: 1px dashed var(--line); padding: 5px 0; }
        .hist .old { color: var(--red); text-decoration: line-through; } .hist .new { color: var(--green); }

        .toast { position: fixed; left: 50%; bottom: calc(env(safe-area-inset-bottom) + 20px); transform: translateX(-50%) translateY(150%); visibility: hidden;
            background: #111816; color: #fff; padding: 12px 18px; border-radius: 14px; z-index: 20; transition: transform .2s; max-width: 90vw; text-align: center; }
        .toast.show { transform: translateX(-50%); visibility: visible; }
        .toast.bad { background: var(--red); }
        .install { display: none; margin-top: 10px; background: var(--blue-bg); color: var(--blue); border-radius: 12px; padding: 10px 12px; font-size: 14px; }
    </style>
</head>
<body>
<div class="wrap">
    <header>
        <select id="account" aria-label="Рахунок"></select>
        <button class="icon-btn" id="reload" aria-label="Оновити">⟳</button>
    </header>
    <div class="who">{{ $user->name }} · <a href="/admin/cash" style="color:inherit">повна версія</a></div>
    <div class="offline">Немає звʼязку — операції не збережуться, поки не зʼявиться інтернет.</div>
    <div class="install" id="install"></div>

    <section class="balance">
        <div class="label">Залишок</div>
        <div class="sum" id="balance">…</div>
        <div class="row">
            <span>Сьогодні: <b id="today-in">+0</b> / <b id="today-out">−0</b></span>
            <span>Місяць: <b id="month-in">+0</b> / <b id="month-out">−0</b></span>
        </div>
    </section>

    <div class="actions">
        <button class="act in" data-open="income">+ Прихід</button>
        <button class="act out" data-open="expense">− Витрата</button>
        <button class="act tr" data-open="transfer">⇄ Переказ</button>
        <button class="act rc" data-open="recount">⚖ Перерахунок</button>
    </div>

    <div id="ops"></div>
    <button class="more" id="more" hidden>Показати ще</button>
</div>

<div class="sheet-bg" id="sheet-bg"></div>
<div class="sheet" id="sheet" role="dialog" aria-modal="true"><div class="grab"></div><div id="sheet-body"></div></div>
<div class="toast" id="toast"></div>

<script>
(() => {
    const CSRF = document.querySelector('meta[name=csrf-token]').content;
    const $ = (s, el = document) => el.querySelector(s);
    const $$ = (s, el = document) => [...el.querySelectorAll(s)];
    const esc = (v) => String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const nf = new Intl.NumberFormat('uk-UA', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
    const money = (v) => nf.format(Math.abs(v)) + ' ₴';
    const signed = (v) => (v > 0 ? '+' : v < 0 ? '−' : '') + money(v);
    const today = () => { const d = new Date(); d.setMinutes(d.getMinutes() - d.getTimezoneOffset()); return d.toISOString().slice(0, 10); };
    const uid = () => (crypto.randomUUID ? crypto.randomUUID() : Date.now() + '-' + Math.random());

    const state = { refs: null, account: null, ops: [], page: 0, hasMore: false, balance: 0 };

    // ---------- мережа ----------
    async function api(method, url, body, idem) {
        let res;
        try {
            res = await fetch(url, {
                method, credentials: 'same-origin',
                headers: Object.assign({ 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF, 'X-Requested-With': 'XMLHttpRequest' },
                    body ? { 'Content-Type': 'application/json' } : {}, idem ? { 'X-Idempotency-Key': idem } : {}),
                body: body ? JSON.stringify(body) : undefined,
            });
        } catch (e) {
            throw { offline: true, message: 'Немає звʼязку — не збережено. Спробуйте ще раз.' };
        }
        if (res.status === 401 || res.status === 419) {
            toast('Сесія закінчилась — увійдіть ще раз', true);
            setTimeout(() => location.href = '/admin/login', 1200);
            throw { message: 'Сесія закінчилась' };
        }
        const json = await res.json().catch(() => ({}));
        if (res.status === 422) throw { errors: json.errors || {}, message: json.message };
        if (!res.ok) throw { message: json.message || ('Помилка ' + res.status) };
        return json;
    }

    function toast(text, bad = false) {
        const t = $('#toast'); t.textContent = text; t.className = 'toast show' + (bad ? ' bad' : '');
        clearTimeout(toast.t); toast.t = setTimeout(() => t.className = 'toast' + (bad ? ' bad' : ''), 2600);
    }

    // ---------- головний екран ----------
    async function loadRefs() {
        state.refs = await api('GET', '/cash-app/refs');
        const saved = Number(localStorageGet('cash-account'));
        const ids = state.refs.accounts.map(a => a.id);
        state.account = ids.includes(saved) ? saved : (state.refs.default_account || ids[0]);
        $('#account').innerHTML = state.refs.accounts.map(a => `<option value="${a.id}">${esc(a.name)}</option>`).join('');
        $('#account').value = state.account;
    }

    async function loadData(append = false) {
        const page = append ? state.page + 1 : 0;
        const d = await api('GET', `/cash-app/data?account=${state.account}&page=${page}`);
        state.page = page;
        state.ops = append ? state.ops.concat(d.ops) : d.ops;
        state.hasMore = d.has_more;
        state.balance = d.balance;
        $('#balance').textContent = (d.balance < 0 ? '−' : '') + money(d.balance);
        $('#today-in').textContent = '+' + money(d.today.in);
        $('#today-out').textContent = '−' + money(d.today.out);
        $('#month-in').textContent = '+' + money(d.month.in);
        $('#month-out').textContent = '−' + money(d.month.out);
        renderOps();
    }

    function dayLabel(ymd) {
        const t = today();
        const y = new Date(Date.now() - 864e5); y.setMinutes(y.getMinutes() - y.getTimezoneOffset());
        if (ymd === t) return 'Сьогодні';
        if (ymd === y.toISOString().slice(0, 10)) return 'Вчора';
        const d = new Date(ymd + 'T12:00:00');
        return d.toLocaleDateString('uk-UA', { weekday: 'short', day: 'numeric', month: 'long' });
    }

    function renderOps() {
        const box = $('#ops');
        if (!state.ops.length) { box.innerHTML = '<div class="empty">Операцій ще немає</div>'; $('#more').hidden = true; return; }
        const groups = [];
        state.ops.forEach(o => {
            const g = groups[groups.length - 1];
            if (g && g.date === o.date) g.items.push(o); else groups.push({ date: o.date, items: [o] });
        });
        box.innerHTML = groups.map(g => `<div class="day">${esc(dayLabel(g.date))}</div><div class="list">` + g.items.map(o => `
            <button class="op" data-op="${o.id}">
                <div class="main">
                    <div class="cat">${esc(o.category || '—')}</div>
                    <div class="det">${esc([o.details, o.details !== o.comment ? o.comment : null].filter(Boolean).join(' · ') || ' ')}</div>
                </div>
                <div class="right">
                    <div class="amt ${o.non_flow ? 'neutral' : o.amount > 0 ? 'plus' : 'minus'}">${signed(o.amount)}</div>
                    <div class="bal">${o.balance === null ? '' : 'залишок ' + money(o.balance)}</div>
                </div>
            </button>`).join('') + '</div>').join('');
        $('#more').hidden = !state.hasMore;
    }

    // ---------- аркуш ----------
    function openSheet(html) { $('#sheet-body').innerHTML = html; document.body.classList.add('sheet-open'); }
    function closeSheet() { document.body.classList.remove('sheet-open'); }
    $('#sheet-bg').addEventListener('click', closeSheet);

    const accountOptions = (sel, exclude) => state.refs.accounts.filter(a => a.id !== exclude)
        .map(a => `<option value="${a.id}" ${a.id === sel ? 'selected' : ''}>${esc(a.name)}</option>`).join('');
    const dateField = (v) => `<div class="field"><label>Дата</label><input type="date" name="date" value="${v || today()}" max="${today()}"></div>`;
    const amountField = (name = 'amount', label = 'Сума', value = '') =>
        `<div class="field" data-f="${name}"><label>${label}</label><input class="amount-input" name="${name}" inputmode="decimal" autocomplete="off" placeholder="0" value="${value}"></div>`;
    const commentField = (v = '', ph = 'Що купили / кому / за що') =>
        `<div class="field" data-f="comment"><label>Коментар</label><textarea name="comment" rows="2" placeholder="${ph}">${esc(v)}</textarea></div>`;
    const num = (v) => parseFloat(String(v || '').replace(/\s/g, '').replace(',', '.'));

    function form(el) {
        const data = {};
        $$('[name]', el).forEach(i => { if (i.value !== '') data[i.name] = i.value; });
        if (data.amount) data.amount = num(data.amount);
        if (data.actual) data.actual = num(data.actual);
        $$('.chips', el).forEach(c => { const on = $('.chip.on', c); if (on) data[c.dataset.name] = on.dataset.v; });
        return data;
    }

    function showErrors(el, errors) {
        $$('.err', el).forEach(e => e.remove());
        Object.entries(errors || {}).forEach(([k, msgs]) => {
            const f = $(`[data-f="${k}"]`, el) || $('.save', el).parentElement;
            const e = document.createElement('div'); e.className = 'err'; e.textContent = msgs[0];
            f === $('.save', el).parentElement ? $('.save', el).before(e) : f.append(e);
        });
    }

    function chips(name, list, selected) {
        return `<div class="chips" data-name="${name}">` + list.map(c =>
            `<button type="button" class="chip ${c.name === selected ? 'on' : ''}" data-v="${esc(c.name)}">${esc(c.name)}${c.hint ? `<small>${esc(c.hint)}</small>` : ''}</button>`).join('') + '</div>';
    }
    function bindChips(el, onChange) {
        $$('.chips', el).forEach(box => box.addEventListener('click', ev => {
            const b = ev.target.closest('.chip'); if (!b) return;
            $$('.chip', box).forEach(x => x.classList.toggle('on', x === b));
            onChange && onChange(b.dataset.v);
        }));
    }

    // + Прихід / − Витрата
    function openRecord(kind) {
        const idem = uid();
        const cats = kind === 'income' ? state.refs.income : state.refs.expense;
        openSheet(`<form id="f" onsubmit="return false">
            <h2>${kind === 'income' ? '+ Прихід' : '− Витрата'}</h2>
            <div data-f="amount-wrap">${amountField()}</div>
            <div class="field" data-f="category"><label>Стаття</label>${chips('category', cats, kind === 'income' ? 'Оплата клієнта' : null)}</div>
            <div id="extra"></div>
            ${commentField('', kind === 'income' ? 'Від кого / за що' : 'Що купили / кому / за що')}
            <div class="row2">
                <div class="field" data-f="account_id"><label>Рахунок</label><select name="account_id">${accountOptions(state.account)}</select></div>
                <div data-f="date">${dateField()}</div>
            </div>
            <div><button class="save ${kind === 'expense' ? 'red' : ''}">Записати</button></div>
        </form>`);
        const el = $('#f');
        const extra = (cat) => {
            const x = $('#extra', el); x.innerHTML = '';
            $('[data-f="amount-wrap"]', el).hidden = false;
            if (cat === 'Виплата ЗП') {
                x.innerHTML = `<div class="field" data-f="employee_id"><label>Співробітник</label><select name="employee_id"><option value="">— оберіть —</option>` +
                    state.refs.employees.map(e => `<option value="${e.id}">${esc(e.name)}${e.debt > 0 ? ' — винні ' + money(e.debt) : ''}</option>`).join('') +
                    `</select><div class="hint">У P&L ЗП вже є з табелю — тут лише гаситься борг.</div></div>`;
            } else if (cat === 'Закупівля') {
                $('[data-f="amount-wrap"]', el).hidden = true;
                $('[name=amount]', el).value = '';
                x.innerHTML = state.refs.invoices.length
                    ? `<div class="field" data-f="stock_document_id"><label>Неоплачена накладна</label><select name="stock_document_id"><option value="">— оберіть —</option>` +
                      state.refs.invoices.map(i => `<option value="${i.id}">${esc(i.label)} · ${money(i.amount)}</option>`).join('') +
                      `</select><div class="hint">Накладна стане оплаченою, сума — з накладної.</div></div>`
                    : `<div class="hint">Неоплачених накладних немає. Дрібна покупка без накладної — стаття «Закупівля без накладної».</div>`;
            } else if (cat === 'Оплата клієнта') {
                x.innerHTML = `<div class="field" data-f="order_id"><label>Замовлення клієнта</label>
                    <input type="search" id="order-q" placeholder="Імʼя, телефон або № замовлення" autocomplete="off">
                    <input type="hidden" name="order_id"><div class="results" id="order-res" hidden></div>
                    <div class="hint">Гроші зарахуються на баланс клієнта.</div></div>`;
                bindOrderSearch(el);
            } else {
                const c = state.refs.expense.find(c => c.name === cat);
                if (c && c.hint) x.innerHTML = `<div class="hint">${esc(c.hint)}</div>`;
                else if (kind === 'expense' && cat) x.innerHTML = `<div class="hint">Піде в P&L рядком «Витрати з каси».</div>`;
            }
        };
        bindChips(el, extra);
        extra(kind === 'income' ? 'Оплата клієнта' : null);
        $('.save', el).addEventListener('click', () => {
            const d = form(el);
            submitWith(el, 'POST', '/cash-app/ops', idem, Object.assign(d, { kind }));
        });
        setTimeout(() => $('[name=amount]', el).focus(), 250);
    }

    async function submitWith(el, method, url, idem, data) {
        const btn = $('.save', el); btn.disabled = true;
        try {
            const r = await api(method, url, data, idem);
            closeSheet(); toast(r.duplicate ? 'Вже записано раніше' : (r.message || 'Збережено'));
            await refresh(true);
        } catch (e) {
            if (e.errors) { showErrors(el, e.errors); toast(e.message || 'Перевірте поля', true); }
            else toast(e.message || 'Помилка', true);
        } finally { btn.disabled = false; }
    }

    function bindOrderSearch(el) {
        const q = $('#order-q', el), res = $('#order-res', el), hidden = $('[name=order_id]', el);
        let t, seq = 0;
        q.addEventListener('input', () => {
            clearTimeout(t); hidden.value = '';
            t = setTimeout(async () => {
                if (q.value.trim().length < 2) { res.hidden = true; return; }
                const my = ++seq;
                try {
                    const r = await api('GET', '/cash-app/orders?q=' + encodeURIComponent(q.value.trim()));
                    // Запізніла відповідь на старий запит або замовлення вже обране — не перемальовуємо.
                    if (my !== seq || hidden.value) return;
                    res.hidden = false;
                    res.innerHTML = r.items.length ? r.items.map(i => `<button type="button" data-id="${i.id}">${esc(i.label)}</button>`).join('')
                        : '<div class="hint" style="padding:10px">Нічого не знайдено</div>';
                } catch (e) { toast(e.message, true); }
            }, 300);
        });
        res.addEventListener('click', ev => {
            const b = ev.target.closest('button'); if (!b) return;
            seq++;
            hidden.value = b.dataset.id; q.value = b.textContent; res.hidden = true;
        });
    }

    // ⇄ Переказ
    function openTransfer() {
        const idem = uid();
        const other = state.refs.accounts.find(a => a.id !== state.account);
        openSheet(`<form id="f" onsubmit="return false">
            <h2>⇄ Переказ між рахунками</h2>
            ${amountField()}
            <div class="row2">
                <div class="field" data-f="account_id"><label>Звідки</label><select name="account_id">${accountOptions(state.account)}</select></div>
                <div class="field" data-f="to_account_id"><label>Куди</label><select name="to_account_id">${accountOptions(other ? other.id : null)}</select></div>
            </div>
            <div class="hint">Напр., здали готівку на картку. Не витрата — у звіти не йде.</div>
            ${commentField('', 'Необовʼязково')}
            <div data-f="date">${dateField()}</div>
            <div><button class="save">Записати переказ</button></div>
        </form>`);
        const el = $('#f');
        $('.save', el).addEventListener('click', () => submitWith(el, 'POST', '/cash-app/transfer', idem, form(el)));
        setTimeout(() => $('[name=amount]', el).focus(), 250);
    }

    // ⚖ Перерахунок
    function openRecount() {
        const idem = uid();
        openSheet(`<form id="f" onsubmit="return false">
            <h2>⚖ Перерахунок</h2>
            <div class="hint">Порахуйте гроші й впишіть, скільки фактично. Різниця запишеться окремою операцією — у звіти не йде.</div>
            <div class="field" data-f="account_id"><label>Рахунок</label><select name="account_id">${accountOptions(state.account)}</select></div>
            ${amountField('actual', 'Фактично на руках')}
            <div class="hint" id="diff">У CRM зараз ${money(state.balance)}</div>
            ${commentField('', 'Необовʼязково')}
            <div data-f="date">${dateField()}</div>
            <div><button class="save">Записати перерахунок</button></div>
        </form>`);
        const el = $('#f');
        $('[name=actual]', el).addEventListener('input', (ev) => {
            const v = num(ev.target.value);
            if (isNaN(v)) { $('#diff').textContent = 'У CRM зараз ' + money(state.balance); return; }
            const d = Math.round((v - state.balance) * 100) / 100;
            $('#diff').innerHTML = `У CRM ${money(state.balance)} → різниця <b class="${d >= 0 ? 'plus' : 'minus'}">${signed(d)}</b>`;
        });
        $('.save', el).addEventListener('click', () => submitWith(el, 'POST', '/cash-app/recount', idem, form(el)));
        setTimeout(() => $('[name=actual]', el).focus(), 250);
    }

    // Деталі операції
    function openOp(id) {
        const o = state.ops.find(x => x.id === id); if (!o) return;
        openSheet(`<div id="f">
            <h2>${esc(o.category || 'Операція')}</h2>
            <div class="big ${o.non_flow ? 'neutral' : o.amount > 0 ? 'plus' : 'minus'}">${signed(o.amount)}</div>
            <dl class="kv">
                <dt>Дата</dt><dd>${esc(new Date(o.date + 'T12:00:00').toLocaleDateString('uk-UA'))}</dd>
                ${o.details && o.details !== o.comment ? `<dt>Кому / за що</dt><dd>${esc(o.details)}</dd>` : ''}
                ${o.comment ? `<dt>Коментар</dt><dd>${esc(o.comment)}</dd>` : ''}
                <dt>Вніс</dt><dd>${esc(o.user || '—')}</dd>
                ${o.balance !== null ? `<dt>Залишок після</dt><dd>${money(o.balance)}</dd>` : ''}
            </dl>
            ${o.can_edit ? '<button class="ghost" id="edit">Змінити</button>' : ''}
            <button class="ghost" id="hist">Історія змін</button>
            <div id="hist-box"></div>
            ${o.can_delete ? '<button class="ghost danger" id="del">Видалити</button>' : ''}
            ${!o.can_edit ? '<div class="hint" style="text-align:center;margin-top:10px">Змінити може адмін (минулі дні, накладні й перекази — лише видалити).</div>' : ''}
        </div>`);
        $('#hist').addEventListener('click', () => loadHistory(o));
        o.can_edit && $('#edit').addEventListener('click', () => openEdit(o));
        o.can_delete && $('#del').addEventListener('click', async () => {
            if (!confirm(o.category === 'Переказ між рахунками' ? 'Видалити переказ з обох рахунків?' : 'Видалити операцію? Сума повернеться в залишок.')) return;
            try { const r = await api('DELETE', '/cash-app/ops/' + o.id); closeSheet(); toast(r.message); await refresh(true); }
            catch (e) { toast(e.message, true); }
        });
    }

    async function loadHistory(o) {
        const box = $('#hist-box'); box.innerHTML = '<div class="hint">Завантажую…</div>';
        try {
            const r = await api('GET', `/cash-app/ops/${o.id}/history`);
            box.innerHTML = r.items.length ? r.items.map(h => `<div class="hist"><b>${esc(h.event)} · ${esc(h.at)} · ${esc(h.who)}</b>` +
                h.changes.map(c => `<div class="ch"><span>${esc(c.label)}</span><span>${c.old !== '—' ? `<span class="old">${esc(c.old)}</span> → ` : ''}<span class="new">${esc(c.new)}</span></span></div>`).join('') + '</div>').join('')
                : `<div class="hint">Історії немає — операцію внесено до того, як її почали записувати${r.created_by ? ' (вніс ' + esc(r.created_by) + ')' : ''}.</div>`;
        } catch (e) { box.innerHTML = ''; toast(e.message, true); }
    }

    function openEdit(o) {
        const cats = o.categories.map(n => ({ name: n }));
        openSheet(`<form id="f" onsubmit="return false">
            <h2>Змінити операцію</h2>
            ${amountField('amount', 'Сума', Math.abs(o.amount))}
            ${cats.length ? `<div class="field" data-f="category"><label>Стаття</label>${chips('category', cats, o.category)}</div>`
                : `<div class="hint">Стаття: ${esc(o.category)} (не змінюється)</div>`}
            ${commentField(o.comment || '', '')}
            <div data-f="date">${dateField(o.date)}</div>
            <div><button class="save">Зберегти</button></div>
        </form>`);
        const el = $('#f');
        bindChips(el);
        $('.save', el).addEventListener('click', () => submitWith(el, 'PATCH', '/cash-app/ops/' + o.id, null, form(el)));
    }

    // ---------- події ----------
    async function refresh(withRefs = false) {
        try {
            if (withRefs || !state.refs) await loadRefs();
            await loadData();
        } catch (e) { if (!e.offline) toast(e.message, true); }
    }
    function localStorageGet(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
    function localStorageSet(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }

    $('#account').addEventListener('change', (e) => { state.account = Number(e.target.value); localStorageSet('cash-account', state.account); loadData().catch(err => toast(err.message, true)); });
    $('#reload').addEventListener('click', () => refresh(true).then(() => toast('Оновлено')));
    $('#more').addEventListener('click', () => loadData(true).catch(e => toast(e.message, true)));
    $('#ops').addEventListener('click', (e) => { const b = e.target.closest('[data-op]'); if (b) openOp(Number(b.dataset.op)); });
    $$('[data-open]').forEach(b => b.addEventListener('click', () => {
        if (!state.refs) return toast('Ще завантажую…', true);
        ({ income: () => openRecord('income'), expense: () => openRecord('expense'), transfer: openTransfer, recount: openRecount })[b.dataset.open]();
    }));
    const net = () => document.body.classList.toggle('is-offline', !navigator.onLine);
    addEventListener('online', () => { net(); refresh(); });
    addEventListener('offline', net);
    document.addEventListener('visibilitychange', () => { if (!document.hidden && navigator.onLine) refresh(); });
    net();

    // Встановлення на телефон
    const standalone = matchMedia('(display-mode: standalone)').matches || navigator.standalone;
    if (!standalone) {
        const box = $('#install');
        if (/iphone|ipad/i.test(navigator.userAgent)) {
            box.innerHTML = 'Щоб додати на екран: Safari → «Поділитися» → «На екран “Додому”».';
            box.style.display = 'block';
        }
        addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            box.innerHTML = '<button class="ghost" style="margin:0">📲 Встановити «Касу» на телефон</button>';
            box.style.display = 'block';
            $('button', box).addEventListener('click', () => { e.prompt(); box.style.display = 'none'; });
        });
    }
    if ('serviceWorker' in navigator) navigator.serviceWorker.register('/cash-app/sw.js', { scope: '/cash-app' }).catch(() => {});

    refresh(true);
})();
</script>
</body>
</html>
