/**
 * The Tools screen's jobs (backfill, verify) and the Classic Editor meta box.
 *
 * Plain DOM rather than React: these are a few buttons and a results area on server-rendered
 * screens. Text reaches the page through textContent only.
 */
import apiFetch from '@wordpress/api-fetch';
import { __, _n, sprintf } from '@wordpress/i18n';
import { eventLine, pinnedLabel, versionLine, versionLink } from './labels';

const { path, post } = window.soapboxBlyg;

function el( tag, text, attrs = {} ) {
	const node = document.createElement( tag );
	if ( text !== undefined && text !== null ) {
		node.textContent = text;
	}
	Object.entries( attrs ).forEach( ( [ name, value ] ) =>
		node.setAttribute( name, value )
	);
	return node;
}

function output( name ) {
	return document.querySelector( `[data-soapbox-blyg-output="${ name }"]` );
}

function notice( target, status, text ) {
	const box = el( 'div', null, {
		class: `notice notice-${ status } inline`,
	} );
	box.appendChild( el( 'p', text ) );
	target.appendChild( box );
	return box;
}

function download( filename, type, content ) {
	const link = el( 'a', filename, {
		href: URL.createObjectURL( new Blob( [ content ], { type } ) ),
		download: filename,
		class: 'button',
	} );
	link.style.marginRight = '8px';
	return link;
}

// CRC-32, as the zip format wants it.
const CRC_TABLE = Array.from( { length: 256 }, ( _, n ) => {
	let c = n;
	for ( let k = 0; k < 8; k++ ) {
		// eslint-disable-next-line no-bitwise
		c = c & 1 ? 0xedb88320 ^ ( c >>> 1 ) : c >>> 1;
	}
	// eslint-disable-next-line no-bitwise
	return c >>> 0;
} );

function crc32( bytes ) {
	let c = 0xffffffff;
	for ( let i = 0; i < bytes.length; i++ ) {
		// eslint-disable-next-line no-bitwise
		c = CRC_TABLE[ ( c ^ bytes[ i ] ) & 0xff ] ^ ( c >>> 8 );
	}
	// eslint-disable-next-line no-bitwise
	return ( c ^ 0xffffffff ) >>> 0;
}

/**
 * A zip archive of text files, stored without compression.
 *
 * Written here rather than taken from a library: it is the dry run's files in one download, the
 * format is forty lines, and a dependency would be several hundred kilobytes in the admin for it.
 *
 * @param {Array} files Each a pair: name, text.
 * @return {Blob} The archive.
 */
function zip( files ) {
	const encoder = new TextEncoder();
	const parts = [];
	const directory = [];
	let offset = 0;
	const header = ( signature, size ) => {
		const view = new DataView( new ArrayBuffer( size ) );
		view.setUint32( 0, signature, true );
		return view;
	};
	files.forEach( ( [ name, text ] ) => {
		const nameBytes = encoder.encode( name );
		const data = encoder.encode( text );
		const crc = crc32( data );
		// A local file header, then the bytes. Flag 0x0800: the name is UTF-8.
		const local = header( 0x04034b50, 30 );
		local.setUint16( 4, 20, true );
		local.setUint16( 6, 0x0800, true );
		local.setUint32( 14, crc, true );
		local.setUint32( 18, data.length, true );
		local.setUint32( 22, data.length, true );
		local.setUint16( 26, nameBytes.length, true );
		parts.push( local, nameBytes, data );
		// And its entry in the directory at the end.
		const central = header( 0x02014b50, 46 );
		central.setUint16( 4, 20, true );
		central.setUint16( 6, 20, true );
		central.setUint16( 8, 0x0800, true );
		central.setUint32( 16, crc, true );
		central.setUint32( 20, data.length, true );
		central.setUint32( 24, data.length, true );
		central.setUint16( 28, nameBytes.length, true );
		central.setUint32( 42, offset, true );
		directory.push( central, nameBytes );
		offset += 30 + nameBytes.length + data.length;
	} );
	const size = directory.reduce(
		( total, part ) => total + part.byteLength,
		0
	);
	const end = header( 0x06054b50, 22 );
	end.setUint16( 8, files.length, true );
	end.setUint16( 10, files.length, true );
	end.setUint32( 12, size, true );
	end.setUint32( 16, offset, true );
	return new Blob( [ ...parts, ...directory, end ], {
		type: 'application/zip',
	} );
}

