/*
 | Обране.
 |
 | Працює без входу: серце тисне і гість, список живе в localStorage поруч із
 | кошиком. Вимагати реєстрацію заради закладки — найшвидший спосіб втратити і
 | закладку, і клієнта.
 |
 | Залогінений клієнт має той самий список, але на сервері: він мусить бути
 | однаковий на телефоні й ноутбуку. При вході гостьовий список не заміщується,
 | а зливається — жоден із двох не має права зникнути.
 */
const KEY = 'bur:favourites:v1';

const load = () => {
    try {
        return JSON.parse(localStorage.getItem(KEY)) ?? [];
    } catch {
        return [];
    }
};

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

export default function favourites(authenticated = false, serverIds = []) {
    return {
        ids: authenticated ? serverIds : load(),
        authenticated,

        init() {
            if (this.authenticated) {
                this.merge();
            }
        },

        persist() {
            try {
                localStorage.setItem(KEY, JSON.stringify(this.ids));
            } catch {
                /* приватний режим — працюємо без збереження */
            }
        },

        has(id) {
            return this.ids.includes(id);
        },

        get count() {
            return this.ids.length;
        },

        async toggle(id) {
            const at = this.ids.indexOf(id);
            const adding = at === -1;

            // Спершу малюємо, потім зберігаємо: серце має відгукнутися одразу,
            // а не через круговий шлях до сервера.
            adding ? this.ids.push(id) : this.ids.splice(at, 1);
            this.persist();

            if (!this.authenticated) {
                return;
            }

            try {
                await fetch(`/favourites/${id}`, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' },
                });
            } catch {
                /* мережа впала — список лишається в браузері, зіллється при вході */
            }
        },

        /** Злиття гостьового списку з серверним одразу після входу. */
        async merge() {
            const guest = load();

            if (!guest.length) {
                return;
            }

            try {
                const response = await fetch('/favourites-sync', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': csrf(),
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                    },
                    body: JSON.stringify({ ids: guest }),
                });

                const data = await response.json();
                this.ids = data.saved ?? this.ids;

                // Гостьовий список більше не потрібен: далі головний — сервер.
                localStorage.removeItem(KEY);
            } catch {
                /* спробуємо наступного разу */
            }
        },
    };
}
