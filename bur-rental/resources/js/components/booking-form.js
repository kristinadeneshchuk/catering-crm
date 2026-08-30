/*
 | Бронювання одним екраном: три секції розкриваються послідовно.
 | Багатосторінковий чекаут тут коштував би конверсії — половина трафіку
 | оформлює замовлення з телефона, стоячи на об'єкті.
 */
export default function bookingForm({ zones = [], deposit = 0, discountPercent = 0, client = null }) {
    return {
        step: 1,
        zones,
        deposit,

        // Відсоток приходить із сервера і тут тільки показується. Порахувати
        // його в браузері означало б дозволити правити знижку в devtools —
        // остаточну суму все одно рахує BookingController.
        discountPercent,

        clientType: 'person', // person | company
        pickup: 'self', // self | delivery
        payment: 'card',
        depositWay: 'card-hold',

        // Дані залогіненого клієнта підставляються одразу: набирати телефон
        // і ЄДРПОУ вдруге — найдурніша причина кинути оформлення.
        phone: client?.phone ?? '',
        name: client?.name ?? '',
        company: client?.company ?? '',
        edrpou: client?.edrpou ?? '',
        email: client?.email ?? '',
        zone: zones[0]?.slug ?? null,
        address: '',
        errors: {},

        /** Маска +380 __ ___ __ __ — вводять у рукавичках, форма не має заважати. */
        maskPhone() {
            const digits = this.phone.replace(/\D/g, '').replace(/^380/, '').slice(0, 9);
            const p = [digits.slice(0, 2), digits.slice(2, 5), digits.slice(5, 7), digits.slice(7, 9)];
            this.phone = '+380 ' + p.filter(Boolean).join(' ');
        },

        get deliveryPrice() {
            if (this.pickup === 'self') return 0;
            return this.zones.find((z) => z.slug === this.zone)?.price ?? 0;
        },

        /** Знижка діє тільки на оренду — так само, як на сервері. */
        get discountAmount() {
            return Math.floor((this.$store.booking.total * this.discountPercent) / 100);
        },

        get payable() {
            return (
                this.$store.booking.total -
                this.discountAmount +
                this.$store.booking.deposit +
                this.deliveryPrice
            );
        },

        validate(section) {
            const e = {};

            if (section >= 2) {
                if (this.phone.replace(/\D/g, '').length !== 12) e.phone = 'Введіть номер повністю';
                if (this.clientType === 'person' && !this.name.trim()) e.name = 'Як до вас звертатись?';
                if (this.clientType === 'company') {
                    if (!this.company.trim()) e.company = 'Назва компанії';
                    if (!/^\d{8}$/.test(this.edrpou)) e.edrpou = 'ЄДРПОУ — 8 цифр';
                    if (!/^\S+@\S+\.\S+$/.test(this.email)) e.email = 'Email для рахунку';
                }
            }

            if (section >= 3 && this.pickup === 'delivery' && !this.address.trim()) {
                e.address = 'Адреса доставки';
            }

            this.errors = e;
            return Object.keys(e).length === 0;
        },

        go(section) {
            // Назад можна завжди, вперед — тільки заповнивши поточну секцію.
            if (section <= this.step || this.validate(section - 1)) this.step = section;
        },

        submit(event) {
            if (!this.validate(3)) {
                event.preventDefault();
                this.step = Object.keys(this.errors).some((k) => k === 'address') ? 3 : 2;
            }
        },
    };
}
