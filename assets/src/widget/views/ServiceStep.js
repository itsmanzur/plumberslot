import { h } from 'preact';
import { Button, EmptyState, WizardRail } from '../../shared';
import { handleListboxKeyDown } from '../a11y';
import { money } from '../lib';

const RAIL = [
	{ id: 'service', label: 'Service' },
	{ id: 'time', label: 'Time' },
	{ id: 'address', label: 'Address' },
	{ id: 'confirm', label: 'Confirm' },
];

export function ServiceStep( {
	technician,
	selectedId,
	onSelect,
	onContinue,
	onBack,
} ) {
	const services = technician.services || [];

	if ( ! services.length ) {
		return h( EmptyState, {
			title: 'No services yet',
			description: 'This technician has not published any services.',
		} );
	}

	const selected = services.find( ( s ) => s.id === selectedId );
	const selectedIndex = services.findIndex( ( s ) => s.id === selectedId );

	return h(
		'div',
		{ class: 'ts-book ts-book--step' },
		h(
			'div',
			{ class: 'ts-book__hd' },
			h( WizardRail, { steps: RAIL, current: 0 } )
		),
		h(
			'div',
			{ class: 'ts-book__body' },
			h( 'h2', null, 'What do you want to work on?' ),
			h(
				'p',
				{ class: 'ts-book__sub' },
				'Pick a service. Prices and appointment length can differ.'
			),
			h(
				'div',
				{
					class: 'ts-book__services',
					role: 'listbox',
					'aria-label': 'Services',
				},
				services.map( ( service, index ) => {
					const selectedTone = service.id === selectedId;
					const details = service.category || '';
					const price =
						Number( service.price_minor ) > 0
							? `${ money(
									service.price_minor,
									service.currency
								) } / appointment`
							: 'Free';
					return h(
						'button',
						{
							type: 'button',
							key: service.id,
							role: 'option',
							'aria-selected': selectedTone ? 'true' : 'false',
							tabIndex:
								selectedIndex === index ||
								( selectedIndex < 0 && index === 0 )
									? 0
									: -1,
							class: [
								'ts-book__service',
								selectedTone ? 'is-selected' : '',
								service.is_free_estimate
									? 'is-free-estimate'
									: '',
								service.is_emergency_available
									? 'is-emergency'
									: '',
							]
								.filter( Boolean )
								.join( ' ' ),
							onClick: () => onSelect( service.id ),
							onKeyDown: ( event ) =>
								handleListboxKeyDown( event, {
									items: services,
									currentIndex: index,
									onSelect: ( nextService ) =>
										onSelect( nextService.id ),
								} ),
						},
						h( 'strong', null, service.name ),
						h(
							'p',
							{ class: 'ts-book__muted' },
							details ||
								`${ service.duration_min } minute appointment`
						),
						service.is_emergency_available
							? h(
									'span',
									{ class: 'ts-book__emergency-badge' },
									'⚡ Emergency available'
								)
							: null,
						h(
							'span',
							{ class: 'ts-book__service-price' },
							service.is_free_estimate
								? h(
										'span',
										null,
										h(
											's',
											null,
											money(
												service.price_minor ||
													technician.from_price_minor,
												service.currency
											)
										),
										' Free estimate'
									)
								: price
						)
					);
				} )
			)
		),
		h(
			'footer',
			{ class: 'ts-book__ft' },
			onBack
				? h( Button, { variant: 'ghost', onClick: onBack }, '← Back' )
				: h( 'span' ),
			h(
				'div',
				{ class: 'ts-book__footer-end' },
				h(
					'span',
					{ class: 'ts-book__muted' },
					selected
						? `${ selected.name } selected`
						: 'Choose a service'
				),
				h(
					Button,
					{ disabled: ! selected, onClick: onContinue },
					'Choose a time →'
				)
			)
		)
	);
}

export { RAIL };
