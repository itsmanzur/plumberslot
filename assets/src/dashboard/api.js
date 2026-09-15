const boot =
	typeof window !== 'undefined' && window.plumberslotDashboard
		? window.plumberslotDashboard
		: {
				root: '/wp-json/plumberslot/v1',
				nonce: '',
				loggedIn: false,
				loginUrl: '/wp-login.php',
				user: null,
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
	const { method = 'GET', body, headers = {} } = options;
	const cfg = getBoot();
	const url = `${ cfg.root.replace( /\/$/, '' ) }/${ path.replace(
		/^\//,
		''
	) }`;
	const response = await fetch( url, {
		method,
		credentials: 'same-origin',
		headers: {
			Accept: 'application/json',
			'Content-Type': 'application/json',
			...( cfg.nonce ? { 'X-WP-Nonce': cfg.nonce } : {} ),
			...headers,
		},
		body: body ? JSON.stringify( body ) : undefined,
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

export function put( path, body ) {
	return api( path, { method: 'PUT', body } );
}

export function del( path ) {
	return api( path, { method: 'DELETE' } );
}
