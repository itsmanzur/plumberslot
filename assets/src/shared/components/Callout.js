import { h } from 'preact';

const TONE_CLASS = {
	warn: 'ts-callout--warn',
	danger: 'ts-callout--danger',
};

export function Callout( { title, children, tone = 'info', className = '' } ) {
	const toneClass = TONE_CLASS[ tone ] || '';

	return h(
		'div',
		{
			class: [ 'ts-callout', toneClass, className ]
				.filter( Boolean )
				.join( ' ' ),
			role: 'note',
		},
		title ? h( 'strong', null, title, ' ' ) : null,
		children
	);
}
