import { h } from 'preact';
import { Button, Callout, WizardRail, navigateTo } from '../../shared';
import { formatInZone } from '../lib';
import { RAIL } from './ServiceStep';

/**
 * Soft account gate between Time and Confirm.
 * Keeps the chosen slot in a draft so WP login can return to Confirm.
 *
 * @param {Object}   root0            Component properties.
 * @param {Object}   root0.technician      Selected technician.
 * @param {Object}   root0.service    Selected service.
 * @param {string}   root0.start      Selected start time.
 * @param {string}   root0.timezone   Display timezone.
 * @param {string}   root0.loginUrl   WordPress login URL.
 * @param {Function} root0.onBack     Back navigation callback.
 * @param {Function} root0.onSignedIn Signed-in callback.
 */
export function AccountStep( {
	technician,
	service,
	start,
	timezone,
	loginUrl,
	onBack,
	onSignedIn,
} ) {
	const when = start ? formatInZone( start, timezone ) : '';

	return h(
		'div',
		{ class: 'ts-book ts-book--step' },
		h(
			'div',
			{ class: 'ts-book__hd' },
			h( WizardRail, { steps: RAIL, current: 2 } )
		),
		h(
			'div',
			{ class: 'ts-book__body' },
			h( 'h2', null, 'Sign in to book' ),
			h(
				'p',
				{ class: 'ts-book__lede' },
				'We hold your slot after you sign in, then send reminders and the meeting link to your account.'
			),
			h(
				Callout,
				{ title: 'Ready to book:' },
				[ service?.name || 'Lesson', technician?.display_name, when ]
					.filter( Boolean )
					.join( ' · ' )
			),
			h(
				'p',
				{ class: 'ts-book__muted' },
				'After sign-in you will return here to confirm — your service and time stay selected.'
			)
		),
		h(
			'footer',
			{ class: 'ts-book__ft' },
			h( Button, { variant: 'ghost', onClick: onBack }, '← Back' ),
			h(
				'div',
				{ class: 'ts-book__footer-end' },
				onSignedIn
					? h( Button, { onClick: onSignedIn }, 'Continue →' )
					: h(
							Button,
							{
								onClick: () => {
									navigateTo( loginUrl, {
										sameOrigin: true,
									} );
								},
							},
							'Sign in to continue'
					  )
			)
		)
	);
}
