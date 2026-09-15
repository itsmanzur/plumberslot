/**
 * Localized boot config from wp_localize_script.
 */

const boot =
	typeof window !== 'undefined' && window.tutorslotAdmin
		? window.tutorslotAdmin
		: {
				root: '/wp-json/tutorslot/v1',
				nonce: '',
				tutorId: 0,
				timezone: 'UTC',
				version: '0.1.0',
				initialDashboard: null,
				user: { id: 0, name: '' },
				caps: {
					manageOwn: true,
					manageTutors: false,
					manageAll: false,
					viewReports: false,
				},
				urls: { admin: '', home: '/', bookingPage: '/book/' },
				docs: { videoUrl: '' },
		  };

export function getConfig() {
	return boot;
}

export function can( cap ) {
	return Boolean( boot.caps?.[ cap ] );
}
