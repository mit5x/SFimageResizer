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
	 * Shows the outcome of one half of the configuration check.
	 *
	 * @param {Element} row     The row to update.
	 * @param {boolean} working Whether the image loaded.
	 */
	function showOutcome( row, working ) {
		row.classList.remove( 'is-unknown' );
		row.classList.toggle( 'is-working', working );
		row.classList.toggle( 'is-broken', ! working );

		var mark = row.querySelector( '.sfir-check-mark' );

		if ( mark ) {
			mark.textContent = working ? '\u2713' : '\u2717';
		}

		var state = row.querySelector( '.sfir-check-state' );

		if ( state ) {
			state.textContent = working
				? ( settings.workingLabel || '' ) + ' ' + ( settings.checkedNow || '' )
				: ( settings.failedLabel || '' ) + ' ' + ( settings.failedText || '' );
		}
	}

	/**
	 * Tells the site which of the two checks the browser could complete.
	 *
	 * @param {string} which Name of the check that answered.
	 */
	function reportSuccess( which ) {
		if ( ! settings.ajaxUrl || ! settings.nonce || ! window.fetch ) {
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
			.catch( function () {
				// The row already shows the outcome; only the memory of it is lost.
			} );
	}

	/**
	 * Watches the two test images.
	 *
	 * Their own load and error events settle the check. An image element is
	 * used rather than fetch() on purpose: it is the same request a visitor's
	 * browser makes for a real image, it is not affected by content blockers,
	 * and it puts the answer on the screen where it can be seen.
	 */
	function runCheck() {
		var rows = document.querySelectorAll( '.sfir-check-row' );

		Array.prototype.forEach.call( rows, function ( row ) {
			var image = row.querySelector( 'img.sfir-probe' );
			var which = row.getAttribute( 'data-check' );

			if ( ! image || ! which ) {
				return;
			}

			function settle( working ) {
				showOutcome( row, working );

				if ( working ) {
					reportSuccess( which );
				}
			}

			// A cached image can be complete before the handlers are attached.
			if ( image.complete ) {
				settle( image.naturalWidth > 0 );
				return;
			}

			image.addEventListener( 'load', function () {
				settle( true );
			} );

			image.addEventListener( 'error', function () {
				settle( false );
			} );
		} );
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
