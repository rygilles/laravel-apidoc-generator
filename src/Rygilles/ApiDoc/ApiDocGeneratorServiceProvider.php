<?php

namespace Rygilles\ApiDoc;

use Mpociot\ApiDoc\Commands\UpdateDocumentation;
use Rygilles\ApiDoc\Commands\GenerateDocumentation;

class ApiDocGeneratorServiceProvider extends \Mpociot\ApiDoc\ApiDocGeneratorServiceProvider
{
	/**
	 * Register the API doc commands.
	 *
	 * @return void
	 */
	public function register()
	{
		$this->app->singleton('apidoc.generate', function () {
			return new GenerateDocumentation();
		});
		$this->app->singleton('apidoc.update', function () {
			return new UpdateDocumentation();
		});

		$this->commands([
			'apidoc.generate',
			'apidoc.update',
		]);
	}
}
