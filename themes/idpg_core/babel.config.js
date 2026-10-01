module.exports = {
	presets: [
		[
			'@babel/preset-env',
			{
				targets: {
					browsers: [ 'extends @wordpress/browserslist-config' ],
				},
			},
		],
	],
};