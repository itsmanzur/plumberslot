import { h } from 'preact';

// Rounded star rating with an optional review count, e.g. "★★★★☆ 4.2 · 18
// reviews". Renders nothing when there is no rating yet, so callers can use
// it unconditionally without an extra guard.
export function RatingStars( { rating, count = 0, showCount = true } ) {
	const value = Number( rating ) || 0;

	if ( ! value ) {
		return null;
	}

	const stars = '★'.repeat( Math.round( Math.min( 5, value ) ) );

	return h(
		'span',
		{ class: 'ts-book__rating-inline' },
		h( 'span', { class: 'ts-book__stars' }, stars ),
		showCount
			? h(
					'span',
					{ class: 'ts-book__muted' },
					` ${ value } · ${ count } review${ 1 === count ? '' : 's' }`
				)
			: null
	);
}
