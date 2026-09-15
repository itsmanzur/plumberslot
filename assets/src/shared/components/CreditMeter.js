import { h } from 'preact';

export function CreditMeter( {
	used = 0,
	total = 0,
	label = 'Job credits',
	variant = 'bar',
} ) {
	const safeTotal = Math.max( 0, Number( total ) || 0 );
	const safeUsed = Math.min( safeTotal, Math.max( 0, Number( used ) || 0 ) );
	const remaining = safeTotal - safeUsed;
	const pct =
		safeTotal > 0 ? Math.round( ( remaining / safeTotal ) * 100 ) : 0;

	if ( variant === 'pips' ) {
		const pips = [];
		for ( let i = 0; i < safeTotal; i++ ) {
			pips.push(
				h( 'i', {
					key: i,
					class: i < safeUsed ? 'is-used' : '',
					'aria-hidden': 'true',
				} )
			);
		}
		return h(
			'div',
			{
				class: 'ts-credit ts-credit--pips',
				role: 'group',
				'aria-label': label,
			},
			h(
				'div',
				{ class: 'ts-credit__hd' },
				h( 'span', null, label ),
				h( 'strong', null, `${ remaining } left` )
			),
			h( 'div', { class: 'ts-credit__pips' }, pips ),
			h(
				'p',
				{ class: 'ts-credit__meta' },
				`${ safeUsed } used · ${ safeTotal } total`
			)
		);
	}

	return h(
		'div',
		{ class: 'ts-credit', role: 'group', 'aria-label': label },
		h(
			'div',
			{ class: 'ts-credit__hd' },
			h( 'span', null, label ),
			h( 'strong', null, `${ remaining } left` )
		),
		h(
			'div',
			{
				class: 'ts-credit__bar',
				role: 'progressbar',
				'aria-valuemin': 0,
				'aria-valuemax': safeTotal,
				'aria-valuenow': remaining,
				'aria-label': `${ remaining } of ${ safeTotal } credits remaining`,
			},
			h( 'div', {
				class: 'ts-credit__fill',
				style: { width: `${ pct }%` },
			} )
		),
		h(
			'p',
			{ class: 'ts-credit__meta' },
			`${ safeUsed } used · ${ safeTotal } total`
		)
	);
}
