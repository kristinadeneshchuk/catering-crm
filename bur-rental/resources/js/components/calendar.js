/*
 | Календар доступності. Показує правду про наявність — це головна перевага
 | сервісу, тому зайняті дати клікнути не можна, а не «можна, а потім передзвонимо».
 |
 | Приймає: start (ISO), busy (масив ISO), days, minDays/maxDays.
 | Віддає: сітку місяця з семантичним станом кожної комірки.
 */
export const WEEKDAYS = ['пн', 'вт', 'ср', 'чт', 'пт', 'сб', 'нд'];

export const MONTHS = [
    'січня', 'лютого', 'березня', 'квітня', 'травня', 'червня',
    'липня', 'серпня', 'вересня', 'жовтня', 'листопада', 'грудня',
];

export const MONTHS_NOM = [
    'Січень', 'Лютий', 'Березень', 'Квітень', 'Травень', 'Червень',
    'Липень', 'Серпень', 'Вересень', 'Жовтень', 'Листопад', 'Грудень',
];

export const iso = (d) =>
    `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

export const addDays = (isoDate, n) => {
    const d = new Date(isoDate + 'T00:00:00');
    d.setDate(d.getDate() + n);
    return iso(d);
};

export const short = (isoDate) => {
    const [, m, d] = isoDate.split('-');
    return `${d}.${m}`;
};

/** Схиляє «день / дні / днів» — числа тут головний контент, помилка помітна. */
export const daysWord = (n) => {
    const t = n % 10;
    const h = n % 100;
    if (t === 1 && h !== 11) return 'день';
    if (t >= 2 && t <= 4 && (h < 10 || h > 20)) return 'дні';
    return 'днів';
};

/** Будує сітку одного місяця з підсвіткою діапазону і зайнятих дат. */
export function monthGrid({ year, month, from, to, busy = [], today = null }) {
    const cells = [];
    const offset = (new Date(year, month, 1).getDay() + 6) % 7; // тиждень з понеділка
    const total = new Date(year, month + 1, 0).getDate();

    for (let i = 0; i < offset; i++) cells.push({ blank: true });

    for (let d = 1; d <= total; d++) {
        const date = iso(new Date(year, month, d));
        const inRange = from && to && date >= from && date <= to;

        cells.push({
            blank: false,
            day: d,
            date,
            busy: busy.includes(date),
            past: today ? date < today : false,
            today: date === today,
            inRange,
            isStart: date === from,
            isEnd: date === to,
        });
    }

    return cells;
}

/** Чи перетинається обраний діапазон із зайнятими датами. */
export function hasConflict(from, to, busy) {
    return busy.some((d) => d >= from && d <= to);
}

/** Найближча дата, з якої модель вільна підряд на потрібну кількість днів. */
export function firstFreeFrom(start, days, busy) {
    let cursor = start;
    for (let guard = 0; guard < 180; guard++) {
        const end = addDays(cursor, days - 1);
        if (!hasConflict(cursor, end, busy)) return cursor;
        cursor = addDays(cursor, 1);
    }
    return null;
}

export default function calendar({ from, to, busy = [], today = null, months = 1 }) {
    return {
        from,
        to,
        busy,
        today,
        cursor: new Date((from ?? today ?? iso(new Date())) + 'T00:00:00'),

        get title() {
            return `${MONTHS_NOM[this.cursor.getMonth()]} ${this.cursor.getFullYear()}`;
        },

        get grid() {
            return monthGrid({
                year: this.cursor.getFullYear(),
                month: this.cursor.getMonth(),
                from: this.from,
                to: this.to,
                busy: this.busy,
                today: this.today,
            });
        },

        get weekdays() {
            return WEEKDAYS;
        },

        get months() {
            return months;
        },

        shift(n) {
            const d = new Date(this.cursor);
            d.setMonth(d.getMonth() + n);
            this.cursor = d;
        },

        pick(cell) {
            if (cell.blank || cell.busy || cell.past) return;
            if (!this.from || this.to) {
                this.from = cell.date;
                this.to = null;
            } else if (cell.date < this.from) {
                this.from = cell.date;
            } else {
                this.to = cell.date;
            }
            this.$dispatch('range-changed', { from: this.from, to: this.to });
        },

        cellClass(cell) {
            if (cell.blank) return '';
            if (cell.past) return 'text-border-2 line-through cursor-not-allowed';
            if (cell.busy)
                return 'bg-danger-bg text-danger-text line-through cursor-not-allowed';
            if (cell.isStart || cell.isEnd) return 'bg-brand text-white font-semibold';
            if (cell.inRange) return 'bg-brand-tint text-brand';
            if (cell.today) return 'border border-brand hover:bg-surface-1';
            return 'hover:bg-surface-1';
        },
    };
}
