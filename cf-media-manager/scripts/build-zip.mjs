#!/usr/bin/env node
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname( fileURLToPath( import.meta.url ) );
const releaseScript = path.resolve( __dirname, '../../scripts/release.mjs' );

execFileSync( 'node', [ releaseScript, 'cf-media-manager' ], { stdio: 'inherit' } );
