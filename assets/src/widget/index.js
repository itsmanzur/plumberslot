import { h, render } from 'preact';

import '../shared/tokens.css';
import '../shared/components.css';
import './app.css';
import './compat.css';

import { BookingApp } from './App';

document.querySelectorAll( '.plumberslot-widget' ).forEach( ( target ) => {
	render(
		h( BookingApp, {
			tutorId: Number.parseInt( target.dataset.tutor || '0', 10 ),
			subjectId: Number.parseInt( target.dataset.subject || '0', 10 ),
			view: target.dataset.view || 'booking',
		} ),
		target
	);
} );
