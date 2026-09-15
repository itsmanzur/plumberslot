import { h } from 'preact';

export function StatTile( { label, value, hint } ) {
	return h(
		'div',
		{ class: 'ts-tile' },
		h( 'div', { class: 'ts-tile__k' }, label ),
		h( 'div', { class: 'ts-tile__v' }, value ),
		hint ? h( 'div', { class: 'ts-tile__d' }, hint ) : null
	);
}
