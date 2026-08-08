/*
 | Фільтри лістингу.
 |
 | Застосування — по кнопці з лічильником, а не по кожному кліку: на мобільному
 | кожен автозапит перемальовує екран під пальцем. Стан серіалізується в URL —
 | посилання з фільтрами має відкриватися і в краулера, і в людини з месенджера.
 */
export default function filters({ applied = {}, total = 0 }) {
    return {
        open: false, // мобільний bottom sheet / вузький desktop
        draft: structuredClone(applied),
        applied,
        total,

        get dirtyCount() {
            return Object.values(this.draft).flat().filter(Boolean).length;
        },

        get appliedChips() {
            return Object.entries(this.applied).flatMap(([key, value]) =>
                (Array.isArray(value) ? value : [value])
                    .filter(Boolean)
                    .map((v) => ({ key, value: v, label: this.label(key, v) })),
            );
        },

        label(key, value) {
            const names = {
                brand: 'Бренд',
                branch: 'Філія',
                price: 'Ціна',
                power: 'Потужність',
                energy: 'Енергія удару',
                weight: 'Вага',
                free: 'Тільки вільні',
            };
            return `${names[key] ?? key}: ${value}`;
        },

        toggle(key, value) {
            const list = (this.draft[key] ??= []);
            const at = list.indexOf(value);
            at === -1 ? list.push(value) : list.splice(at, 1);
        },

        checked(key, value) {
            return (this.draft[key] ?? []).includes(value);
        },

        apply() {
            const q = new URLSearchParams(location.search);
            Object.keys(this.draft).forEach((k) => q.delete(k));
            Object.entries(this.draft).forEach(([k, v]) => {
                (Array.isArray(v) ? v : [v]).filter(Boolean).forEach((x) => q.append(k, x));
            });
            q.delete('page');
            location.search = q.toString();
        },

        removeChip(chip) {
            const q = new URLSearchParams(location.search);
            const rest = q.getAll(chip.key).filter((v) => v !== chip.value);
            q.delete(chip.key);
            rest.forEach((v) => q.append(chip.key, v));
            location.search = q.toString();
        },

        reset() {
            const q = new URLSearchParams(location.search);
            Object.keys(this.applied).forEach((k) => q.delete(k));
            location.search = q.toString();
        },
    };
}
