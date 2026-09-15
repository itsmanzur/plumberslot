import { h } from 'preact';

/**
 * Compact person cell for admin tables.
 *
 * @param {Object} props
 * @param {string} props.name
 * @param {string} [props.context]
 * @param {string} [props.initials]
 */
export function PersonCell( { name, context = '', initials = '' } ) {
	const mark =
		initials ||
		String( name || '?' )
			.split( /\s+/ )
			.map( ( p ) => p[ 0 ] )
			.join( '' )
			.slice( 0, 2 )
			.toUpperCase();

	return h(
		'div',
		{ class: 'ts-person' },
		h(
			'span',
			{ class: 'ts-person__avatar', 'aria-hidden': 'true' },
			mark
		),
		h(
			'span',
			{ class: 'ts-person__text' },
			h( 'b', { class: 'ts-person__name' }, name ),
			context
				? h( 'small', { class: 'ts-person__context' }, context )
				: null
		)
	);
}
