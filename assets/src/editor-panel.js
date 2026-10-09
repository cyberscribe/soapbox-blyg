/**
 * The Blyg panel in the block editor's document sidebar.
 *
 * It reads the post's item through the plugin's admin REST routes, and refreshes after every
 * save, since a save is what may publish a new version. Pinning is a request of its own, never
 * part of the post's save.
 */
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	ExternalLink,
	Modal,
	Notice,
	Spinner,
} from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { registerPlugin } from '@wordpress/plugins';
import { eventLine, pinnedLabel, versionLine, versionLink } from './labels';

const { path } = window.soapboxBlyg;

function BlygPanel() {
	const { postId, isSaving } = useSelect( ( select ) => {
		const store = select( 'core/editor' );
		return {
			postId: store.getCurrentPostId(),
			isSaving: store.isSavingPost() && ! store.isAutosavingPost(),
		};
	}, [] );

	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ confirming, setConfirming ] = useState( null );
	const [ pinning, setPinning ] = useState( false );
	const wasSaving = useRef( false );

	const load = useCallback( () => {
		apiFetch( { path: `${ path }/item?post=${ postId }` } )
			.then( ( response ) => {
				setData( response );
				setError( null );
			} )
			.catch( ( e ) => setError( e.message ) );
	}, [ postId ] );

	useEffect( load, [ load ] );

	// Reload when a save finishes: that is the moment the item may have gained a version.
	useEffect( () => {
		if ( wasSaving.current && ! isSaving ) {
			load();
		}
		wasSaving.current = isSaving;
	}, [ isSaving, load ] );

	const pin = ( version ) => {
		setPinning( true );
		apiFetch( {
			path: `${ path }/pin`,
			method: 'POST',
			data: { post: postId, version },
		} )
			.then( ( response ) => {
				setData( response );
				setError( null );
			} )
			.catch( ( e ) => setError( e.message ) )
			.finally( () => {
				setPinning( false );
				setConfirming( null );
			} );
	};

	if ( data && ! data.publishes ) {
		return null;
	}

	const item = data && data.item;

	return (
		<PluginDocumentSettingPanel
			name="soapbox-blyg"
			title={ __( 'Blyg', 'soapbox-for-blygger' ) }
			className="soapbox-blyg-panel"
		>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			{ ! data && ! error && <Spinner /> }
			{ data && ! item && (
				<p>
					{ __(
						'Not on the blyg yet. A post joins it when it is published.',
						'soapbox-for-blygger'
					) }
				</p>
			) }
			{ item && (
				<>
					<p>
						<code
							style={ {
								fontSize: '11px',
								wordBreak: 'break-all',
							} }
						>
							{ item.id }
						</code>
						<br />
						{ versionLine( item ) }
						<br />
						<ExternalLink href={ item.url }>
							{ __( 'Item document', 'soapbox-for-blygger' ) }
						</ExternalLink>
					</p>
					{ item.mentions !== null && item.mentions > 0 && (
						<p>
							{ sprintf(
								/* translators: %d: number of verified mentions */
								_n(
									'%d verified mention',
									'%d verified mentions',
									item.mentions,
									'soapbox-for-blygger'
								),
								item.mentions
							) }
						</p>
					) }
					{ data.can_pin &&
						item.changelog.some( ( entry ) => entry.pinnable ) && (
							<p className="description">
								{ __(
									'A pinned version stays public for good, even if the post is later unpublished or deleted.',
									'soapbox-for-blygger'
								) }
							</p>
						) }
					<ol
						reversed
						style={ { marginLeft: '1.5em', listStyle: 'decimal' } }
					>
						{ [ ...item.changelog ].reverse().map( ( entry ) => (
							<li
								key={ entry.version }
								style={ { marginBottom: '8px' } }
							>
								{ eventLine( entry ) }
								{ entry.note && (
									<>
										<br />
										<em>{ entry.note }</em>
									</>
								) }
								{ entry.pinned && (
									<>
										<br />
										{ pinnedLabel( entry ) }
										{ ' · ' }
										<ExternalLink href={ entry.pin_url }>
											{ versionLink( entry.version ) }
										</ExternalLink>
									</>
								) }
								{ entry.pinnable && data.can_pin && (
									<>
										<br />
										<Button
											variant="secondary"
											size="small"
											onClick={ () =>
												setConfirming( entry.version )
											}
										>
											{ sprintf(
												/* translators: %d: version number */
												__(
													'Pin version %d',
													'soapbox-for-blygger'
												),
												entry.version
											) }
										</Button>
									</>
								) }
							</li>
						) ) }
					</ol>
				</>
			) }
			{ confirming !== null && (
				<Modal
					title={ sprintf(
						/* translators: %d: version number */
						__( 'Pin version %d?', 'soapbox-for-blygger' ),
						confirming
					) }
					onRequestClose={ () => setConfirming( null ) }
				>
					<p>
						{ __(
							'Pinning cannot be undone. This exact version will be served at its own address forever, including after later edits and if the post is withdrawn.',
							'soapbox-for-blygger'
						) }
					</p>
					<p>
						{ __(
							'Pin a version when someone needs to be able to cite it and find the same words there later.',
							'soapbox-for-blygger'
						) }
					</p>
					<div
						style={ {
							display: 'flex',
							gap: '8px',
							justifyContent: 'flex-end',
						} }
					>
						<Button
							variant="tertiary"
							onClick={ () => setConfirming( null ) }
						>
							{ __( 'Cancel', 'soapbox-for-blygger' ) }
						</Button>
						<Button
							variant="primary"
							isBusy={ pinning }
							disabled={ pinning }
							onClick={ () => pin( confirming ) }
						>
							{ __(
								'Pin it permanently',
								'soapbox-for-blygger'
							) }
						</Button>
					</div>
				</Modal>
			) }
		</PluginDocumentSettingPanel>
	);
}

registerPlugin( 'soapbox-blyg', { render: BlygPanel, icon: null } );
