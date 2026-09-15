import { h } from 'preact';
import { DashboardScreen } from './screens/DashboardScreen';
import { AvailabilityScreen } from './screens/AvailabilityScreen';
import { BookingsScreen } from './screens/BookingsScreen';
import { SubjectsScreen } from './screens/SubjectsScreen';
import { TutorsScreen } from './screens/TutorsScreen';
import { SettingsScreen } from './screens/SettingsScreen';
import { SetupWizardScreen } from './screens/SetupWizardScreen';
import { HelpDocsScreen } from './screens/HelpDocsScreen';
import { DesignSystemDemo } from '../shared/DesignSystemDemo';

const ROUTES = {
	plumberslot: DashboardScreen,
	'plumberslot-availability': AvailabilityScreen,
	'plumberslot-subjects': SubjectsScreen,
	'plumberslot-bookings': BookingsScreen,
	'plumberslot-tutors': TutorsScreen,
	'plumberslot-settings': SettingsScreen,
	'plumberslot-help': HelpDocsScreen,
	'plumberslot-setup': SetupWizardScreen,
	'plumberslot-design-system': DesignSystemDemo,
};

/**
 * Map WP admin page slug → screen component.
 *
 * @param {string} screen Admin page slug from data-screen.
 */
export function resolveScreen( screen ) {
	return ROUTES[ screen ] || DashboardScreen;
}

export function App( { screen } ) {
	const Screen = resolveScreen( screen );

	return h(
		'div',
		{
			class: 'plumberslot-admin plumberslot-root ts-admin',
			'data-plumberslot-screen': screen,
		},
		h( Screen )
	);
}

/** Reference screens for exit-gate / visual harness. */
export const ADMIN_SCREENS = [
	'plumberslot',
	'plumberslot-availability',
	'plumberslot-subjects',
	'plumberslot-bookings',
	'plumberslot-tutors',
	'plumberslot-setup',
	'plumberslot-settings',
	'plumberslot-help',
];
