import {defineConfig} from 'vite';
import {resolve} from 'path';
import commonjs from '@rollup/plugin-commonjs';
import {nodeResolve} from '@rollup/plugin-node-resolve';

export default defineConfig({
    root: '.',
    build: {
        outDir: 'build',
        emptyOutDir: false,
        cssCodeSplit: false,
        sourcemap: true,
        target: 'es2018',
        lib: false,
        rollupOptions: {
            input: resolve(__dirname, 'src/_sayanet/public/js/scripts.vite.js'),
            output: {
                entryFileNames: '_sayanet/public/js/scripts.js',
                assetFileNames: asset => {
                    if (asset.name && asset.name.endsWith('.css')) {
                        return '_sayanet/public/css/styles.css';
                    }
                    return '_sayanet/public/assets/[name][extname]';
                },
                chunkFileNames: '_sayanet/public/js/[name].js',
                format: 'iife',
                name: 'sayanet'
            },
            external: []
        }
    },
    css: {
        preprocessorOptions: {
            less: {
                javascriptEnabled: true
            }
        }
    },
    resolve: {
        alias: {
            jsdom: false
        }
    },
    plugins: [
        nodeResolve({
            browser: true,
            preferBuiltins: false
        }),
        commonjs({
            include: [/src\/.*\.js$/, /node_modules/],
            transformMixedEsModules: true,
            dynamicRequireTargets: ['src/_sayanet/public/js/lib/main/*.js'],
            ignoreDynamicRequires: false
        })
    ]
});
