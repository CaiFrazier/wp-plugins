import { useState } from '@wordpress/element';
import { TextControl, SelectControl, Button, CheckboxControl, Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { useStore } from '../store';
import PaginationControls from './PaginationControls';

const STATUS_OPTIONS = [
	{ label: __( 'All', 'cf-bulk-meta-editor' ), value: 'any' },
	{ label: __( 'Published', 'cf-bulk-meta-editor' ), value: 'publish' },
	{ label: __( 'Draft', 'cf-bulk-meta-editor' ), value: 'draft' },
	{ label: __( 'Pending', 'cf-bulk-meta-editor' ), value: 'pending' },
	{ label: __( 'Private', 'cf-bulk-meta-editor' ), value: 'private' },
];

export default function PostPicker() {
	const [ collapsed, setCollapsed ] = useState( false );

	const {
		pickerPosts,
		pickerTotal,
		pickerPages,
		pickerPage,
		pickerPerPage,
		pickerStatus,
		pickerSearch,
		pickerLoading,
		selectedIds,
		setPickerPage,
		setPickerPerPage,
		setPickerSearch,
		setPickerStatus,
		toggleSelectId,
		selectAllOnPage,
		clearPageSelection,
		loadSelected,
	} = useStore();

	const allPageSelected = pickerPosts.length > 0 && pickerPosts.every( ( p ) => selectedIds.has( p.id ) );

	return (
		<div className={ `bme-picker ${ collapsed ? 'is-collapsed' : '' }` }>
			<div className="bme-picker-header">
				<strong>{ __( 'Post Picker', 'cf-bulk-meta-editor' ) }</strong>
				<button className="bme-collapse-btn" onClick={ () => setCollapsed( ! collapsed ) }>
					{ collapsed ? '▶' : '◀' }
				</button>
			</div>

			{ ! collapsed && (
				<>
					<TextControl
						label={ __( 'Search', 'cf-bulk-meta-editor' ) }
						hideLabelFromVision
						placeholder={ __( 'Search posts…', 'cf-bulk-meta-editor' ) }
						value={ pickerSearch }
						onChange={ setPickerSearch }
					/>
					<SelectControl
						label={ __( 'Status', 'cf-bulk-meta-editor' ) }
						hideLabelFromVision
						value={ pickerStatus }
						options={ STATUS_OPTIONS }
						onChange={ setPickerStatus }
					/>

					<div className="bme-picker-select-all">
						<CheckboxControl
							label={ __( 'Select all on this page', 'cf-bulk-meta-editor' ) }
							checked={ allPageSelected }
							onChange={ ( v ) => ( v ? selectAllOnPage() : clearPageSelection() ) }
						/>
						{ selectedIds.size > 0 && (
							<span className="bme-picker-selected-count">
								{ selectedIds.size }{ ' ' }
								{ __( 'selected', 'cf-bulk-meta-editor' ) }
							</span>
						) }
					</div>

					<div className="bme-picker-list">
						{ pickerLoading && <Spinner /> }
						{ ! pickerLoading && pickerPosts.map( ( post ) => (
							<div key={ post.id } className="bme-picker-row">
								<CheckboxControl
									label={ post.title || `#${ post.id }` }
									checked={ selectedIds.has( post.id ) }
									onChange={ () => toggleSelectId( post.id ) }
								/>
								<StatusBadge status={ post.status } />
							</div>
						) ) }
					</div>

					<PaginationControls
						page={ pickerPage }
						pages={ pickerPages }
						perPage={ pickerPerPage }
						total={ pickerTotal }
						onPage={ setPickerPage }
						onPerPage={ setPickerPerPage }
						perPageOptions={ [ 25, 50, 100 ] }
					/>

					<Button
						variant="primary"
						className="bme-load-btn"
						onClick={ loadSelected }
						disabled={ ! selectedIds.size }
					>
						{ __( 'Load Selected', 'cf-bulk-meta-editor' ) }
						{ selectedIds.size > 0 && ` (${ selectedIds.size })` }
					</Button>
				</>
			) }
		</div>
	);
}

function StatusBadge( { status } ) {
	return (
		<span className={ `bme-status-badge bme-status-${ status }` }>{ status }</span>
	);
}
