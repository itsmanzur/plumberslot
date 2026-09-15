import { h, render } from 'preact';

import '../shared/tokens.css';
import '../shared/components.css';
import './app.css';
import './docs.css';

import { App } from './App';

function mount() {
	const target =
		document.getElementById( 'tutorslot-admin-root' ) ||
		document.getElementById( 'tutorslot-setup-root' );

	if ( ! target ) {
		return;
	}

	window.performance?.mark?.( 'tutorslot-admin-mount-start' );
	const screen = target.dataset.screen || 'tutorslot';
	render( h( App, { screen } ), target );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', mount );
} else {
	mount();
}
