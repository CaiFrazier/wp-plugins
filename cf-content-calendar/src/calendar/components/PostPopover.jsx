import { useRef, useLayoutEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { parseLocalDate } from '../utils/dates';

const STATUS_LABELS = {
	publish: __( 'Published', 'cf-content-calendar' ),
	future: __( 'Scheduled', 'cf-content-calendar' ),
	draft: __( 'Draft', 'cf-content-calendar' ),
	pending: __( 'Pending', 'cf-content-calendar' ),
	private: __( 'Private', 'cf-content-calendar' ),
};

export default function PostPopover( { post, typeLabel, id } ) {
	const ref = useRef( null );
	// Flip classes when the popover would overflow the viewport at grid edges.
	const [ flip, setFlip ] = useState( { right: false, up: false } );

	useLayoutEffect( () => {
		const el = ref.current;
		if ( ! el ) {
			return;
		}
		const rect = el.getBoundingClientRect();
		setFlip( {
			right: rect.right > window.innerWidth - 8,
			up: rect.bottom > window.innerHeight - 8,
		} );
	}, [] );

	const date = parseLocalDate( post.date );
	const timeStr = date.toLocaleTimeString( undefined, {
		hour: '2-digit',
		minute: '2-digit',
	} );
	const dateStr = date.toLocaleDateString( undefined, {
		month: 'short',
		day: 'numeric',
		year: 'numeric',
	} );

	const className =
		'cf-cal-popover' +
		( flip.right ? ' is-flip-right' : '' ) +
		( flip.up ? ' is-flip-up' : '' );

	return (
		// Not role="tooltip": it contains an interactive Edit link, which
		// tooltips must not. It's a hovercard referenced via aria-describedby.
		<div ref={ ref } id={ id } className={ className }>
			<p className="cf-cal-popover-title">
				{ post.title || __( '(no title)', 'cf-content-calendar' ) }
			</p>
			<ul className="cf-cal-popover-meta">
				<li>
					<span className="cf-cal-popover-label">
						{ __( 'Type', 'cf-content-calendar' ) }
					</span>
					{ typeLabel }
				</li>
				<li>
					<span className="cf-cal-popover-label">
						{ __( 'Status', 'cf-content-calendar' ) }
					</span>
					{ STATUS_LABELS[ post.status ] || post.status }
				</li>
				<li>
					<span className="cf-cal-popover-label">
						{ __( 'Date', 'cf-content-calendar' ) }
					</span>
					{ dateStr }
					{ 'future' === post.status || 'draft' === post.status
						? ` ${ timeStr }`
						: '' }
				</li>
			</ul>
			{ post.edit_link && (
				<a
					href={ post.edit_link }
					className="cf-cal-popover-edit button button-small"
				>
					{ __( 'Edit', 'cf-content-calendar' ) }
				</a>
			) }
		</div>
	);
}