function setBusy( busy ) {
	document.querySelectorAll( '[data-soapbox-blyg]' ).forEach( ( button ) => {
		button.disabled = busy;
	} );
}

/**
 * Runs the backfill one batch at a time until nothing is left, reporting progress as it goes.
 * A dry run collects what each post would look like and offers it as files to read.
 *
 * @param {boolean} dryRun Whether to render without publishing.
 */
async function backfill( dryRun ) {
	const target = output( 'backfill' );
	target.textContent = '';
	const progress = el( 'p', __( 'Starting…', 'soapbox-for-blygger' ) );
	target.appendChild( progress );
	setBusy( true );

	const rows = [];
	const failed = [];
	let offset = 0;
	let done = 0;
	let total = null;
	let stuck = 0;

	try {
		for (;;) {
			const batch = await apiFetch( {
				path: `${ path }/backfill`,
				method: 'POST',
				data: { dry_run: dryRun, offset },
			} );
			if ( total === null ) {
				total = batch.total;
			}
			done += dryRun ? batch.processed : batch.created;
			rows.push( ...batch.rows );
			failed.push( ...batch.failed );
			offset = batch.offset;
			stuck = batch.stuck;
			progress.textContent = sprintf(
				/* translators: 1: posts handled so far, 2: total posts */
				__( '%1$d of %2$d…', 'soapbox-for-blygger' ),
				Math.min( done + failed.length, total ),
				total
			);
			if ( batch.remaining === 0 ) {
				break;
			}
		}
	} catch ( e ) {
		notice( target, 'error', e.message );
		setBusy( false );
		return;
	}

	progress.remove();
	if ( dryRun ) {
		notice(
			target,
			failed.length ? 'warning' : 'success',
			[
				__( 'Dry run complete.', 'soapbox-for-blygger' ),
				sprintf(
					/* translators: %d: number of posts */
					_n(
						'%d post rendered.',
						'%d posts rendered.',
						rows.length,
						'soapbox-for-blygger'
					),
					rows.length
				),
				sprintf(
					/* translators: %d: number of posts that could not be rendered */
					_n(
						'%d failed.',
						'%d failed.',
						failed.length,
						'soapbox-for-blygger'
					),
					failed.length
				),
				__( 'Nothing was published.', 'soapbox-for-blygger' ),
			].join( ' ' )
		);
		if ( rows.length ) {
			// A spreadsheet treats a cell that begins with one of these as a formula. A leading
			// apostrophe makes it text. The command line's index.tsv does the same.
			const cell = ( value ) =>
				/^[=+\-@\t]/.test( String( value ) )
					? `'${ value }`
					: String( value );
			const tsv =
				'post_id\tslug\tchars\tmedia\tflags\n' +
				rows
					.map( ( r ) =>
						[
							r.post,
							r.slug,
							r.chars,
							r.media,
							r.flags.join( ',' ),
						]
							.map( cell )
							.join( '\t' )
					)
					.join( '\n' ) +
				'\n';
			const markdown = rows
				.map(
					( r ) =>
						`<!-- post ${ r.post }: ${ r.slug } -->\n\n${ r.md }`
				)
				.join( '\n\n---\n\n' );
			const files = el( 'p' );
			files.appendChild(
				download(
					'soapbox-blyg-dry-run.tsv',
					'text/tab-separated-values',
					tsv
				)
			);
			files.appendChild(
				download( 'soapbox-blyg-dry-run.md', 'text/markdown', markdown )
			);
			// Every post as two files of its own, with the list: the form a dump from the
			// command line has, so the two can be compared file for file.
			const archive = el( 'a', 'soapbox-blyg-dry-run.zip', {
				href: URL.createObjectURL(
					zip( [
						[ 'index.tsv', tsv ],
						...rows.flatMap( ( r ) => [
							[ `${ r.post }-${ r.slug }.md`, r.md ],
							[ `${ r.post }-${ r.slug }.html`, r.html ],
						] ),
					] )
				),
				download: 'soapbox-blyg-dry-run.zip',
				class: 'button',
			} );
			files.appendChild( archive );
			target.appendChild( files );
			target.appendChild(
				el(
					'p',
					__(
						'The first file lists every post with its size and flags; the second is every post as it would be published, to read through; the third is a zip of every post as separate Markdown and HTML files, for comparing one run with another. Flags: featured-only (the featured image was added), embed-link (an embed became a link), br-heavy (many line breaks: usually a poem), relative-src (an image address was relative), empty-body (nothing but the title).',
						'soapbox-for-blygger'
					),
					{ class: 'description' }
				)
			);
		}
	} else {
		notice(
			target,
			failed.length || stuck ? 'warning' : 'success',
			sprintf(
				/* translators: %d: number of posts added */
				_n(
					'Backfill complete: %d post added to the blyg.',
					'Backfill complete: %d posts added to the blyg.',
					done,
					'soapbox-for-blygger'
				),
				done
			)
		);
		if ( stuck ) {
			notice(
				target,
				'error',
				sprintf(
					/* translators: %d: number of posts */
					_n(
						'%d post could not be added and was left as it is.',
						'%d posts could not be added and were left as they are.',
						stuck,
						'soapbox-for-blygger'
					),
					stuck
				)
			);
		}
	}
	if ( failed.length ) {
		const list = el( 'ul' );
		list.style.listStyle = 'disc';
		list.style.marginLeft = '2em';
		failed.forEach( ( f ) =>
			list.appendChild(
				el(
					'li',
					sprintf(
						/* translators: 1: post id, 2: error message */
						__( 'Post %1$d: %2$s', 'soapbox-for-blygger' ),
						f.post,
						f.message
					)
				)
			)
		);
		target.appendChild( list );
	}
	setBusy( false );
	if ( ! dryRun ) {
		// The figures printed when the page loaded are out of date now. Say so rather than leave them.
		const waiting = document.querySelector( '[data-soapbox-blyg-waiting]' );
		if ( waiting ) {
			waiting.textContent = '';
		}
		const reload = el( 'p' );
		reload.appendChild(
			el(
				'a',
				__(
					'Reload this page to see the updated totals.',
					'soapbox-for-blygger'
				),
				{ href: window.location.href }
			)
		);
		target.appendChild( reload );
		if ( ! stuck ) {
			document
				.querySelectorAll( '[data-soapbox-blyg^="backfill"]' )
				.forEach( ( button ) => {
					button.disabled = true;
				} );
		}
	}
}

