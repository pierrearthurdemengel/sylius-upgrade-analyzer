const Encore = require('@symfony/webpack-encore');

Encore
    .setOutputPath('public/build/')
    .setPublicPath('/build')
    .addEntry('app', './assets/js/app.js')
    .addEntry('admin', './assets/js/admin.js')
    .enableSassLoader()
    .enableSourceMaps(!Encore.isProduction())
;

module.exports = Encore.getWebpackConfig();
