import wpPot from 'wp-pot';

wpPot( {
	destFile: 'languages/cf-chunked-upload.pot',
	domain: 'cf-chunked-upload',
	package: 'CF Chunked Upload',
	src: [ '**/*.php', '!vendor/**', '!node_modules/**', '!tests/**' ],
} );
