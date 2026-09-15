import { h } from 'preact';

/**
 * Horizontal day schedule strip (timeline).
 *
 * @param {Object}   props
 * @param {Array}    props.items     Sessions: { id, title, startPct, widthPct, done? }
 * @param {number}   props.nowPct    Now-marker position 0–100
 * @param {string}   props.nowLabel  e.g. "14:20"
 * @param {string[]} props.ticks     Hour labels under the line
 * @param {string}   props.className Optional styling hook
 */
export function TodayStrip( {
	items = [],
	nowPct = 0,
	nowLabel = 'Now',
	ticks = [ '08:00', '10:00', '12:00', '14:00', '16:00', '18:00' ],
	className = '',
} ) {
	return h(
		'div',
		{
			class: [ 'ts-strip', className ].filter( Boolean ).join( ' ' ),
			'aria-label': 'Today’s schedule',
		},
		h(
			'div',
			{ class: 'ts-strip__line' },
			h( 'div', {
				class: 'ts-strip__now',
				style: { left: `${ nowPct }%` },
				'data-label': nowLabel,
			} ),
			items.map( ( item ) =>
				h(
					'div',
					{
						key: item.id,
						class: [
							'ts-strip__ses',
							item.done ? 'ts-strip__ses--done' : '',
						]
							.filter( Boolean )
							.join( ' ' ),
						style: {
							left: `${ item.startPct }%`,
							width: `${ item.widthPct }%`,
						},
						title: item.title,
					},
					item.time
						? h( 'span', { class: 'ts-strip__time' }, item.time )
						: null,
					item.title
				)
			)
		),
		h(
			'div',
			{ class: 'ts-strip__ticks', 'aria-hidden': 'true' },
			ticks.map( ( tick ) => h( 'span', { key: tick }, tick ) )
		)
	);
}
