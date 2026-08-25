import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            // Bunny em vez de Google Fonts: espelho de mesmo catálogo, sem
            // rastreamento — e os arquivos são baixados no BUILD e servidos
            // pelo próprio app (ver public/build/), nunca buscados do
            // navegador do cliente em tempo real. É o que permite usar
            // webfont de verdade na hospedagem compartilhada.
            fonts: [
                bunny('Fraunces', {
                    weights: [400, 500, 600],
                }),
                bunny('Inter', {
                    weights: [400, 500, 600, 700],
                }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
