import { useState } from '@wordpress/element';
import { Modal, Button, CheckboxControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import useCalendarStore from '../store';

/**
 * Shown when a published post is dragged into the future. Moving it there
 * unpublishes and re-schedules it, so we confirm the intent first. A
 * "don't ask again" choice is persisted per-user (localStorage) by the store.
 */
export default function RescheduleConfirmDialog() {
	const { pendingReschedule, confirmReschedule, cancelReschedule } =
		useCalendarStore();
	const [ dontAsk, setDontAsk ] = useState( false );

	if ( ! pendingReschedule ) {
		return null;
	}

	return (
		<Modal
			title={ __( 'Unpublish and reschedule?', 'cf-content-calendar' ) }
			onRequestClose={ cancelReschedule }
			className="cf-cal-reschedule-modal"
		>
			<p>
				{ sprintf(
					/* translators: %s is a date and time, e.g. "Jul 5, 2026, 9:00 AM". */
					__(
						'You are moving a published post into the future. This will unpublish it and reschedule it for %s. Are you sure?',
						'cf-content-calendar'
					),
					pendingReschedule.whenLabel
				) }
			</p>

			<CheckboxControl
				__nextHasNoMarginBottom
				label={ __( "Don't ask me again", 'cf-content-calendar' ) }
				checked={ dontAsk }
				onChange={ setDontAsk }
			/>

			<div className="cf-cal-modal-actions">
				<Button variant="tertiary" onClick={ cancelReschedule }>
					{ __( 'Cancel', 'cf-content-calendar' ) }
				</Button>
				<Button
					variant="primary"
					onClick={ () => confirmReschedule( dontAsk ) }
				>
					{ __( 'Unpublish', 'cf-content-calendar' ) }
				</Button>
			</div>
		</Modal>
	);
}
