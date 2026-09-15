import { h } from 'preact';
import { Button } from './Button';

export function EmptyState( {
	title,
	description,
	actionLabel,
	onAction,
	secondaryLabel,
	onSecondary,
} ) {
	const primary =
		actionLabel && onAction
			? h( Button, { onClick: onAction }, actionLabel )
			: null;
	const secondary =
		secondaryLabel && onSecondary
			? h(
					Button,
					{ variant: 'secondary', onClick: onSecondary },
					secondaryLabel
			  )
			: null;

	return h(
		'div',
		{ class: 'ts-empty', role: 'status' },
		h( 'div', { class: 'ts-empty__mark', 'aria-hidden': 'true' } ),
		h( 'h3', null, title ),
		description ? h( 'p', null, description ) : null,
		primary || secondary
			? h( 'div', { class: 'ts-empty__actions' }, primary, secondary )
			: null
	);
}

export function ErrorState( { title, description, actionLabel, onAction } ) {
	return h(
		'div',
		{ class: 'ts-error', role: 'alert' },
		h( 'div', { class: 'ts-error__mark', 'aria-hidden': 'true' }, '!' ),
		h( 'h3', null, title ),
		description ? h( 'p', null, description ) : null,
		actionLabel && onAction
			? h(
					Button,
					{ variant: 'secondary', onClick: onAction },
					actionLabel
			  )
			: null
	);
}

export function Skeleton( { lines = 3 } ) {
	const items = [];

	for ( let i = 0; i < lines; i++ ) {
		items.push(
			h( 'div', {
				class: [
					'ts-skeleton__line',
					i === 0 ? 'ts-skeleton__line--lg' : '',
				]
					.filter( Boolean )
					.join( ' ' ),
				key: i,
			} )
		);
	}

	return h(
		'div',
		{ class: 'ts-skeleton', 'aria-busy': 'true', 'aria-live': 'polite' },
		items
	);
}