async function verify( render ) {
	const target = output( 'verify' );
	target.textContent = '';
	const progress = el( 'p', __( 'Verifying…', 'soapbox-for-blygger' ) );
	target.appendChild( progress );
	setBusy( true );
	try {
		const result = await apiFetch( {
			path: `${ path }/verify`,
			method: 'POST',
			data: { render },
		} );
		progress.remove();
		notice(
			target,
			result.passed ? 'success' : 'error',
			result.passed
				? __( 'The ledger is verified.', 'soapbox-for-blygger' )
				: sprintf(
						/* translators: %d: number of problems */
						_n(
							'%d problem found.',
							'%d problems found.',
							result.problems.length,
							'soapbox-for-blygger'
						),
						result.problems.length
					)
		);
		const lines = [
			...result.problems.map( ( line ) => `FAIL  ${ line }` ),
			...result.warnings.map( ( line ) => `WARN  ${ line }` ),
			...result.log,
		];
		const pre = el( 'pre', lines.join( '\n' ) );
		pre.style.cssText =
			'max-width:60rem;overflow:auto;background:#f6f7f7;padding:12px;border:1px solid #dcdcde';
		target.appendChild( pre );
	} catch ( e ) {
		progress.remove();
		notice( target, 'error', e.message );
	}
	setBusy( false );
}

