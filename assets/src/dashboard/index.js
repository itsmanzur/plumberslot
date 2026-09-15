import { h, render } from 'preact';

import '../shared/tokens.css';
import '../shared/components.css';
import './app.css';

import { ParentDashboard } from './ParentDashboard';
import { TutorDashboard } from './TutorDashboard';

const boot =
	typeof window !== 'undefined' && window.plumberslotDashboard
		? window.plumberslotDashboard
		: { view: 'parent' };

document.querySelectorAll( '.plumberslot-dashboard' ).forEach( ( target ) => {
	const view = target.dataset.view || boot.view || 'parent';
	render(
		h( view === 'tutor' ? TutorDashboard : ParentDashboard, null ),
		target
	);
} );
