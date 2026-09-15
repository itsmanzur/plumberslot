import { h } from 'preact';
import { Button, StatusChip } from '../../shared';
import { money } from '../lib';

export function ProfileView( { tutor, onBook } ) {
	const from = money( tutor.from_price_minor, tutor.currency );
	const rating = Number( tutor.rating ) || 0;
	const stars = rating
		? `${ '★'.repeat( Math.round( Math.min( 5, rating ) ) ) }`
		: '';

	return h(
		'div',
		{ class: 'ts-book ts-book--profile' },
		h(
			'div',
			{ class: 'ts-book__main' },
			h(
				'header',
				{ class: 'ts-book__identity' },
				h(
					'div',
					{ class: 'ts-book__avatar', 'aria-hidden': 'true' },
					tutor.initials
				),
				h(
					'div',
					null,
					h( 'h1', null, tutor.display_name ),
					h(
						'p',
						{ class: 'ts-book__rating' },
						stars
							? h(
									'span',
									null,
									h(
										'span',
										{ class: 'ts-book__stars' },
										stars
									),
									' ',
									h(
										'span',
										{ class: 'ts-book__muted' },
										`${ rating } · ${
											tutor.lesson_count || 0
										} lessons taught`
									)
							  )
							: `${ tutor.lesson_count || 0 } lessons taught`
					)
				)
			),
			h(
				'ul',
				{ class: 'ts-book__meta' },
				h(
					'li',
					null,
					h( 'b', null, `${ tutor.years_teaching } years` ),
					' teaching'
				),
				h(
					'li',
					null,
					'Replies in ',
					h( 'b', null, tutor.response_time )
				),
				h( 'li', null, ( tutor.languages || [] ).join( ', ' ) )
			),
			h(
				'div',
				{ class: 'ts-book__pills' },
				( tutor.subjects || [] ).map( ( s ) =>
					h( StatusChip, { key: s.id, tone: 'open' }, s.name )
				)
			),
			tutor.bio
				? h(
						'section',
						{ class: 'ts-book__bio' },
						h( 'h2', null, 'About' ),
						h( 'p', null, tutor.bio )
				  )
				: null,
			tutor.reviews?.length
				? h(
						'section',
						{ class: 'ts-book__reviews' },
						h( 'h2', null, 'What families say' ),
						tutor.reviews.map( ( r ) =>
							h(
								'article',
								{ key: r.id, class: 'ts-book__review' },
								h(
									'div',
									{ class: 'ts-book__review-top' },
									h( 'b', null, r.author ),
									h(
										'span',
										{ class: 'ts-book__stars' },
										`${ r.rating }★`
									),
									h( 'small', null, r.role )
								),
								h( 'p', null, r.body )
							)
						)
				  )
				: null
		),
		h(
			'aside',
			{ class: 'ts-book__sticky' },
			h( 'p', { class: 'ts-book__eyebrow' }, 'From' ),
			h(
				'p',
				{ class: 'ts-book__price' },
				from,
				h( 'span', { class: 'ts-book__price-unit' }, 'per hour' )
			),
			tutor.offer_trial
				? h(
						'p',
						{ class: 'ts-book__muted' },
						'First lesson can be free'
				  )
				: null,
			h(
				'div',
				{ class: 'ts-book__sticky-actions' },
				h( Button, { onClick: onBook }, 'See open times' ),
				h(
					Button,
					{
						variant: 'secondary',
						disabled: true,
						title: 'Messaging arrives in a later release',
					},
					`Message ${ firstName( tutor.display_name ) }`
				)
			),
			h(
				'p',
				{ class: 'ts-book__muted' },
				'Free cancellation until 12 hours before.'
			),
			h(
				'dl',
				{ class: 'ts-book__kv' },
				h(
					'div',
					null,
					h( 'dt', null, 'Next opening' ),
					h( 'dd', null, tutor.next_opening?.label || '—' )
				),
				h(
					'div',
					null,
					h( 'dt', null, 'Lesson length' ),
					h( 'dd', null, `${ tutor.default_duration } min` )
				),
				h(
					'div',
					null,
					h( 'dt', null, 'Where' ),
					h( 'dd', null, tutor.meeting_provider )
				)
			)
		)
	);
}

function firstName( name = '' ) {
	return String( name ).trim().split( /\s+/ )[ 0 ] || 'tutor';
}
