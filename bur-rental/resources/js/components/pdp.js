import { addDays, daysWord, firstFreeFrom, hasConflict, monthGrid, short, WEEKDAYS, MONTHS_NOM } from './calendar';

const fmt = (n) => n.toLocaleString('uk-UA').replace(/ /g, ' ');

/*
 | Конверсійне ядро картки товару.
 |
 | Один рух повзунка одночасно: перемикає активний рівень тарифної сходинки,
 | зсуває кінцеву дату в календарі і перераховує підсумок. Без кнопки
 | «Розрахувати» — вона тут була б визнанням, що ми не встигаємо порахувати.
 */
export default function pdp({ tiers, branches, extras, busy, deposit, today, from }) {
    return {
        tiers,
        branches,
        extras,
        deposit,
        today,
        from: from ?? today,
        days: 5,
        branchId: branches[0]?.id ?? null,
        picked: {},
        tab: 'specs',
        faq: null,
        cursor: new Date((from ?? today) + 'T00:00:00'),

        init() {
            const store = this.$store.booking;
            if (store.from) this.from = store.from;
            if (store.to && store.from) {
                const span = Math.round(
                    (new Date(store.to) - new Date(store.from)) / 86400000,
                ) + 1;
                if (span >= 1 && span <= 30) this.days = span;
            }
            if (store.branch && branches.some((b) => b.slug === store.branch)) {
                this.branchId = branches.find((b) => b.slug === store.branch).id;
            }
        },

        /* ——— строк ——— */

        get to() {
            return addDays(this.from, this.days - 1);
        },

        get daysLabel() {
            return `${this.days} ${daysWord(this.days)}`;
        },

        get rangeLabel() {
            return `${short(this.from)} — ${short(this.to)} · ${this.daysLabel}`;
        },

        setDays(n) {
            this.days = Math.min(30, Math.max(1, n));
            this.syncStore();
        },

        /** Заповнена частина треку повзунка — інакше вона сіра на всю ширину. */
        get trackStyle() {
            const pct = ((this.days - 1) / 29) * 100;
            return `--track: linear-gradient(to right, var(--color-brand) ${pct}%, var(--color-border-1) ${pct}%)`;
        },

        /* ——— тарифна сходинка ——— */

        get tier() {
            return this.tiers.find((t) => this.days >= t.min && this.days <= (t.max ?? 999));
        },

        isActiveTier(t) {
            return t.min === this.tier.min;
        },

        /** Висота стовпчика під плиткою: видно, що ціна падає зі строком. */
        barHeight(t) {
            const max = Math.max(...this.tiers.map((x) => x.price));
            return Math.round((t.price / max) * 34);
        },

        /* ——— доступність ——— */

        get busyDates() {
            return busy[this.branchId] ?? [];
        },

        get conflict() {
            return hasConflict(this.from, this.to, this.busyDates);
        },

        get freeFrom() {
            const d = firstFreeFrom(this.from, this.days, this.busyDates);
            return d ? short(d) : null;
        },

        /** Філія, де ці самі дати вільні — щоб не залишати клієнта в глухому куті. */
        get altBranch() {
            return this.branches.find(
                (b) => b.id !== this.branchId && !hasConflict(this.from, this.to, busy[b.id] ?? []),
            );
        },

        get grid() {
            return monthGrid({
                year: this.cursor.getFullYear(),
                month: this.cursor.getMonth(),
                from: this.from,
                to: this.to,
                busy: this.busyDates,
                today: this.today,
            });
        },

        get monthTitle() {
            return `${MONTHS_NOM[this.cursor.getMonth()]} ${this.cursor.getFullYear()}`;
        },

        get weekdays() {
            return WEEKDAYS;
        },

        shiftMonth(n) {
            const d = new Date(this.cursor);
            d.setMonth(d.getMonth() + n);
            this.cursor = d;
        },

        pickDate(cell) {
            if (cell.blank || cell.busy || cell.past) return;
            this.from = cell.date;
            this.syncStore();
        },

        cellClass(cell) {
            if (cell.past) return 'text-border-2 line-through cursor-not-allowed';
            if (cell.busy) return 'bg-danger-bg text-danger-text line-through cursor-not-allowed';
            if (cell.isStart) return 'bg-brand text-white font-semibold rounded-l-[6px]';
            if (cell.isEnd) return 'bg-brand text-white font-semibold rounded-r-[6px]';
            if (cell.inRange) return 'bg-brand-tint text-brand';
            if (cell.today) return 'ring-[1.5px] ring-brand ring-inset hover:bg-surface-1';
            return 'hover:bg-surface-1 cursor-pointer';
        },

        /* ——— філія ——— */

        get branch() {
            return this.branches.find((b) => b.id === this.branchId) ?? this.branches[0];
        },

        branchStatus(b) {
            return hasConflict(this.from, this.to, busy[b.id] ?? [])
                ? { ok: false, text: `зайнятий до ${short(this.freeFromFor(b))}` }
                : { ok: true, text: 'вільний на ці дати' };
        },

        freeFromFor(b) {
            return firstFreeFrom(this.from, this.days, busy[b.id] ?? []) ?? this.from;
        },

        pickBranch(b) {
            this.branchId = b.id;
            this.syncStore();
        },

        /* ——— витратники ——— */

        toggleExtra(id) {
            this.picked[id] = !this.picked[id];
        },

        get extrasSum() {
            return this.extras.reduce((s, x) => s + (this.picked[x.id] ? x.price : 0), 0);
        },

        /* ——— підсумок ——— */

        get rent() {
            return this.days * this.tier.price;
        },

        get rentLine() {
            return `${this.days} × ${this.tier.price} ₴ = ${fmt(this.rent)} ₴`;
        },

        get total() {
            return this.rent + this.extrasSum + this.deposit;
        },

        money(n) {
            return `${fmt(n)} ₴`;
        },

        syncStore() {
            const s = this.$store.booking;
            s.from = this.from;
            s.to = this.to;
            s.branch = this.branch?.slug ?? null;
            s.persist();
        },

        book(product) {
            this.$store.booking.add({
                id: product.id,
                slug: product.slug,
                name: product.name,
                brand: product.brand,
                price: this.tier.price,
                days: this.days,
                from: this.from,
                to: this.to,
                branch: this.branch?.name,
                deposit: this.deposit,
                extras: this.extras.filter((x) => this.picked[x.id]),
            });
        },
    };
}
