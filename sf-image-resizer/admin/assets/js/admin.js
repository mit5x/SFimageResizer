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
	 * Marks one row of the configuration check as working.
	 *
	 * @param {string} which Name of the check that answered.
	 */
	function showWorking( which ) {
		var row = document.querySelector( '.sfir-check-row[data-check="' + which + '"]' );

		if ( ! row ) {
			return;
		}

		row.classList.remove( 'is-unknown' );
		row.classList.add( 'is-working' );

		var mark = row.querySelector( '.sfir-check-mark' );

		if ( mark ) {
			mark.textContent = '\u2713';
		}

		var state = row.querySelector( '.sfir-check-state' );

		if ( state ) {
			state.textContent =
				( settings.workingLabel || '' ) + ' ' + ( settings.checkedNow || '' );
		}

		var verdict = document.querySelector( '.sfir-verdict' );

		if ( verdict ) {
			verdict.classList.add( 'sfir-hidden' );
		}
	}

	/**
	 * Tells the site which of the two checks the browser could complete.
	 *
	 * @param {string} which Name of the check that answered.
	 */
	function reportSuccess( which ) {
		if ( ! settings.ajaxUrl || ! settings.nonce ) {
			return;
		}

		var body = new FormData();
		body.append( 'action', settings.ajaxAction );
		body.append( '_wpnonce', settings.nonce );
		body.append( 'which', which );

		window
			.fetch( settings.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: body,
			} )
			.then( function ( response ) {
				if ( response.ok ) {
					showWorking( which );
				}
			} )
			.catch( function () {
				// Nothing to do: the row keeps its "not confirmed" state.
			} );
	}

	/**
	 * Loads one probe URL exactly the way a visitor's browser would. A server
	 * side request would prove nothing about what a browser experiences.
	 *
	 * @param {string} url   URL to load.
	 * @param {string} which Name of the check this URL stands for.
	 */
	function runProbe( url, which ) {
		if ( ! url || ! window.fetch ) {
			return;
		}

		window
			.fetch( url, { cache: 'no-store', credentials: 'omit' } )
			.then( function ( response ) {
				var type = response.headers.get( 'content-type' ) || '';

				if ( response.ok && 0 === type.indexOf( 'image/' ) ) {
					reportSuccess( which );
				}
			} )
			.catch( function () {
				// Leaves the honest "not confirmed" state in place.
			} );
	}

	/**
	 * Runs both halves of the configuration check.
	 *
	 * They are independent: a server can support one and not the other, and
	 * that is exactly what the screen wants to report.
	 */
	function runCheck() {
		runProbe( settings.renderUrl, settings.checkRender || 'render' );
		runProbe( settings.probeUrl, settings.checkRequest || 'request' );
	}

	/**
	 * Copies the Markdown reference to the clipboard.
	 */
	function setUpCopyButton() {
		var button = document.getElementById( 'sfir-copy-markdown' );

		if ( ! button ) {
			return;
		}

		button.addEventListener( 'click', function () {
			var field = document.getElementById( button.getAttribute( 'data-target' ) );
			var note = document.getElementById( 'sfir-copy-feedback' );

			if ( ! field ) {
				return;
			}

			function done( message ) {
				if ( note ) {
					note.textContent = message;
					window.setTimeout( function () {
						note.textContent = '';
					}, 4000 );
				}
			}

			if ( window.navigator.clipboard && window.navigator.clipboard.writeText ) {
				window.navigator.clipboard
					.writeText( field.value )
					.then( function () {
						done( settings.copied || '' );
					} )
					.catch( function () {
						field.select();
						done( settings.copyFailed || '' );
					} );
				return;
			}

			field.select();
			done( settings.copyFailed || '' );
		} );
	}

	/**
	 * Applies the language choice as soon as it changes.
	 */
	function setUpLanguagePicker() {
		var select = document.getElementById( 'sfir-locale' );

		if ( ! select || ! select.form ) {
			return;
		}

		var apply = document.getElementById( 'sfir-language-apply' );

		if ( apply ) {
			apply.classList.add( 'sfir-hidden' );
		}

		select.addEventListener( 'change', function () {
			select.form.submit();
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		confirmOn( 'sfir-clear-cache', settings.confirmCache );
		confirmOn( 'sfir-clear-log', settings.confirmLog );
		setUpCopyButton();
		setUpLanguagePicker();
		runCheck();
	} );
}() );
