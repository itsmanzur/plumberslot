import { h, render } from 'preact';

import '../shared/tokens.css';
import '../shared/components.css';
import './app.css';
import './compat.css';

import { BookingApp } from './App';

document.querySelectorAll( '.plumberslot-widget' ).forEach( ( target ) => {
	render(
		h( BookingApp, {
			technicianId: Number.parseInt(
				target.dataset.technician || '0',
				10
			),
			serviceId: Number.parseInt( target.dataset.service || '0', 10 ),
			view: target.dataset.view || 'booking',
		} ),
		target
	);
} );
