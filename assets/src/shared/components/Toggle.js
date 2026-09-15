import { h } from 'preact';

export function Toggle( { checked, onChange, label, explanation, id } ) {
	const switchId =
		id || `ts-toggle-${ label.replace( /\s+/g, '-' ).toLowerCase() }`;

	return h(
		'div',
		{ class: 'ts-toggle' },
		h(
			'button',
			{
				id: switchId,
				type: 'button',
				class: 'ts-toggle__switch',
				role: 'switch',
				'aria-checked': checked ? 'true' : 'false',
				'aria-label': label,
				onClick: () => onChange( ! checked ),
			},
			h( 'span', { class: 'ts-toggle__track', 'aria-hidden': 'true' } )
		),
		h(
			'div',
			null,
			h(
				'label',
				{ class: 'ts-toggle__label', htmlFor: switchId },
				label
			),
			explanation
				? h( 'p', { class: 'ts-toggle__hint' }, explanation )
				: null
		)
	);
}
