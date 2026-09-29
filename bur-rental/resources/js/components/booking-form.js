/*
 | Бронювання одним екраном: три секції розкриваються послідовно.
 | Багатосторінковий чекаут тут коштував би конверсії — половина трафіку
 | оформлює замовлення з телефона, стоячи на об'єкті.
 */
export default function bookingForm({ zones = [], weights = {}, rules = {}, deposit = 0, discountPercent = 0, client = null }) {
    return {
        step: 1,
        zones,
        weights,
        rules,
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
        // id, а не slug: саме id іде значенням у <select> і на сервер.
        zone: zones[0] ? String(zones[0].id) : null,
        address: '',
        errors: {},

        /** Маска +380 __ ___ __ __ — вводять у рукавичках, форма не має заважати. */
        maskPhone() {
            const digits = this.phone.replace(/\D/g, '').replace(/^380/, '').slice(0, 9);
            const p = [digits.slice(0, 2), digits.slice(2, 5), digits.slice(5, 7), digits.slice(7, 9)];
            this.phone = '+380 ' + p.filter(Boolean).join(' ');
        },

        weightOf(item) {
            return Number(this.weights[item.id] ?? 0);
        },

        /** Назви позицій, які самовивозом не видаються. Сервер перевіряє те саме. */
        get heavyInCart() {
            const heavy = this.$store.booking.cart.filter((i) => this.weightOf(i) >= this.rules.heavyKg);
            return [...new Set(heavy.map((i) => i.name))];
        },

        /*
         | Доставка — дзеркало RentalPricing::delivery(). Найважча позиція кошика
         | і найдовший строк: від heavyKg на freeDays+ днів — безкоштовно, інакше
         | тариф зони плюс гідроборт або окрема машина. Остаточну суму все одно
         | рахує сервер; тут головне, щоб клієнт до відправлення бачив ту саму.
         */
        get heaviest() {
            return Math.max(0, ...this.$store.booking.cart.map((i) => this.weightOf(i)));
        },

        get longestDays() {
            return Math.max(0, ...this.$store.booking.cart.map((i) => Number(i.days) || 0));
        },

        get deliveryZone() {
            return this.zones.find((z) => String(z.id) === String(this.zone)) ?? null;
        },

        get deliveryFree() {
            return this.heaviest >= this.rules.heavyKg && this.longestDays >= this.rules.freeDays;
        },

        get deliverySurcharge() {
            if (this.deliveryFree) return 0;
            if (this.heaviest >= this.rules.truckKg) return this.rules.truckFee;
            if (this.heaviest >= this.rules.heavyKg) return this.rules.hoistFee;
            return 0;
        },

        /** Зона «за домовленістю»: суму називає менеджер, у «До сплати» її немає. */
        get deliveryByQuote() {
            return this.deliveryZone?.price_mode === 'quote';
        },

        get deliveryPrice() {
            if (this.pickup === 'self' || !this.deliveryZone || this.deliveryByQuote || this.deliveryFree) return 0;
            return this.deliveryZone.price + this.deliverySurcharge;
        },

        /** Пояснення під сумою: чому безкоштовно, за що доплата або хто назве ціну. */
        get deliveryNote() {
            if (this.deliveryByQuote) return 'вартість назве менеджер, коли підтверджуватиме бронь';
            if (this.deliveryFree) return `безкоштовно: техніка від ${this.rules.heavyKg} кг на ${this.rules.freeDays}+ днів`;
            if (this.heaviest >= this.rules.truckKg) return `з них ${this.rules.truckFee} ₴ — окрема машина`;
            if (this.heaviest >= this.rules.heavyKg) return `з них ${this.rules.hoistFee} ₴ — гідроборт`;
            return '';
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
