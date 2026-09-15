import { h } from 'preact';
import { Button, WizardRail } from '../../shared';
import { RAIL } from './ServiceStep';

/**
 * Collects the job-site address. Plumbing is on-site work, unlike the
 * remote tutoring this widget was forked from, so a booking is not
 * complete without knowing where the technician needs to show up.
 *
 * @param {Object}   props
 * @param {Object}   props.address    { line1, line2, city, state, zip }
 * @param {Function} props.onChange   ( nextAddress ) => void
 * @param {Function} props.onContinue
 * @param {Function} props.onBack
 */
export function AddressStep( { address, onChange, onContinue, onBack } ) {
	const value = address || {};

	const set = ( field ) => ( event ) => {
		onChange( { ...value, [ field ]: event.target.value } );
	};

	const isComplete =
		Boolean( ( value.line1 || '' ).trim() ) &&
		Boolean( ( value.city || '' ).trim() ) &&
		Boolean( ( value.state || '' ).trim() ) &&
		Boolean( ( value.zip || '' ).trim() );

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
			h( 'h2', null, 'Where is the job?' ),
			h(
				'p',
				{ class: 'ts-book__sub' },
				"Tell us the service address so the technician knows where to go."
			),
			h(
				'div',
				{ class: 'ts-book__fields' },
				h(
					'label',
					{ class: 'ts-book__field' },
					h(
						'span',
						null,
						'Street address',
						h( 'small', null, 'Required' )
					),
					h( 'input', {
						type: 'text',
						autocomplete: 'address-line1',
						value: value.line1 || '',
						placeholder: '123 Main St',
						onInput: set( 'line1' ),
					} )
				),
				h(
					'label',
					{ class: 'ts-book__field' },
					h(
						'span',
						null,
						'Apt, suite, unit',
						h( 'small', null, 'Optional' )
					),
					h( 'input', {
						type: 'text',
						autocomplete: 'address-line2',
						value: value.line2 || '',
						placeholder: 'Apt 4B',
						onInput: set( 'line2' ),
					} )
				),
				h(
					'label',
					{ class: 'ts-book__field' },
					h( 'span', null, 'City', h( 'small', null, 'Required' ) ),
					h( 'input', {
						type: 'text',
						autocomplete: 'address-level2',
						value: value.city || '',
						placeholder: 'Springfield',
						onInput: set( 'city' ),
					} )
				),
				h(
					'div',
					{ class: 'ts-book__fields ts-book__fields--two' },
					h(
						'label',
						{ class: 'ts-book__field' },
						h(
							'span',
							null,
							'State',
							h( 'small', null, 'Required' )
						),
						h( 'input', {
							type: 'text',
							autocomplete: 'address-level1',
							value: value.state || '',
							placeholder: 'CA',
							onInput: set( 'state' ),
						} )
					),
					h(
						'label',
						{ class: 'ts-book__field' },
						h(
							'span',
							null,
							'ZIP / postal code',
							h( 'small', null, 'Required' )
						),
						h( 'input', {
							type: 'text',
							autocomplete: 'postal-code',
							value: value.zip || '',
							placeholder: '90210',
							onInput: set( 'zip' ),
						} )
					)
				)
			)
		),
		h(
			'footer',
			{ class: 'ts-book__ft' },
			h( Button, { variant: 'ghost', onClick: onBack }, '← Back' ),
			h(
				Button,
				{ disabled: ! isComplete, onClick: onContinue },
				'Continue →'
			)
		)
	);
}
