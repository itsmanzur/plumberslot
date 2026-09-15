import { h } from 'preact';
import { Button } from '../../shared';

export function PageHeader( { title, subtitle, eyebrow = '', actions = [] } ) {
	return h(
		'header',
		{ class: 'ts-admin__header' },
		h(
			'div',
			null,
			eyebrow ? h( 'p', { class: 'ts-admin__eyebrow' }, eyebrow ) : null,
			h( 'h1', null, title ),
			subtitle ? h( 'p', { class: 'ts-admin__sub' }, subtitle ) : null
		),
		actions.length
			? h(
					'div',
					{ class: 'ts-admin__actions' },
					actions.map( ( action, index ) =>
						h(
							Button,
							{
								key: action.id || index,
								variant: action.variant || 'secondary',
								dirty: action.dirty,
								disabled: action.disabled,
								onClick: action.onClick,
							},
							action.label
						)
					)
				)
			: null
	);
}

export function ScreenState( { status, error, empty, children } ) {
	if ( status === 'loading' ) {
		return h(
			'div',
			{ class: 'ts-admin__state' },
			empty?.loading || 'Loading…'
		);
	}

	if ( status === 'error' ) {
		return h(
			'div',
			{ class: 'ts-admin__state ts-admin__state--error', role: 'alert' },
			error || 'Something went wrong.'
		);
	}

	return children;
}
