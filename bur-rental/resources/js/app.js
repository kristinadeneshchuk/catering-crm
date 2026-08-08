import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';

import booking from './stores/booking';
import pdp from './components/pdp';
import calendar from './components/calendar';
import filters from './components/filters';
import bookingForm from './components/booking-form';

// Стан, який мусить пережити перезавантаження: місто, філія, дати, кошик.
Alpine.plugin(collapse);

Alpine.store('booking', booking());

Alpine.data('pdp', pdp);
Alpine.data('calendar', calendar);
Alpine.data('filters', filters);
Alpine.data('bookingForm', bookingForm);

window.Alpine = Alpine;
Alpine.start();
