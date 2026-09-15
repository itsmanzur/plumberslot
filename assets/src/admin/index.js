import { h, render } from 'preact';

import '../shared/tokens.css';
import '../shared/components.css';
import './app.css';
import './docs.css';

import { App } from './App';

function mount() {
	const target =
		document.getElementById( 'plumberslot-admin-root' ) ||
		document.getElementById( 'plumberslot-setup-root' );

	if ( ! target ) {
		return;
	}

	window.performance?.mark?.( 'plumberslot-admin-mount-start' );
	const screen = target.dataset.screen || 'plumberslot';
	render( h( App, { screen } ), target );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', mount );
} else {
	mount();
}
