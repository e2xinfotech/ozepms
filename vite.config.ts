import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import { globSync } from 'node:fs';

// Multi-page app: every file in resources/js/pages is its own entry point,
// so each page downloads only the code it needs (shared code is split into common chunks).
const pages = globSync('resources/js/pages/**/*.tsx').filter((f) => !f.includes('/_'));

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', ...pages],
            refresh: true,
        }),
        react(),
    ],
    // The dev server listens on localhost so its address can be named in the Content-Security-Policy.
    server: {
        host: 'localhost',
        port: 5173,
        strictPort: true,
    },
    resolve: {
        alias: { '@': '/resources/js' },
    },
    build: {
        sourcemap: false,
        chunkSizeWarningLimit: 600,
    },
});
