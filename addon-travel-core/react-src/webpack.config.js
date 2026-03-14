const path = require('path');

module.exports = {
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
