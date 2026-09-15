import { h } from 'preact';

export function TimezoneBanner( { timezone, label = 'Times shown in' } ) {
	return h(
		'div',
		{ class: 'ts-tz', role: 'status' },
		h( 'span', { class: 'ts-tz__icon', 'aria-hidden': 'true' }, '◎' ),
		h( 'span', null, `${ label } ` ),
		h( 'strong', null, timezone )
	);
}
