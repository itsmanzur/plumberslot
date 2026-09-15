import { h } from 'preact';

const TONES = {
	open: 'ts-slot--open',
	held: 'ts-slot--held',
	booked: 'ts-slot--booked',
	taken: 'ts-slot--booked',
	gone: 'ts-slot--gone',
};

export function SlotButton( {
	label,
	tone = 'open',
	selected = false,
	disabled = false,
	listboxOption = false,
	onClick,
	...rest
} ) {
	const locked =
		disabled ||
		tone === 'booked' ||
		tone === 'taken' ||
		tone === 'held' ||
		tone === 'gone';

	return h(
		'button',
		{
			type: 'button',
			class: [ 'ts-slot', TONES[ tone ] || TONES.open ]
				.filter( Boolean )
				.join( ' ' ),
			disabled: locked,
			...( listboxOption
				? {
						role: 'option',
						'aria-selected': selected ? 'true' : 'false',
					}
				: { 'aria-pressed': selected ? 'true' : 'false' } ),
			onClick,
			...rest,
		},
		label
	);
}
