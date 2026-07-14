const path = require('path');

// Export a function so production detection keys off webpack's own
// `--mode` flag (package.json build runs `webpack --mode production`).
// The old `process.env.NODE_ENV === 'production'` check was always false —
// the build script never set NODE_ENV for the config process — so even
// "production" builds emitted eval-source-map development bundles
// (~1.79 MB committed to js/addons/travel_core and shipped to every hotel
// product page, and eval() breaks under a strict CSP).
module.exports = (env, argv) => {
    const isProduction = argv.mode === 'production';

    return {
        // Production: no devtool — the committed bundles are the artifact;
        // no eval(), no inline/base64 source maps, no sibling .map files.
        devtool: isProduction ? false : 'eval-source-map',
        entry: {
            'react19-bundle': './src/index.jsx',
        },
        output: {
            filename: '[name].js',
            path: path.resolve(__dirname, '..', 'js', 'addons', 'travel_core'),
        },
        optimization: {
            splitChunks: {
                cacheGroups: {
                    reactVendor: {
                        test: /[\\/]node_modules[\\/](react|react-dom|scheduler)[\\/]/,
                        name: 'react-vendor',
                        chunks: 'all',
                        priority: 10,
                    },
                },
            },
        },
        module: {
            rules: [
                {
                    test: /\.jsx?$/,
                    exclude: /node_modules/,
                    use: {
                        loader: 'babel-loader',
                        options: {
                            presets: [
                                ['@babel/preset-env', {
                                    targets: '> 1%, not dead',
                                    modules: false,
                                }],
                                ['@babel/preset-react', {
                                    runtime: 'automatic',
                                }],
                            ],
                        },
                    },
                },
            ],
        },
        resolve: {
            extensions: ['.js', '.jsx'],
        },
    };
};
