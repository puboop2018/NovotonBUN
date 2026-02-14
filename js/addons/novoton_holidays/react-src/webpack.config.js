const path = require('path');

module.exports = {
    entry: './src/index.jsx',
    output: {
        filename: 'react19-bundle.js',
        path: path.resolve(__dirname, '..'),
        iife: true,
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
