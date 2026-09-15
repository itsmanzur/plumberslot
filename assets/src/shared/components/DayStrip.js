import { h } from 'preact';

export function DayStrip( {
	days = [],
	value,
	onChange,
	'aria-label': ariaLabel = 'Choose day',
} ) {
	return h(
		'div',
		{ class: 'ts-days', role: 'group', 'aria-label': ariaLabel },
		days.map( ( day ) => {
			const selected = day.id === value;
			const open = ! day.disabled;

			return h(
				'button',
				{
					type: 'button',
					key: day.id,
					class: [ 'ts-day', open ? 'ts-day--open' : 'ts-day--gone' ]
						.filter( Boolean )
						.join( ' ' ),
					'aria-pressed': selected ? 'true' : 'false',
					onClick: () => onChange( day.id ),
					disabled: day.disabled,
				},
				h( 'span', { class: 'ts-day__dow' }, day.dow ),
				h( 'span', { class: 'ts-day__dom' }, day.date ),
				h( 'span', {
					class: 'ts-day__dot',
					'aria-hidden': 'true',
				} )
			);
		} )
	);
}
