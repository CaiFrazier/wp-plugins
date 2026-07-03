import { useState, useRef, useEffect, useId } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import PostPopover from './PostPopover';

export default function PostChip( { post } ) {
	const [ hovered, setHovered ] = useState( false );
	const chipRef = useRef( null );
	const hideTimer = useRef( null );
	const popoverId = useId();

	const cfCalData = window.cfCalData || {};
	const typeLabel =
		( cfCalData.postTypes || {} )[ post.post_type ] || post.post_type;
	const displayTitle =
		post.title || __( '(no title)', 'cf-content-calendar' );

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

	function handleKeyDown( e ) {
		// Escape dismisses the popover for keyboard users.
		if ( e.key === 'Escape' && hovered ) {
			e.stopPropagation();
			setHovered( false );
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
		// Hover region so the cursor can travel chip → popover without it
		// vanishing. The keyboard-equivalent lives on the interactive chip
		// below (onFocus/onBlur to open, Escape to dismiss).
		// eslint-disable-next-line jsx-a11y/no-noninteractive-element-interactions
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
				onKeyDown={ handleKeyDown }
				tabIndex={ 0 }
				role="button"
				aria-describedby={ hovered ? popoverId : undefined }
				aria-label={ sprintf(
					/* translators: 1: post title, 2: post type, 3: post status. */
					__( '%1$s, %2$s (%3$s)', 'cf-content-calendar' ),
					displayTitle,
					typeLabel,
					post.status
				) }
			>
				<span className="cf-cal-chip-type">{ typeLabel }</span>
				<span className="cf-cal-chip-title">{ displayTitle }</span>
			</div>

			{ hovered && (
				<PostPopover
					post={ post }
					typeLabel={ typeLabel }
					id={ popoverId }
				/>
			) }
		</div>
	);
}
