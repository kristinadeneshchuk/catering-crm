/*
 | Наскрізний стан оренди. Живе в localStorage, бо клієнт заходить з категорії,
 | йде на PDP, повертається — дати й філія мають лишитися.
 |
 | city    → впливає на телефони, філії та доставку на всіх сторінках
 | branch  → дефолт: найближча філія обраного міста
 | from/to → діапазон оренди, прокидається через URL між лістингом і PDP
 | cart    → позиції з власними датами й філією
 */
const KEY = 'bur:booking:v1';

const load = () => {
    try {
        return JSON.parse(localStorage.getItem(KEY)) ?? {};
    } catch {
        return {};
    }
};

export default function booking() {
    const saved = load();

    return {
        city: saved.city ?? 'kyiv',
        branch: saved.branch ?? null,
        from: saved.from ?? null,
        to: saved.to ?? null,
        cart: saved.cart ?? [],
        compare: saved.compare ?? [],
        drawerOpen: false,
        toast: null,

        init() {
            // Дати з URL мають пріоритет над збереженими — це шаринг посилання.
            const q = new URLSearchParams(location.search);
            if (q.get('from')) this.from = q.get('from');
            if (q.get('to')) this.to = q.get('to');
            if (q.get('branch')) this.branch = q.get('branch');
            this.persist();
        },

        persist() {
            try {
                localStorage.setItem(
                    KEY,
                    JSON.stringify({
                        city: this.city,
                        branch: this.branch,
                        from: this.from,
                        to: this.to,
                        cart: this.cart,
                        compare: this.compare,
                    }),
                );
            } catch {
                /* приватний режим — працюємо без збереження */
            }
        },

        get count() {
            return this.cart.reduce((n, i) => n + i.qty, 0);
        },

        get total() {
            return this.cart.reduce((s, i) => s + i.price * i.days * i.qty, 0);
        },

        get deposit() {
            return this.cart.reduce((s, i) => s + (i.deposit ?? 0) * i.qty, 0);
        },

        add(item) {
            const same = this.cart.find((i) => i.id === item.id && i.from === item.from);
            if (same) {
                same.qty += 1;
            } else {
                this.cart.push({ qty: 1, ...item });
            }
            this.persist();
            // Кошик виїжджає збоку, а не редіректить — вимога handoff.
            this.drawerOpen = true;
            this.flash('Додано в кошик');
        },

        remove(index) {
            this.cart.splice(index, 1);
            this.persist();
        },

        setQty(index, qty) {
            this.cart[index].qty = Math.max(1, qty);
            this.persist();
        },

        toggleCompare(id) {
            const at = this.compare.indexOf(id);
            at === -1 ? this.compare.push(id) : this.compare.splice(at, 1);
            this.persist();
        },

        inCompare(id) {
            return this.compare.includes(id);
        },

        flash(text) {
            this.toast = text;
            setTimeout(() => (this.toast = null), 3000);
        },
    };
}
