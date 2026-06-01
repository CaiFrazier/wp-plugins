import { useEffect } from '@wordpress/element';
import { Notice } from '@wordpress/components';
import { useStore } from '../store';
import PostPicker from './PostPicker';
import Grid from './Grid';
import Toolbar from './Toolbar';
import DebugPanel from './DebugPanel';

export default function App() {
	const { initPostTypes, notices, dismissNotice } = useStore();

	useEffect( () => {
		initPostTypes();
	}, [] );

	return (
		<div className="bme-app">
			{ notices.map( ( n ) => (
				<Notice
					key={ n.id }
					status={ n.type }
					onRemove={ () => dismissNotice( n.id ) }
				>
					{ n.message }
				</Notice>
			) ) }
			<Toolbar />
			<div className="bme-layout">
				<PostPicker />
				<Grid />
			</div>
			<DebugPanel />
		</div>
	);
}
