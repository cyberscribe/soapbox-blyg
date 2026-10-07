/**
 * Wording shared by the block editor panel and the Classic Editor meta box.
 *
 * Each of these is a whole phrase with its values in place, never words joined in code: a
 * translator has to be able to reorder them, and a word such as "Withdrawn" or "Version" is
 * not the same word in every position in every language.
 */
import { dateI18n, getSettings } from '@wordpress/date';
import { _x, sprintf } from '@wordpress/i18n';

/**
 * A moment in the site's own date and time format.
 *
 * @param {string} iso ISO 8601 time.
 * @return {string} The formatted time.
 */
export function when( iso ) {
	const formats = getSettings().formats;
	return dateI18n( `${ formats.date } ${ formats.time }`, iso );
}

/**
 * The line under an item's id: its current version and what kind of item it is.
 *
 * @param {Object} item The item, with version and kind.
 * @return {string} The line.
 */
export function versionLine( item ) {
	if ( 'withdrawn' === item.kind ) {
		return sprintf(
			/* translators: %d: version number. The item is currently withdrawn. */
			_x(
				'Version %d, withdrawn',
				'an item’s current state',
				'soapbox-blyg'
			),
			item.version
		);
	}
	if ( 'fragment' === item.kind ) {
		return sprintf(
			/* translators: %d: version number. "Fragment" is the protocol's word for a short item that is not a thread. */
			_x(
				'Version %d, fragment',
				'an item’s current state',
				'soapbox-blyg'
			),
			item.version
		);
	}
	return sprintf(
		/* translators: %d: version number. "Thread" is the protocol's word for an ordinary item, such as a post. */
		_x( 'Version %d, thread', 'an item’s current state', 'soapbox-blyg' ),
		item.version
	);
}

/**
 * One entry of an item's history: what happened, and when.
 *
 * @param {Object} entry A changelog entry, with kind and at.
 * @return {string} The line.
 */
export function eventLine( entry ) {
	return 'withdrawn' === entry.kind
		? sprintf(
				/* translators: %s: date and time. A version of the item that records its withdrawal. */
				_x(
					'Withdrawn, %s',
					'an entry in an item’s history',
					'soapbox-blyg'
				),
				when( entry.at )
			)
		: sprintf(
				/* translators: %s: date and time. A version of the item that was published then. */
				_x(
					'Published, %s',
					'an entry in an item’s history',
					'soapbox-blyg'
				),
				when( entry.at )
			);
}

/**
 * Says that a version is pinned, and by whom if that is known.
 *
 * @param {Object} entry A changelog entry.
 * @return {string} The label.
 */
export function pinnedLabel( entry ) {
	return entry.pinned_by
		? sprintf(
				/* translators: %s: a user's name. Said of one version of an item. */
				_x(
					'Pinned by %s',
					'a version that has been pinned',
					'soapbox-blyg'
				),
				entry.pinned_by
			)
		: _x( 'Pinned', 'a version that has been pinned', 'soapbox-blyg' );
}

/**
 * The text of the link to a pinned version's own document.
 *
 * @param {number} version Version number.
 * @return {string} The link text.
 */
export function versionLink( version ) {
	return sprintf(
		/* translators: %d: version number. The text of a link to that version's document. */
		_x(
			'Version %d',
			'link to a pinned version’s document',
			'soapbox-blyg'
		),
		version
	);
}
