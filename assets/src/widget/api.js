const boot =
	typeof window !== 'undefined' && window.plumberslotWidget
		? window.plumberslotWidget
		: {
				root: '/wp-json/plumberslot/v1',
				nonce: '',
				loggedIn: false,
				loginUrl: '/wp-login.php',
				dashboardUrl: '/',
				user: null,
				payments: { stripe: false, bkash: false },
				i18n: {},
		  };

export function getBoot() {
	return boot;
}

export class ApiError extends Error {
	constructor( message, { code = 'error', status = 0 } = {} ) {
		super( message );
		this.code = code;
		this.status = status;
	}
}

export async function api( path, options = {} ) {
	const { method = 'GET', body, headers = {}, keepalive = false } = options;
	const bootCfg = getBoot();
	const url = `${ bootCfg.root.replace( /\/$/, '' ) }/${ path.replace(
		/^\//,
		''
	) }`;
	const response = await fetch( url, {
		method,
		credentials: 'same-origin',
		headers: {
			Accept: 'application/json',
			'Content-Type': 'application/json',
			...( bootCfg.nonce ? { 'X-WP-Nonce': bootCfg.nonce } : {} ),
			...headers,
		},
		body: body ? JSON.stringify( body ) : undefined,
		keepalive,
	} );

	let payload = null;
	const text = await response.text();
	if ( text ) {
		try {
			payload = JSON.parse( text );
		} catch {
			payload = text;
		}
	}

	if ( ! response.ok ) {
		throw new ApiError( payload?.message || 'Request failed.', {
			code: payload?.code || 'error',
			status: response.status,
		} );
	}

	return payload;
}

export function get( path, query = {} ) {
	const params = new URLSearchParams();
	Object.entries( query ).forEach( ( [ key, value ] ) => {
		if ( value !== undefined && value !== null && value !== '' ) {
			params.set( key, String( value ) );
		}
	} );
	const qs = params.toString();
	return api( qs ? `${ path }?${ qs }` : path );
}

export function post( path, body ) {
	return api( path, { method: 'POST', body } );
}

export function remove( path, body, { keepalive = false } = {} ) {
	return api( path, { method: 'DELETE', body, keepalive } );
}