const actions = {
	'backfill-dry': () => backfill( true ),
	backfill: () => backfill( false ),
	verify: () => verify( false ),
	'verify-render': () => verify( true ),
};
document.querySelectorAll( '[data-soapbox-blyg]' ).forEach( ( button ) => {
	const action = actions[ button.getAttribute( 'data-soapbox-blyg' ) ];
	if ( action ) {
		button.addEventListener( 'click', action );
	}
} );

/**
 * The Classic Editor meta box: the same data and the same pin route as the block editor's panel.
 *
 * @param {HTMLElement} box  The meta box's container.
 * @param {Object}      data The item, as the admin REST route returns it.
 */
function renderItem( box, data ) {
	box.textContent = '';
	const item = data.item;
	if ( ! item ) {
		box.appendChild(
			el(
				'p',
				__(
					'Not on the blyg yet. A post joins it when it is published.',
					'soapbox-for-blygger'
				)
			)
		);
		return;
	}
	const head = el( 'p' );
	const id = el( 'code', item.id );
	id.style.fontSize = '11px';
	head.appendChild( id );
	head.appendChild( el( 'br' ) );
	head.appendChild( document.createTextNode( versionLine( item ) + ' · ' ) );
	head.appendChild(
		el( 'a', __( 'Item document', 'soapbox-for-blygger' ), {
			href: item.url,
			target: '_blank',
			rel: 'noopener',
		} )
	);
	box.appendChild( head );

	if ( data.can_pin && item.changelog.some( ( entry ) => entry.pinnable ) ) {
		box.appendChild(
			el(
				'p',
				__(
					'A pinned version stays public for good, even if the post is later unpublished or deleted.',
					'soapbox-for-blygger'
				),
				{ class: 'description' }
			)
		);
	}

	const list = el( 'ol', null, { reversed: '' } );
	list.style.marginLeft = '1.5em';
	[ ...item.changelog ].reverse().forEach( ( entry ) => {
		const row = el( 'li', eventLine( entry ) );
		if ( entry.note ) {
			row.appendChild( el( 'br' ) );
			row.appendChild( el( 'em', entry.note ) );
		}
		if ( entry.pinned ) {
			row.appendChild( el( 'br' ) );
			row.appendChild(
				document.createTextNode( pinnedLabel( entry ) + ' · ' )
			);
			row.appendChild(
				el( 'a', versionLink( entry.version ), {
					href: entry.pin_url,
					target: '_blank',
					rel: 'noopener',
				} )
			);
		} else if ( entry.pinnable && data.can_pin ) {
			const button = el(
				'button',
				sprintf(
					/* translators: %d: version number */
					__( 'Pin version %d', 'soapbox-for-blygger' ),
					entry.version
				),
				{ type: 'button', class: 'button button-small' }
			);
			button.addEventListener( 'click', () => {
				// A native dialog on purpose: this is the Classic Editor, which has no modal component,
				// and an irrevocable action should not be one stray click.
				// eslint-disable-next-line no-alert
				const sure = window.confirm(
					sprintf(
						/* translators: %d: version number */
						__( 'Pin version %d?', 'soapbox-for-blygger' ),
						entry.version
					) +
						'\n\n' +
						__(
							'Pinning cannot be undone. This exact version will be served at its own address forever, including after later edits and if the post is withdrawn.',
							'soapbox-for-blygger'
						)
				);
				if ( ! sure ) {
					return;
				}
				button.disabled = true;
				apiFetch( {
					path: `${ path }/pin`,
					method: 'POST',
					data: { post, version: entry.version },
				} )
					.then( ( fresh ) => renderItem( box, fresh ) )
					.catch( ( e ) => {
						button.disabled = false;
						notice( box, 'error', e.message );
					} );
			} );
			row.appendChild( el( 'br' ) );
			row.appendChild( button );
		}
		list.appendChild( row );
	} );
	box.appendChild( list );
}

const itemBox = document.querySelector( '[data-soapbox-blyg-item]' );
if ( itemBox && post ) {
	apiFetch( { path: `${ path }/item?post=${ post }` } )
		.then( ( data ) => renderItem( itemBox, data ) )
		.catch( () => {} ); // The server-rendered summary stays as it is.
}
