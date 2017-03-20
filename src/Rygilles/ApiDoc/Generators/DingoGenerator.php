<?php

namespace Rygilles\ApiDoc\Generators;

use Exception;
use Illuminate\Console\Command;

class DingoGenerator extends \Mpociot\ApiDoc\Generators\DingoGenerator
{
	/**
	 * The parent command object
	 *
	 * @var Command
	 */
	protected $parentCommand = null;

	/**
	 * Set parent command object
	 *
	 * @param Command $command
	 */
	public function setParentCommand(Command $command)
	{
		$this->parentCommand = $command;
	}

	/**
	 * Return defined parent command object
	 *
	 * @return Command|null
	 */
	public function getParentCommand()
	{
		return $this->parentCommand;
	}

	/**
	 * {@inheritdoc}
	 */
	public function callRoute($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
	{
		$dispatcher = app('Dingo\Api\Dispatcher')->raw();

		// Rygilles : added extra headers for json requesting
		$server = collect([
			'Content-Type' => 'application/json',
			'Accept' => 'application/json',
		])->merge($server)->toArray();

		// Rygilles : ActAsUser with passport guard "api"
		$user = auth()->user();
		if ($user) {
			auth()->guard('api')->setUser($user);
		}

		collect($server)->map(function ($key, $value) use ($dispatcher) {
			$dispatcher->header($key, $value);
		});

		// Rygilles : Extra console output logs
		$this->getParentCommand()->comment("\r\n" . 'Calling route [method="' . $method . '", "uri=' . ltrim($uri, '/') . '", parameters=["' . implode('", "', $parameters) . '"]' /* with headers : ' . "\r\n" . print_r($server, true) . "\r\n"*/);

		try {
			$resp = call_user_func_array([$dispatcher, strtolower($method)], [$uri]);
			$this->getParentCommand()->comment('Response :' . "\r\n" . print_r(json_decode($resp->getContent()), true));
		} catch (\Exception $e) {
			// Rygilles : WIP debug log
			$this->getParentCommand()->warn('Call failed, ignore response : ' . get_class($e) . ' : '  . $e->getMessage() . "\r\n" . 'file ' . $e->getFile() . ' at line ' . $e->getLine());
		}

		return $resp;
	}
}
