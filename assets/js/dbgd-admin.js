/* global DBGD */
( function () {
	'use strict';

	var pending = null; // Pending restore selection { source, drive_id, path }.

	function $( sel, ctx ) {
		return ( ctx || document ).querySelector( sel );
	}

	function post( action, data ) {
		var body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', DBGD.nonce );
		Object.keys( data || {} ).forEach( function ( k ) {
			body.append( k, data[ k ] );
		} );
		return fetch( DBGD.ajax, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} ).then( function ( r ) {
			return r.json();
		} );
	}

	function setProgress( box, pct, msg ) {
		if ( ! box ) {
			return;
		}
		box.hidden = false;
		var bar = $( '.dbgd-progress__bar span', box );
		var m = $( '.dbgd-progress__msg', box );
		if ( bar ) {
			bar.style.width = Math.max( 0, Math.min( 100, pct ) ) + '%';
		}
		if ( m ) {
			m.textContent = msg || '';
		}
	}

	function renderLog( log ) {
		var el = $( '#dbgd-log' );
		if ( ! el || ! log ) {
			return;
		}
		el.innerHTML = '';
		log.slice().reverse().forEach( function ( entry ) {
			var line = document.createElement( 'div' );
			line.className = 'dbgd-log__line dbgd-log__line--' + ( entry.level || 'info' );
			line.textContent = '[' + entry.time + '] ' + entry.msg;
			el.appendChild( line );
		} );
	}

	/* ---------------- Backup ---------------- */

	function startBackup( destination ) {
		var box = $( '#dbgd-backup-progress' );
		setProgress( box, 1, 'Starting…' );
		toggleButtons( true );
		post( 'dbgd_backup_start', { destination: destination } ).then( function ( res ) {
			if ( ! res.success ) {
				setProgress( box, 0, ( res.data && res.data.message ) || DBGD.i18n.failed );
				toggleButtons( false );
				return;
			}
			runBackupStep();
		} );
	}

	function runBackupStep() {
		var box = $( '#dbgd-backup-progress' );
		post( 'dbgd_backup_step', {} ).then( function ( res ) {
			if ( ! res.success ) {
				setProgress( box, 0, DBGD.i18n.failed );
				toggleButtons( false );
				return;
			}
			var job = res.data;
			setProgress( box, job.progress, job.message );
			renderLog( job.log );
			if ( job.status === 'done' ) {
				setProgress( box, 100, job.message || DBGD.i18n.done );
				toggleButtons( false );
				loadList();
				return;
			}
			if ( job.status === 'error' ) {
				setProgress( box, job.progress, ( job.error || DBGD.i18n.failed ) );
				toggleButtons( false );
				return;
			}
			runBackupStep();
		} ).catch( function () {
			// Network blip — retry shortly.
			setTimeout( runBackupStep, 2500 );
		} );
	}

	function toggleButtons( busy ) {
		[ '#dbgd-backup-drive', '#dbgd-backup-local', '#dbgd-upload-restore' ].forEach( function ( sel ) {
			var b = $( sel );
			if ( b ) {
				b.disabled = busy;
			}
		} );
	}

	/* ---------------- List ---------------- */

	function loadList() {
		post( 'dbgd_list_drive', {} ).then( function ( res ) {
			var tbody = $( '#dbgd-backup-list tbody' );
			if ( ! tbody ) {
				return;
			}
			tbody.innerHTML = '';
			if ( ! res.success ) {
				tbody.innerHTML = '<tr><td colspan="4">' + ( ( res.data && res.data.message ) || DBGD.i18n.failed ) + '</td></tr>';
				return;
			}
			var items = res.data.items || [];
			if ( ! items.length ) {
				tbody.innerHTML = '<tr><td colspan="4">No backups yet.</td></tr>';
				return;
			}
			items.forEach( function ( it ) {
				var tr = document.createElement( 'tr' );

				var c1 = document.createElement( 'td' );
				c1.textContent = it.name;
				tr.appendChild( c1 );

				var c2 = document.createElement( 'td' );
				c2.textContent = it.where;
				tr.appendChild( c2 );

				var c3 = document.createElement( 'td' );
				c3.textContent = it.size;
				tr.appendChild( c3 );

				var c4 = document.createElement( 'td' );

				var restore = document.createElement( 'button' );
				restore.className = 'button button-small';
				restore.textContent = 'Restore';
				restore.addEventListener( 'click', function () {
					pending = { source: it.source, drive_id: it.drive_id || '', path: it.path || '' };
					showRestoreBox();
				} );
				c4.appendChild( restore );

				if ( it.can_dl && it.path ) {
					var dl = document.createElement( 'a' );
					dl.className = 'button button-small';
					dl.textContent = 'Download';
					dl.href = DBGD.adminPost + '?action=dbgd_download&_wpnonce=' + encodeURIComponent( DBGD.dlNonce ) + '&path=' + encodeURIComponent( it.path );
					c4.appendChild( document.createTextNode( ' ' ) );
					c4.appendChild( dl );
				}

				var del = document.createElement( 'button' );
				del.className = 'button button-small button-link-delete';
				del.textContent = 'Delete';
				del.addEventListener( 'click', function () {
					if ( ! window.confirm( DBGD.i18n.confirmDelete ) ) {
						return;
					}
					post( 'dbgd_delete_backup', { drive_id: it.drive_id || '', path: it.path || '' } ).then( loadList );
				} );
				c4.appendChild( document.createTextNode( ' ' ) );
				c4.appendChild( del );

				tr.appendChild( c4 );
				tbody.appendChild( tr );
			} );
		} );
	}

	/* ---------------- Restore ---------------- */

	function showRestoreBox() {
		var box = $( '#dbgd-restore-box' );
		if ( box ) {
			box.hidden = false;
			box.scrollIntoView( { behavior: 'smooth', block: 'center' } );
		}
	}

	function startRestore() {
		if ( ! pending ) {
			return;
		}
		if ( ! window.confirm( DBGD.i18n.confirmRestore ) ) {
			return;
		}
		var box = $( '#dbgd-restore-progress' );
		var newUrl = ( $( '#dbgd-new-url' ) || {} ).value || DBGD.home;
		setProgress( box, 1, 'Starting…' );
		var data = {
			source: pending.source,
			new_url: newUrl,
			drive_id: pending.drive_id,
			path: pending.path
		};
		post( 'dbgd_restore_start', data ).then( function ( res ) {
			if ( ! res.success ) {
				setProgress( box, 0, ( res.data && res.data.message ) || DBGD.i18n.failed );
				return;
			}
			runRestoreStep( res.data.token );
		} );
	}

	function runRestoreStep( token ) {
		var box = $( '#dbgd-restore-progress' );
		var body = new FormData();
		body.append( 'action', 'dbgd_restore_step' );
		body.append( 'nonce', DBGD.nonce );
		body.append( 'token', token );
		fetch( DBGD.ajax, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( res ) {
				if ( ! res.success ) {
					setProgress( box, 0, ( res.data && res.data.message ) || DBGD.i18n.failed );
					return;
				}
				var st = res.data;
				setProgress( box, st.progress, st.message );
				renderLog( st.log );
				if ( st.status === 'done' ) {
					setProgress( box, 100, st.message || DBGD.i18n.done );
					return;
				}
				if ( st.status === 'error' ) {
					setProgress( box, st.progress, st.error || DBGD.i18n.failed );
					return;
				}
				runRestoreStep( token );
			} )
			.catch( function () {
				// DB swap can briefly drop the connection — keep polling with the token.
				setTimeout( function () {
					runRestoreStep( token );
				}, 3000 );
			} );
	}

	function uploadAndRestore() {
		var input = $( '#dbgd-upload-file' );
		if ( ! input || ! input.files || ! input.files.length ) {
			window.alert( 'Choose a .zip file first.' );
			return;
		}
		var box = $( '#dbgd-backup-progress' );
		setProgress( box, 5, 'Uploading…' );
		var body = new FormData();
		body.append( 'action', 'dbgd_upload_restore' );
		body.append( 'nonce', DBGD.nonce );
		body.append( 'file', input.files[ 0 ] );
		fetch( DBGD.ajax, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( res ) {
				if ( ! res.success ) {
					setProgress( box, 0, ( res.data && res.data.message ) || DBGD.i18n.failed );
					return;
				}
				box.hidden = true;
				pending = { source: 'local', drive_id: '', path: res.data.path };
				showRestoreBox();
			} );
	}

	/* ---------------- Wire up ---------------- */

	document.addEventListener( 'DOMContentLoaded', function () {
		var bd = $( '#dbgd-backup-drive' );
		var bl = $( '#dbgd-backup-local' );
		var rl = $( '#dbgd-refresh-list' );
		var ur = $( '#dbgd-upload-restore' );
		var dr = $( '#dbgd-do-restore' );
		var cr = $( '#dbgd-cancel-restore' );

		if ( bd ) {
			bd.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				startBackup( 'drive' );
			} );
		}
		if ( bl ) {
			bl.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				startBackup( 'local' );
			} );
		}
		if ( rl ) {
			rl.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				loadList();
			} );
		}
		if ( ur ) {
			ur.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				uploadAndRestore();
			} );
		}
		if ( dr ) {
			dr.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				startRestore();
			} );
		}
		if ( cr ) {
			cr.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				$( '#dbgd-restore-box' ).hidden = true;
				pending = null;
			} );
		}

		if ( $( '#dbgd-backup-list' ) ) {
			loadList();
		}
	} );
}() );
