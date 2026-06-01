import { useState, useRef, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import PostPopover from './PostPopover';

export default function PostChip( { post } ) {
	const [ hovered, setHovered ] = useState( false );
	const chipRef = useRef( null );
	const hideTimer = useRef( null );

	const cfCalData = window.cfCalData || {};
	const typeLabel =
		( cfCalData.postTypes || {} )[ post.post_type ] || post.post_type;

	// Clear any pending hide timer on unmount.
	useEffect( () => () => clearTimeout( hideTimer.current ), [] );

	function show() {
		clearTimeout( hideTimer.current );
		setHovered( true );
	}

	// Delay hiding so the cursor can travel from the chip to the popover
	// (and reach the quick-edit link) without it vanishing.
	function scheduleHide() {
		clearTimeout( hideTimer.current );
		hideTimer.current = setTimeout( () => setHovered( false ), 150 );
	}

	function handleDragStart( e ) {
		e.dataTransfer.effectAllowed = 'move';
		e.dataTransfer.setData( 'text/plain', String( post.id ) );
		setHovered( false );
		if ( chipRef.current ) {
			chipRef.current.classList.add( 'is-dragging' );
		}
	}

	function handleDragEnd() {
		if ( chipRef.current ) {
			chipRef.current.classList.remove( 'is-dragging' );
		}
	}

	const statusClass = [
		'publish',
		'future',
		'draft',
		'pending',
		'private',
	].includes( post.status )
		? `status-${ post.status }`
		: '';

	return (
		<div
			className="cf-cal-chip-wrap"
			onMouseEnter={ show }
			onMouseLeave={ scheduleHide }
		>
			<div
				ref={ chipRef }
				className={ `cf-cal-chip ${ statusClass }` }
				draggable
				onDragStart={ handleDragStart }
				onDragEnd={ handleDragEnd }
				onFocus={ show }
				onBlur={ scheduleHide }
				tabIndex={ 0 }
				role="button"
				aria-label={ `${ post.title } — ${ typeLabel } (${ post.status })` }
			>
				<span className="cf-cal-chip-type">{ typeLabel }</span>
				<span className="cf-cal-chip-title">
					{ post.title || __( '(no title)', 'cf-content-calendar' ) }
				</span>
			</div>

			{ hovered && <PostPopover post={ post } typeLabel={ typeLabel } /> }
		</div>
	);
}
