/*
 | Сторінка обраного для гостя.
 |
 | Список лежить у localStorage, тому сервер не може відрендерити його разом зі
 | сторінкою — вона добирає картки одним запитом за id. Альтернатива (віддати
 | весь каталог і сховати зайве) росте разом із каталогом і не потрібна нікому.
 */
export default function favouritesPage() {
    return {
        loading: true,
        empty: false,
        html: '',

        async load() {
            const ids = this.$store.favourites.ids;

            if (!ids.length) {
                this.loading = false;
                this.empty = true;

                return;
            }

            try {
                const response = await fetch(`/favourites/items?ids=${ids.join(',')}`, {
                    headers: { Accept: 'text/html' },
                });
                this.html = await response.text();
            } catch {
                this.empty = true;
            }

            this.loading = false;
        },
    };
}
