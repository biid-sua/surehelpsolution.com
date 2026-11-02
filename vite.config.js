import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/css/site-modern.css', 'resources/js/site-modern.js', 'resources/css/game.css', 'resources/js/game.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
});
