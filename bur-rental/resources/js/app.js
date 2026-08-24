import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';

import booking from './stores/booking';
import favourites from './stores/favourites';
import pdp from './components/pdp';
import calendar from './components/calendar';
import filters from './components/filters';
import bookingForm from './components/booking-form';
import favouritesPage from './components/favourites-page';

// Стан, який мусить пережити перезавантаження: місто, філія, дати, кошик.
Alpine.plugin(collapse);

Alpine.store('booking', booking());

// Обране: у гостя — localStorage, у залогіненого — сервер. Стартові дані
// кладе layout, бо тільки він знає, чи є сесія.
Alpine.store('favourites', favourites(window.burFavourites?.authenticated, window.burFavourites?.ids));

Alpine.data('pdp', pdp);
Alpine.data('calendar', calendar);
Alpine.data('filters', filters);
Alpine.data('bookingForm', bookingForm);
Alpine.data('favouritesPage', favouritesPage);

window.Alpine = Alpine;
Alpine.start();
