import { h } from 'preact';
import { Button, StatusChip } from '../../shared';
import { money } from '../lib';

export function ProfileView( { technician, onBook } ) {
	const from = money( technician.from_price_minor, technician.currency );
	const rating = Number( technician.rating ) || 0;
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
					technician.initials
				),
				h(
					'div',
					null,
					h( 'h1', null, technician.display_name ),
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
											technician.job_count || 0
										} jobs completed`
									)
							  )
							: `${ technician.job_count || 0 } jobs completed`
					)
				)
			),
			h(
				'ul',
				{ class: 'ts-book__meta' },
				h(
					'li',
					null,
					h( 'b', null, `${ technician.years_teaching } years` ),
					' in business'
				),
				h(
					'li',
					null,
					'Replies in ',
					h( 'b', null, technician.response_time )
				),
				h( 'li', null, ( technician.languages || [] ).join( ', ' ) )
			),
			h(
				'div',
				{ class: 'ts-book__pills' },
				( technician.services || [] ).map( ( s ) =>
					h( StatusChip, { key: s.id, tone: 'open' }, s.name )
				)
			),
			technician.bio
				? h(
						'section',
						{ class: 'ts-book__bio' },
						h( 'h2', null, 'About' ),
						h( 'p', null, technician.bio )
				  )
				: null,
			technician.reviews?.length
				? h(
						'section',
						{ class: 'ts-book__reviews' },
						h( 'h2', null, 'What customers say' ),
						technician.reviews.map( ( r ) =>
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
			technician.offer_free_estimate
				? h(
						'p',
						{ class: 'ts-book__muted' },
						'First estimate can be free'
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
					`Message ${ firstName( technician.display_name ) }`
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
					h( 'dd', null, technician.next_opening?.label || '—' )
				),
				h(
					'div',
					null,
					h( 'dt', null, 'Appointment length' ),
					h( 'dd', null, `${ technician.default_duration } min` )
				),
				h(
					'div',
					null,
					h( 'dt', null, 'Where' ),
					h( 'dd', null, technician.meeting_provider )
				)
			)
		)
	);
}

function firstName( name = '' ) {
	return String( name ).trim().split( /\s+/ )[ 0 ] || 'technician';
}
