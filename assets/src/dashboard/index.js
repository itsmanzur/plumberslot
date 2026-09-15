import { h, render } from 'preact';

import '../shared/tokens.css';
import '../shared/components.css';
import './app.css';

import { TechnicianDashboard } from './TechnicianDashboard';

document.querySelectorAll( '.plumberslot-dashboard' ).forEach( ( target ) => {
	render( h( TechnicianDashboard, null ), target );
} );
