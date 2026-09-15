import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import zlib from 'node:zlib';
import { fileURLToPath } from 'node:url';

const pluginRoot = path.resolve(
	path.dirname( fileURLToPath( import.meta.url ) ),
	'..'
);
const budgetBytes = 50 * 1024;
const assets = [ 'widget.js', 'widget.css' ].map( ( filename ) => {
	const contents = fs.readFileSync(
		path.join( pluginRoot, 'assets', 'dist', filename )
	);
	return {
		filename,
		rawBytes: contents.byteLength,
		gzipBytes: zlib.gzipSync( contents, { level: 9 } ).byteLength,
	};
} );
const totalGzipBytes = assets.reduce(
	( total, asset ) => total + asset.gzipBytes,
	0
);
const formatKib = ( bytes ) => `${ ( bytes / 1024 ).toFixed( 2 ) } KiB`;

for ( const asset of assets ) {
	console.log(
		`${ asset.filename }: ${ formatKib(
			asset.gzipBytes
		) } gzip (${ formatKib( asset.rawBytes ) } raw)`
	);
}

console.log(
	`Combined: ${ formatKib( totalGzipBytes ) } / ${ formatKib(
		budgetBytes
	) } gzip`
);

if ( totalGzipBytes >= budgetBytes ) {
	console.error( 'Widget JS + CSS gzip budget exceeded.' );
	process.exitCode = 1;
}
