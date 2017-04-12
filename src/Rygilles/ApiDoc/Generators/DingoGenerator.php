<?php

namespace Rygilles\ApiDoc\Generators;

use Exception;
use Faker\Factory;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Mpociot\ApiDoc\Parsers\RuleDescriptionParser as Description;
use Mpociot\Reflection\DocBlock;
use ReflectionClass;

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
	 * @param \Illuminate\Routing\Route $route
	 * @param array $bindings
	 * @param array $headers
	 * @param bool $withResponse
	 *
	 * @return array
	 */
	public function processRoute($route, $bindings = [], $headers = [], $withResponse = true)
	{
		$response = '';

		$routeAction = $route->getAction();
		$routeApiDocsSettings = $this->getRouteApiDocsSettings($routeAction['uses']);
		if ($withResponse && !in_array('no_call', $routeApiDocsSettings)) {
			try {
				$response = $this->getRouteResponse($route, $bindings, $headers);
			} catch (Exception $e) {
			}
		}

		if (in_array('no_call', $routeApiDocsSettings)) {
			$this->getParentCommand()->info('Ignore route call for ' . $routeAction['uses']);
		}

		// Rygilles : Fix for model tranformers
		// @link https://github.com/mpociot/laravel-apidoc-generator/issues/153
		if (is_object($response)) {
			$response = $response->content();
		}
		$routeGroup = $this->getRouteGroup($routeAction['uses']);
		$routeDescription = $this->getRouteDescription($routeAction['uses']);

		return $this->getParameters([
			'id' => md5($route->uri().':'.implode($route->getMethods())),
			'resource' => $routeGroup,
			'title' => $routeDescription['short'],
			/* Rygilles : Try to add model description dor this route (using resource name / route group) */
			'description' => $this->addRouteModelDescription($route),
			'methods' => $route->getMethods(),
			'uri' => $route->uri(),
			'parameters' => [],
			'response' => $response,
			/* Rygilles : add "binded" uri for blade template context usage */
			'bindedUri' => $this->addRouteModelBindings($route, $bindings),
			/* Rygilles : add bindings for blade template context usage */
			'bindings' => $bindings
		], $routeAction, $bindings);
	}

	/**
	 * Rygilles : Try to add model description for this route (using resource name / route group)
	 *
	 * @param \Illuminate\Routing\Route $route
	 * @return string
	 */
	protected function addRouteModelDescription($route)
	{
		$routeAction = $route->getAction();
		$routeGroup = $this->getRouteGroup($routeAction['uses']);
		$routeDescription = $this->getRouteDescription($routeAction['uses']);
		$routeMethods = $route->getMethods();

		list($routeControllerClass, $routeControllerMethod) = explode('@', $routeAction['uses']);

		$description = $routeDescription['long'];

		if (!$routeGroup || $routeGroup == 'general') {
			return $description;
		}

		if ($routeControllerMethod != 'index') {
			return $description;
		}

		// Check if a model exists with this resource name and try to grab extra information
		$className = '\\App\\Models\\' . $routeGroup;
		if (class_exists($className)) {
			$classInstance = new $className;

			$perPage = null;
			$perPageMin = null;
			$perPageMax = null;

			if (method_exists($classInstance, 'getPerPage')) {
				$perPage = $classInstance->getPerPage();
			}

			if (method_exists($classInstance, 'getPerPageMin')) {
				$perPageMin = $classInstance->getPerPageMin();
			}

			if (method_exists($classInstance, 'getPerPageMax')) {
				$perPageMax = $classInstance->getPerPageMax();
			}

			if (!is_null($perPage) && !is_null($perPageMin) && !is_null($perPageMax)) {
				$this->getParentCommand()->info('Resource "' . $routeGroup . '" model pagination found for index route. Long description modified.');
				$description .= "\n" . Description::parse('pagination')->with([$perPageMin, $perPageMax, $perPage])->getDescription();
			}
		}

		return $description;
	}

	/**
	 * Rygilles : Return route settings for documentation generation
	 *
	 * @param  \Illuminate\Routing\Route  $route
	 * @return string[]
	 */
	protected function getRouteApiDocsSettings($route)
	{
		list($class, $method) = explode('@', $route);
		$reflection = new ReflectionClass($class);
		$reflectionMethod = $reflection->getMethod($method);

		$comment = $reflectionMethod->getDocComment();
		$phpdoc = new DocBlock($comment);

		$settings = [];

		/*
		if ($phpdoc->hasTag('ApiDocsProfiles')) {
			$apiDocsProfilesTag = array_first($phpdoc->getTagsByName('ApiDocsProfiles'));
			try {
				$apiDocsProfiles = json_decode($apiDocsProfilesTag->getContent());
				if (is_null($apiDocsProfiles)) {
					$this->getParentCommand->warn('@ApiDocsProfile found on route ' . $route . ' but is not JSON formatted');
					return [];
				}

			} catch (\Exception $e) {
				$this->getParentCommand->warn('@ApiDocsProfile found on route ' . $route . ' but is not JSON formatted');
				return [];
			}
		}
		*/

		// Document the route but don't make a call (prevent DELETE...)
		if ($phpdoc->hasTag('ApiDocsNoCall')) {
			$settings[] = 'no_call';
		}

		return $settings;
    }

	/**
	 * @param array $routeData
	 * @param array $routeAction
	 * @param array $bindings
	 *
	 * @return mixed
	 */
	protected function getParameters($routeData, $routeAction, $bindings)
	{
		$validator = \Illuminate\Support\Facades\Validator::make([], $this->getRouteRules($routeAction['uses'], $bindings));
		foreach ($validator->getRules() as $attribute => $rules) {
			$attributeData = [
				'required' => false,
				'type' => null,
				'default' => '',
				'value' => '',
				'description' => [],
			];
			foreach ($rules as $ruleName => $rule) {
				// Rygilles : add rand to seed
				$this->parseRule($rule, $attribute, $attributeData, $routeData['id'] + rand(0, 1000));
			}
			$routeData['parameters'][$attribute] = $attributeData;
		}
		/*
		if (count($routeData['parameters']))
			dd($routeData);
		*/
		return $routeData;
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
			try {
				auth()->guard('api')->setUser($user);
			} catch (\Exception $e) {}
		}

		collect($server)->map(function ($key, $value) use ($dispatcher) {
			$dispatcher->header($key, $value);
		});

		// Rygilles : Extra console output logs
		//$this->getParentCommand()->comment("\r\n" . 'Calling route (method="' . $method . '", "uri=' . ltrim($uri, '/') . '", parameters=["' . implode('", "', $parameters) . '"])' /* with headers : ' . "\r\n" . print_r($server, true) . "\r\n"*/);

		try {
			$resp = call_user_func_array([$dispatcher, strtolower($method)], [$uri]);
			//$this->getParentCommand()->comment('Response :' . "\r\n" . print_r(json_decode($resp->getContent()), true));
		} catch (\Exception $e) {
			// Rygilles : WIP debug log
			//$this->getParentCommand()->warn('Call failed, ignore response : ' . get_class($e) . ' : '  . $e->getMessage() . "\r\n" . 'file ' . $e->getFile() . ' at line ' . $e->getLine());
		}

		return $resp;
	}

	/**
	 * @param  string  $rule
	 * @param  string  $ruleName
	 * @param  array  $attributeData
	 * @param  int  $seed
	 *
	 * @return void
	 */
	protected function parseRule($rule, $ruleName, &$attributeData, $seed)
	{
		$faker = Factory::create();
		$faker->seed(crc32($seed));

		$parsedRule = $this->parseStringRule($rule);
		$parsedRule[0] = $this->normalizeRule($parsedRule[0]);
		list($rule, $parameters) = $parsedRule;

		switch ($rule) {
			// Rygilles : UUID fake value
			case 'uuid':
				$attributeData['description'][] = Description::parse($rule)->getDescription();
				$attributeData['value'] = $faker->uuid;
				break;
			// Rygilles : md5 fake value
			case 'md5':
				$attributeData['description'][] = Description::parse($rule)->getDescription();
				$attributeData['value'] = $faker->md5;
				break;
			// Rygilles : strength fake value (password)
			case 'strength':
				$attributeData['description'][] = Description::parse($rule)->getDescription();
				$attributeData['value'] = $faker->password;
				break;
			case 'required':
				$attributeData['required'] = true;
				break;
			case 'accepted':
				$attributeData['required'] = true;
				$attributeData['type'] = 'boolean';
				$attributeData['value'] = true;
				break;
			case 'after':
				$attributeData['type'] = 'date';
				$attributeData['description'][] = Description::parse($rule)->with(date(DATE_RFC850, strtotime($parameters[0])))->getDescription();
				$attributeData['value'] = date(DATE_RFC850, strtotime('+1 day', strtotime($parameters[0])));
				break;
			case 'alpha':
				$attributeData['description'][] = Description::parse($rule)->getDescription();
				$attributeData['value'] = ucfirst($faker->words(3, true));
				break;
			case 'alpha_dash':
				$attributeData['description'][] = Description::parse($rule)->getDescription();
				break;
			case 'alpha_num':
				$attributeData['description'][] = Description::parse($rule)->getDescription();
				break;
			case 'in':
				$attributeData['description'][] = Description::parse($rule)->with($this->fancyImplode($parameters, ', ', ' or '))->getDescription();
				$attributeData['value'] = $faker->randomElement($parameters);
				break;
			case 'not_in':
				$attributeData['description'][] = Description::parse($rule)->with($this->fancyImplode($parameters, ', ', ' or '))->getDescription();
				$attributeData['value'] = ucfirst($faker->words(3, true));
				break;
			case 'min':
				$attributeData['description'][] = Description::parse($rule)->with($parameters)->getDescription();
				if (Arr::get($attributeData, 'type') === 'numeric' || Arr::get($attributeData, 'type') === 'integer') {
					$attributeData['value'] = $faker->numberBetween($parameters[0]);
				}
				break;
			case 'max':
				$attributeData['description'][] = Description::parse($rule)->with($parameters)->getDescription();
				if (Arr::get($attributeData, 'type') === 'numeric' || Arr::get($attributeData, 'type') === 'integer') {
					$attributeData['value'] = $faker->numberBetween(0, $parameters[0]);
				}
				break;
			case 'between':
				if (! isset($attributeData['type'])) {
					$attributeData['type'] = 'numeric';
				}
				$attributeData['description'][] = Description::parse($rule)->with($parameters)->getDescription();
				$attributeData['value'] = $faker->numberBetween($parameters[0], $parameters[1]);
				break;
			case 'before':
				$attributeData['type'] = 'date';
				$attributeData['description'][] = Description::parse($rule)->with(date(DATE_RFC850, strtotime($parameters[0])))->getDescription();
				$attributeData['value'] = date(DATE_RFC850, strtotime('-1 day', strtotime($parameters[0])));
				break;
			case 'date_format':
				$attributeData['type'] = 'date';
				$attributeData['description'][] = Description::parse($rule)->with($parameters)->getDescription();
				$attributeData['value'] = date($parameters[0]);
				break;
			case 'different':
				$attributeData['description'][] = Description::parse($rule)->with($parameters)->getDescription();
				break;
			case 'digits':
				$attributeData['type'] = 'numeric';
				$attributeData['description'][] = Description::parse($rule)->with($parameters)->getDescription();
				$attributeData['value'] = $faker->randomNumber($parameters[0], true);
				break;
			case 'digits_between':
				$attributeData['type'] = 'numeric';
				$attributeData['description'][] = Description::parse($rule)->with($parameters)->getDescription();
				break;
			case 'file':
				$attributeData['type'] = 'file';
				$attributeData['description'][] = Description::parse($rule)->getDescription();
				break;
			case 'image':
				$attributeData['type'] = 'image';
				$attributeData['description'][] = Description::parse($rule)->getDescription();
				break;
			case 'json':
				$attributeData['type'] = 'string';
				$attributeData['description'][] = Description::parse($rule)->getDescription();
				$attributeData['value'] = json_encode(['foo', 'bar', 'baz']);
				break;
			case 'mimetypes':
			case 'mimes':
				$attributeData['description'][] = Description::parse($rule)->with($this->fancyImplode($parameters, ', ', ' or '))->getDescription();
				break;
			case 'required_if':
				$attributeData['description'][] = Description::parse($rule)->with($this->splitValuePairs($parameters))->getDescription();
				break;
			case 'required_unless':
				$attributeData['description'][] = Description::parse($rule)->with($this->splitValuePairs($parameters))->getDescription();
				break;
			case 'required_with':
				$attributeData['description'][] = Description::parse($rule)->with($this->fancyImplode($parameters, ', ', ' or '))->getDescription();
				break;
			case 'required_with_all':
				$attributeData['description'][] = Description::parse($rule)->with($this->fancyImplode($parameters, ', ', ' and '))->getDescription();
				break;
			case 'required_without':
				$attributeData['description'][] = Description::parse($rule)->with($this->fancyImplode($parameters, ', ', ' or '))->getDescription();
				break;
			case 'required_without_all':
				$attributeData['description'][] = Description::parse($rule)->with($this->fancyImplode($parameters, ', ', ' and '))->getDescription();
				break;
			case 'same':
				$attributeData['description'][] = Description::parse($rule)->with($parameters)->getDescription();
				break;
			case 'size':
				$attributeData['description'][] = Description::parse($rule)->with($parameters)->getDescription();
				break;
			case 'timezone':
				$attributeData['description'][] = Description::parse($rule)->getDescription();
				$attributeData['value'] = $faker->timezone;
				break;
			case 'exists':
				$fieldName = isset($parameters[1]) ? $parameters[1] : $ruleName;
				$attributeData['description'][] = Description::parse($rule)->with([Str::singular($parameters[0]), $fieldName])->getDescription();
				break;
			case 'active_url':
				$attributeData['type'] = 'url';
				$attributeData['value'] = $faker->url;
				break;
			case 'regex':
				$attributeData['type'] = 'string';
				$attributeData['description'][] = Description::parse($rule)->with($parameters)->getDescription();
				break;
			case 'boolean':
				$attributeData['value'] = true;
				$attributeData['type'] = $rule;
				break;
			case 'array':
				$attributeData['value'] = ucfirst($faker->words(3, true));
				$attributeData['type'] = $rule;
				break;
			case 'date':
				$attributeData['value'] = $faker->date();
				$attributeData['type'] = $rule;
				break;
			case 'email':
				$attributeData['value'] = $faker->safeEmail;
				$attributeData['type'] = $rule;
				break;
			case 'string':
				$attributeData['value'] = ucfirst($faker->words(3, true));
				$attributeData['type'] = $rule;
				break;
			case 'integer':
				$attributeData['value'] = $faker->randomNumber();
				$attributeData['type'] = $rule;
				break;
			case 'numeric':
				$attributeData['value'] = $faker->randomNumber();
				$attributeData['type'] = $rule;
				break;
			case 'url':
				$attributeData['value'] = $faker->url;
				$attributeData['type'] = $rule;
				break;
			case 'ip':
				$attributeData['value'] = $faker->ipv4;
				$attributeData['type'] = $rule;
				break;
		}

		if ($attributeData['value'] === '') {
			$attributeData['value'] = ucfirst($faker->words(3, true));
		}

		if (is_null($attributeData['type'])) {
			$attributeData['type'] = 'string';
		}
	}
}
