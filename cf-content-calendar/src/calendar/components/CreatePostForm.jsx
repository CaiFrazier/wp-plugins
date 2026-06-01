import { useState, useRef, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import useCalendarStore from '../store';
import { formatDate } from '../utils/dates';

const cfCalData = window.cfCalData || {};

export default function CreatePostForm( { date, onClose } ) {
	const { createPost } = useCalendarStore();
	const titleRef = useRef( null );
	const [ title, setTitle ] = useState( '' );
	const [ postType, setPostType ] = useState(
		Object.keys( cfCalData.postTypes || {} )[ 0 ] || 'post'
	);
	const [ status, setStatus ] = useState( 'draft' );
	const [ authorId, setAuthorId ] = useState( 0 );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState( null );

	const postTypes = cfCalData.postTypes || {};
	const canPublish = cfCalData.canPublish || false;
	const authors = cfCalData.authors || {};
	const showAuthors =
		cfCalData.canEditOthers && Object.keys( authors ).length > 0;

	// The form only mounts when the user explicitly clicks a day slot, so
	// moving focus into the title field is expected. Done via ref rather than
	// the autoFocus attribute (jsx-a11y/no-autofocus).
	useEffect( () => {
		titleRef.current?.focus();
	}, [] );

	async function handleSubmit( e ) {
		e.preventDefault();
		if ( ! title.trim() ) {
			return;
		}

		setSaving( true );
		setError( null );
		try {
			await createPost( {
				title,
				post_type: postType,
				post_date: formatDate( date ),
				status: canPublish && 'future' === status ? 'future' : 'draft',
				author_id: showAuthors ? authorId : 0,
			} );
			onClose();
		} catch ( err ) {
			setError(
				err.message ||
					__( 'Could not create post.', 'cf-content-calendar' )
			);
			setSaving( false );
		}
	}

	return (
		<form className="cf-cal-create-form" onSubmit={ handleSubmit }>
			{ error && <p className="cf-cal-form-error">{ error }</p> }

			<input
				ref={ titleRef }
				type="text"
				className="cf-cal-form-title widefat"
				placeholder={ __( 'Post title…', 'cf-content-calendar' ) }
				value={ title }
				onChange={ ( e ) => setTitle( e.target.value ) }
				required
			/>

			{ Object.keys( postTypes ).length > 1 && (
				<select
					className="cf-cal-form-type"
					value={ postType }
					onChange={ ( e ) => setPostType( e.target.value ) }
				>
					{ Object.entries( postTypes ).map( ( [ slug, label ] ) => (
						<option key={ slug } value={ slug }>
							{ label }
						</option>
					) ) }
				</select>
			) }

			{ showAuthors && (
				<select
					className="cf-cal-form-author"
					value={ authorId }
					onChange={ ( e ) =>
						setAuthorId( parseInt( e.target.value, 10 ) )
					}
				>
					<option value={ 0 }>
						{ __( 'Default author (you)', 'cf-content-calendar' ) }
					</option>
					{ Object.entries( authors ).map( ( [ id, name ] ) => (
						<option key={ id } value={ id }>
							{ name }
						</option>
					) ) }
				</select>
			) }

			{ canPublish && (
				<div className="cf-cal-form-status">
					<label htmlFor="cf-cal-status-draft">
						<input
							id="cf-cal-status-draft"
							type="radio"
							name="cf-cal-status"
							value="draft"
							checked={ 'draft' === status }
							onChange={ () => setStatus( 'draft' ) }
						/>{ ' ' }
						{ __( 'Save as Draft', 'cf-content-calendar' ) }
					</label>
					<label htmlFor="cf-cal-status-future">
						<input
							id="cf-cal-status-future"
							type="radio"
							name="cf-cal-status"
							value="future"
							checked={ 'future' === status }
							onChange={ () => setStatus( 'future' ) }
						/>{ ' ' }
						{ __( 'Schedule', 'cf-content-calendar' ) }
					</label>
				</div>
			) }

			<div className="cf-cal-form-actions">
				<button
					type="submit"
					className="button button-primary"
					disabled={ saving }
				>
					{ saving
						? __( 'Saving…', 'cf-content-calendar' )
						: __( 'Create', 'cf-content-calendar' ) }
				</button>
				<button type="button" className="button" onClick={ onClose }>
					{ __( 'Cancel', 'cf-content-calendar' ) }
				</button>
			</div>
		</form>
	);
}
