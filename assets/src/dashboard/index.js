import { h, render } from 'preact';

import '../shared/tokens.css';
import '../shared/components.css';
import './app.css';

import { TechnicianDashboard } from './TechnicianDashboard';
import { CustomerDashboard } from './CustomerDashboard';

document.querySelectorAll( '.plumberslot-dashboard' ).forEach( ( target ) => {
	const Dashboard =
		target.dataset.view === 'customer'
			? CustomerDashboard
			: TechnicianDashboard;
	render( h( Dashboard, null ), target );
} );
