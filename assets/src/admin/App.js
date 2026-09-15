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
	tutorslot: DashboardScreen,
	'tutorslot-availability': AvailabilityScreen,
	'tutorslot-subjects': SubjectsScreen,
	'tutorslot-bookings': BookingsScreen,
	'tutorslot-tutors': TutorsScreen,
	'tutorslot-settings': SettingsScreen,
	'tutorslot-help': HelpDocsScreen,
	'tutorslot-setup': SetupWizardScreen,
	'tutorslot-design-system': DesignSystemDemo,
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
			class: 'tutorslot-admin tutorslot-root ts-admin',
			'data-tutorslot-screen': screen,
		},
		h( Screen )
	);
}

/** Reference screens for exit-gate / visual harness. */
export const ADMIN_SCREENS = [
	'tutorslot',
	'tutorslot-availability',
	'tutorslot-subjects',
	'tutorslot-bookings',
	'tutorslot-tutors',
	'tutorslot-setup',
	'tutorslot-settings',
	'tutorslot-help',
];
