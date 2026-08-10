/**
 * Admin behaviour: confirmation dialogs and the pretty URL browser check.
 */
( function () {
	'use strict';

	var settings = window.sfirAdmin || {};

	function confirmOn( id, message ) {
		var button = document.getElementById( id );

		if ( ! button || ! message ) {
			return;
		}

		button.addEventListener( 'click', function ( event ) {
			if ( ! window.confirm( message ) ) {
				event.preventDefault();
			}
		} );
	}

	/**
	 * Replaces the check block with the success message.
	 */
	function showSuccess() {
		var container = document.getElementById( 'sfir-check' );

		if ( ! container ) {
			return;
		}

		var notice = document.createElement( 'div' );
		notice.className = 'notice notice-success inline sfir-check';

		var paragraph = document.createElement( 'p' );

		var title = document.createElement( 'strong' );
		title.textContent = settings.successTitle || '';
		paragraph.appendChild( title );
		paragraph.appendChild( document.createTextNode( ' ' + ( settings.successText || '' ) ) );

		notice.appendChild( paragraph );

		container.innerHTML = '';
		container.appendChild( notice );
	}

	/**
	 * Tells the site that the browser could load a freshly generated image.
	 */
	function reportSuccess() {
		if ( ! settings.ajaxUrl || ! settings.nonce ) {
			return;
		}

		var body = new FormData();
		body.append( 'action', settings.ajaxAction );
		body.append( '_wpnonce', settings.nonce );

		window
			.fetch( settings.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: body,
			} )
			.then( function ( response ) {
				if ( response.ok ) {
					showSuccess();
				}
			} )
			.catch( function () {
				// Nothing to do: the page keeps its "not confirmed" state.
			} );
	}

	/**
	 * Fetches a signed cache URL that does not exist yet, exactly the way a
	 * visitor's browser would. A server side request would prove nothing.
	 */
	function runCheck() {
		if ( ! settings.probeUrl || ! window.fetch ) {
			return;
		}

		window
			.fetch( settings.probeUrl, { cache: 'no-store', credentials: 'omit' } )
			.then( function ( response ) {
				var type = response.headers.get( 'content-type' ) || '';

				if ( response.ok && 0 === type.indexOf( 'image/' ) ) {
					reportSuccess();
				}
			} )
			.catch( function () {
				// Leaves the honest "not confirmed" state in place.
			} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		confirmOn( 'sfir-clear-cache', settings.confirmCache );
		confirmOn( 'sfir-clear-log', settings.confirmLog );
		runCheck();
	} );
}() );
