/**
 * Confirmation dialogs for the SFimageResizer maintenance buttons.
 */
( function () {
	'use strict';

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

	document.addEventListener( 'DOMContentLoaded', function () {
		var settings = window.sfirAdmin || {};

		confirmOn( 'sfir-clear-cache', settings.confirmCache );
		confirmOn( 'sfir-clear-log', settings.confirmLog );
	} );
}() );
