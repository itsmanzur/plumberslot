import { h } from 'preact';

export function WizardRail( { steps = [], current = 0 } ) {
	return h(
		'ol',
		{ class: 'ts-wizard', 'aria-label': 'Setup progress' },
		steps.map( ( step, index ) => {
			const done = index < current;
			const active = index === current;
			let state = '';
			if ( done ) {
				state = 'done';
			} else if ( active ) {
				state = 'current';
			}

			return h(
				'li',
				{
					key: step.id || index,
					class: [
						'ts-wizard__step',
						state ? `ts-wizard__step--${ state }` : '',
					]
						.filter( Boolean )
						.join( ' ' ),
					'data-n': done ? '✓' : String( index + 1 ),
					'aria-current': active ? 'step' : undefined,
				},
				step.label
			);
		} )
	);
}
