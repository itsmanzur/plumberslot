import { h } from 'preact';
import { Button, EmptyState, WizardRail } from '../../shared';
import { handleListboxKeyDown } from '../a11y';
import { money } from '../lib';

const RAIL = [
	{ id: 'subject', label: 'Subject' },
	{ id: 'time', label: 'Time' },
	{ id: 'confirm', label: 'Confirm' },
];

export function SubjectStep( {
	tutor,
	selectedId,
	onSelect,
	onContinue,
	onBack,
} ) {
	const subjects = tutor.subjects || [];

	if ( ! subjects.length ) {
		return h( EmptyState, {
			title: 'No subjects yet',
			description: 'This tutor has not published lesson subjects.',
		} );
	}

	const selected = subjects.find( ( s ) => s.id === selectedId );
	const selectedIndex = subjects.findIndex( ( s ) => s.id === selectedId );

	return h(
		'div',
		{ class: 'ts-book ts-book--step' },
		h(
			'div',
			{ class: 'ts-book__hd' },
			h( WizardRail, { steps: RAIL, current: 0 } )
		),
		h(
			'div',
			{ class: 'ts-book__body' },
			h( 'h2', null, 'What do you want to work on?' ),
			h(
				'p',
				{ class: 'ts-book__sub' },
				'Pick a subject. Prices and lesson length can differ.'
			),
			h(
				'div',
				{
					class: 'ts-book__subjects',
					role: 'listbox',
					'aria-label': 'Subjects',
				},
				subjects.map( ( subject, index ) => {
					const selectedTone = subject.id === selectedId;
					const details = [ subject.level, subject.curriculum ]
						.filter( Boolean )
						.join( ' · ' );
					const price =
						Number( subject.price_minor ) > 0
							? `${ money(
									subject.price_minor,
									subject.currency
							  ) } / lesson`
							: 'Free';
					return h(
						'button',
						{
							type: 'button',
							key: subject.id,
							role: 'option',
							'aria-selected': selectedTone ? 'true' : 'false',
							tabIndex:
								selectedIndex === index ||
								( selectedIndex < 0 && index === 0 )
									? 0
									: -1,
							class: [
								'ts-book__subject',
								selectedTone ? 'is-selected' : '',
								subject.is_trial ? 'is-trial' : '',
							]
								.filter( Boolean )
								.join( ' ' ),
							onClick: () => onSelect( subject.id ),
							onKeyDown: ( event ) =>
								handleListboxKeyDown( event, {
									items: subjects,
									currentIndex: index,
									onSelect: ( nextSubject ) =>
										onSelect( nextSubject.id ),
								} ),
						},
						h( 'strong', null, subject.name ),
						h(
							'p',
							{ class: 'ts-book__muted' },
							details || `${ subject.duration_min } minute lesson`
						),
						h(
							'span',
							{ class: 'ts-book__subject-price' },
							subject.is_trial
								? h(
										'span',
										null,
										h(
											's',
											null,
											money(
												subject.price_minor ||
													tutor.from_price_minor,
												subject.currency
											)
										),
										' Free trial'
								  )
								: price
						)
					);
				} )
			)
		),
		h(
			'footer',
			{ class: 'ts-book__ft' },
			onBack
				? h( Button, { variant: 'ghost', onClick: onBack }, '← Back' )
				: h( 'span' ),
			h(
				'div',
				{ class: 'ts-book__footer-end' },
				h(
					'span',
					{ class: 'ts-book__muted' },
					selected
						? `${ selected.name } selected`
						: 'Choose a subject'
				),
				h(
					Button,
					{ disabled: ! selected, onClick: onContinue },
					'Choose a time →'
				)
			)
		)
	);
}

export { RAIL };
