<?php

/**
 * This file is part of the Nette Framework (https://nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Nette\Application\Routers;

use Nette;
use Nette\Application;
use Nette\Utils\Strings;


/**
 * The bidirectional route is responsible for mapping
 * HTTP request to an array for dispatch and vice-versa.
 */
class Route implements Application\IRouter
{
	use Nette\SmartObject;

	/** key used in metadata {@link Route::__construct} */
	public const
		VALUE = 'value',
		PATTERN = 'pattern',
		FILTER_IN = 'filterIn',
		FILTER_OUT = 'filterOut',
		FILTER_TABLE = 'filterTable',
		FILTER_STRICT = 'filterStrict';

	/** key used in metadata */
	protected const
		DEFAULT = 'defOut',
		FIXITY = 'fixity',
		FILTER_TABLE_FLIP = 'filterFlip';

	/** url type */
	protected const
		HOST = 1,
		PATH = 2,
		RELATIVE = 3;

	/** fixity types - how to handle default value? {@link Route::$metadata} */
	protected const
		OPTIONAL = 0,
		PATH_OPTIONAL = 1,
		CONSTANT = 2;

	private const
		PRESENTER_KEY = 'presenter',
		MODULE_KEY = 'module';

	/** @deprecated */
	public static $styles = [];

	/** @var array */
	protected $defaultMeta = [
		'#' => [ // default style for path parameters
			self::PATTERN => '[^/]+',
			self::FILTER_OUT => [__CLASS__, 'param2path'],
		],
		'module' => [
			self::PATTERN => '[a-z][a-z0-9.-]*',
			self::FILTER_IN => [__CLASS__, 'path2presenter'],
			self::FILTER_OUT => [__CLASS__, 'presenter2path'],
		],
		'presenter' => [
			self::PATTERN => '[a-z][a-z0-9.-]*',
			self::FILTER_IN => [__CLASS__, 'path2presenter'],
			self::FILTER_OUT => [__CLASS__, 'presenter2path'],
		],
		'action' => [
			self::PATTERN => '[a-z][a-z0-9-]*',
			self::FILTER_IN => [__CLASS__, 'path2action'],
			self::FILTER_OUT => [__CLASS__, 'action2path'],
		],
	];

	/** @var string */
	private $mask;

	/** @var array */
	private $sequence;

	/** @var string  regular expression pattern */
	private $re;

	/** @var string[]  parameter aliases in regular expression */
	private $aliases;

	/** @var array of [value & fixity, filterIn, filterOut] */
	private $metadata = [];

	/** @var array  */
	private $xlat;

	/** @var int HOST, PATH, RELATIVE */
	private $type;

	/** @var string  http | https */
	private $scheme;

	/** @var int */
	private $flags;

	/** @var Nette\Http\Url */
	private $lastRefUrl;

	/** @var string */
	private $lastBaseUrl;


	/**
	 * @param  string  $mask  e.g. '<presenter>/<action>/<id \d{1,3}>'
	 * @param  array|string|\Closure  $metadata  default values or metadata or callback for NetteModule\MicroPresenter
	 */
	public function __construct(string $mask, $metadata = [], int $flags = 0)
	{
		if (is_string($metadata)) {
			[$presenter, $action] = Nette\Application\Helpers::splitName($metadata);
			if (!$presenter) {
				throw new Nette\InvalidArgumentException("Second argument must be array or string in format Presenter:action, '$metadata' given.");
			}
			$metadata = [self::PRESENTER_KEY => $presenter];
			if ($action !== '') {
				$metadata['action'] = $action;
			}
		} elseif ($metadata instanceof \Closure) {
			$metadata = [
				self::PRESENTER_KEY => 'Nette:Micro',
				'callback' => $metadata,
			];
		}

		if (self::$styles) {
			trigger_error('Route::$styles is deprecated.', E_USER_DEPRECATED);
			array_replace_recursive($this->defaultMeta, self::$styles);
		}

		$this->flags = $flags;
		$this->setMask($mask, $metadata);
	}


	/**
	 * Maps HTTP request to an array.
	 */
	public function match(Nette\Http\IRequest $httpRequest): ?array
	{
		// combine with precedence: mask (params in URL-path), fixity, query, (post,) defaults

		// 1) URL MASK
		$url = $httpRequest->getUrl();
		$re = $this->re;

		if ($this->type === self::HOST) {
			$host = $url->getHost();
			$path = '//' . $host . $url->getPath();
			$parts = ip2long($host) ? [$host] : array_reverse(explode('.', $host));
			$re = strtr($re, [
				'/%basePath%/' => preg_quote($url->getBasePath(), '#'),
				'%tld%' => preg_quote($parts[0], '#'),
				'%domain%' => preg_quote(isset($parts[1]) ? "$parts[1].$parts[0]" : $parts[0], '#'),
				'%sld%' => preg_quote($parts[1] ?? '', '#'),
				'%host%' => preg_quote($host, '#'),
			]);

		} elseif ($this->type === self::RELATIVE) {
			$basePath = $url->getBasePath();
			if (strncmp($url->getPath(), $basePath, strlen($basePath)) !== 0) {
				return null;
			}
			$path = substr($url->getPath(), strlen($basePath));

		} else {
			$path = $url->getPath();
		}

		if ($path !== '') {
			$path = rtrim(rawurldecode($path), '/') . '/';
		}

		if (!$matches = Strings::match($path, $re)) {
			// stop, not matched
			return null;
		}

		// assigns matched values to parameters
		$params = [];
		foreach ($matches as $k => $v) {
			if (is_string($k) && $v !== '') {
				$params[$this->aliases[$k]] = $v;
			}
		}


		// 2) CONSTANT FIXITY
		foreach ($this->metadata as $name => $meta) {
			if (!isset($params[$name]) && isset($meta[self::FIXITY]) && $meta[self::FIXITY] !== self::OPTIONAL) {
				$params[$name] = null; // cannot be overwriten in 3) and detected by isset() in 4)
			}
		}


		// 3) QUERY
		if ($this->xlat) {
			$params += self::renameKeys($httpRequest->getQuery(), array_flip($this->xlat));
		} else {
			$params += $httpRequest->getQuery();
		}


		// 4) APPLY FILTERS & FIXITY
		foreach ($this->metadata as $name => $meta) {
			if (isset($params[$name])) {
				if (!is_scalar($params[$name])) {
					// do nothing
				} elseif (isset($meta[self::FILTER_TABLE][$params[$name]])) { // applies filterTable only to scalar parameters
					$params[$name] = $meta[self::FILTER_TABLE][$params[$name]];

				} elseif (isset($meta[self::FILTER_TABLE]) && !empty($meta[self::FILTER_STRICT])) {
					return null; // rejected by filterTable

				} elseif (isset($meta[self::FILTER_IN])) { // applies filterIn only to scalar parameters
					$params[$name] = $meta[self::FILTER_IN]((string) $params[$name]);
					if ($params[$name] === null && !isset($meta[self::FIXITY])) {
						return null; // rejected by filter
					}
				}

			} elseif (isset($meta[self::FIXITY])) {
				$params[$name] = $meta[self::VALUE];
			}
		}

		if (isset($this->metadata[null][self::FILTER_IN])) {
			$params = $this->metadata[null][self::FILTER_IN]($params);
			if ($params === null) {
				return null;
			}
		}

		// 5) PARAMETER MODULE
		$presenter = $params[self::PRESENTER_KEY] ?? null;
		if (isset($this->metadata[self::MODULE_KEY], $params[self::MODULE_KEY]) && is_string($presenter)) {
			$params[self::PRESENTER_KEY] = $params[self::MODULE_KEY] . ':' . $presenter;
		}
		unset($params[self::MODULE_KEY]);

		return $params;
	}


	/**
	 * Constructs absolute URL from array.
	 */
	public function constructUrl(array $params, Nette\Http\Url $refUrl): ?string
	{
		if ($this->flags & self::ONE_WAY) {
			return null;
		}

		$metadata = $this->metadata;
		$presenter = $params[self::PRESENTER_KEY];

		if (isset($metadata[self::MODULE_KEY])) { // try split into module and [submodule:]presenter parts
			$module = $metadata[self::MODULE_KEY];
			if (isset($module[self::FIXITY], $module[self::VALUE]) && strncmp($presenter, $module[self::VALUE] . ':', strlen($module[self::VALUE]) + 1) === 0) {
				$a = strlen($module[self::VALUE]);
			} else {
				$a = strrpos($presenter, ':');
			}
			if ($a === false) {
				$params[self::MODULE_KEY] = isset($module[self::VALUE]) ? '' : null;
			} else {
				$params[self::MODULE_KEY] = substr($presenter, 0, $a);
				$params[self::PRESENTER_KEY] = substr($presenter, $a + 1);
			}
		}

		if (isset($metadata[null][self::FILTER_OUT])) {
			$params = $metadata[null][self::FILTER_OUT]($params);
			if ($params === null) {
				return null;
			}
		}

		foreach ($metadata as $name => $meta) {
			if (!isset($params[$name])) {
				continue; // retains null values
			}

			if (is_scalar($params[$name])) {
				$params[$name] = $params[$name] === false ? '0' : (string) $params[$name];
			}

			if (isset($meta[self::FIXITY])) {
				if ($params[$name] === $meta[self::VALUE]) { // remove default values; null values are retain
					unset($params[$name]);
					continue;

				} elseif ($meta[self::FIXITY] === self::CONSTANT) {
					return null; // missing or wrong parameter '$name'
				}
			}

			if (is_scalar($params[$name]) && isset($meta[self::FILTER_TABLE_FLIP][$params[$name]])) {
				$params[$name] = $meta[self::FILTER_TABLE_FLIP][$params[$name]];

			} elseif (isset($meta[self::FILTER_TABLE_FLIP]) && !empty($meta[self::FILTER_STRICT])) {
				return null;

			} elseif (isset($meta[self::FILTER_OUT])) {
				$params[$name] = $meta[self::FILTER_OUT]($params[$name]);
			}

			if (isset($meta[self::PATTERN]) && !preg_match("#(?:{$meta[self::PATTERN]})\\z#A", rawurldecode((string) $params[$name]))) {
				return null; // pattern not match
			}
		}

		// compositing path
		$sequence = $this->sequence;
		$brackets = [];
		$required = null; // null for auto-optional
		$url = '';
		$i = count($sequence) - 1;
		do {
			$url = $sequence[$i] . $url;
			if ($i === 0) {
				break;
			}
			$i--;

			$name = $sequence[$i--]; // parameter name

			if ($name === ']') { // opening optional part
				$brackets[] = $url;

			} elseif ($name[0] === '[') { // closing optional part
				$tmp = array_pop($brackets);
				if ($required < count($brackets) + 1) { // is this level optional?
					if ($name !== '[!') { // and not "required"-optional
						$url = $tmp;
					}
				} else {
					$required = count($brackets);
				}

			} elseif ($name[0] === '?') { // "foo" parameter
				continue;

			} elseif (isset($params[$name]) && $params[$name] != '') { // intentionally ==
				$required = count($brackets); // make this level required
				$url = $params[$name] . $url;
				unset($params[$name]);

			} elseif (isset($metadata[$name][self::FIXITY])) { // has default value?
				if ($required === null && !$brackets) { // auto-optional
					$url = '';
				} else {
					$url = $metadata[$name][self::DEFAULT] . $url;
				}

			} else {
				return null; // missing parameter '$name'
			}
		} while (true);

		$scheme = $this->scheme ?: $refUrl->getScheme();

		if ($this->type === self::HOST) {
			$host = $refUrl->getHost();
			$parts = ip2long($host) ? [$host] : array_reverse(explode('.', $host));
			$url = strtr($url, [
				'/%basePath%/' => $refUrl->getBasePath(),
				'%tld%' => $parts[0],
				'%domain%' => isset($parts[1]) ? "$parts[1].$parts[0]" : $parts[0],
				'%sld%' => $parts[1] ?? '',
				'%host%' => $host,
			]);
			$url = $scheme . ':' . $url;
		} else {
			if ($this->lastRefUrl !== $refUrl) {
				$basePath = ($this->type === self::RELATIVE ? $refUrl->getBasePath() : '');
				$this->lastBaseUrl = $scheme . '://' . $refUrl->getAuthority() . $basePath;
				$this->lastRefUrl = $refUrl;
			}
			$url = $this->lastBaseUrl . $url;
		}

		if (strpos($url, '//', strlen($scheme) + 3) !== false) {
			return null;
		}

		// build query string
		if ($this->xlat) {
			$params = self::renameKeys($params, $this->xlat);
		}

		$sep = ini_get('arg_separator.input');
		$query = http_build_query($params, '', $sep ? $sep[0] : '&');
		if ($query != '') { // intentionally ==
			$url .= '?' . $query;
		}

		return $url;
	}


	/**
	 * Parse mask and array of default values; initializes object.
	 */
	private function setMask(string $mask, array $metadata): void
	{
		$this->mask = $mask;

		// detect '//host/path' vs. '/abs. path' vs. 'relative path'
		if (preg_match('#(?:(https?):)?(//.*)#A', $mask, $m)) {
			$this->type = self::HOST;
			[, $this->scheme, $mask] = $m;

		} elseif (substr($mask, 0, 1) === '/') {
			$this->type = self::PATH;

		} else {
			$this->type = self::RELATIVE;
		}

		foreach ($metadata as $name => $meta) {
			if (!is_array($meta)) {
				$metadata[$name] = $meta = [self::VALUE => $meta];
			}

			if (array_key_exists(self::VALUE, $meta)) {
				if (is_scalar($meta[self::VALUE])) {
					$metadata[$name][self::VALUE] = $meta[self::VALUE] === false ? '0' : (string) $meta[self::VALUE];
				}
				$metadata[$name]['fixity'] = self::CONSTANT;
			}
		}

		if (strpbrk($mask, '?<>[]') === false) {
			$this->re = '#' . preg_quote($mask, '#') . '/?\z#A';
			$this->sequence = [$mask];
			$this->metadata = $metadata;
			return;
		}

		// PARSE MASK
		// <parameter-name[=default] [pattern]> or [ or ] or ?...
		$parts = Strings::split($mask, '/<([^<>= ]+)(=[^<> ]*)? *([^<>]*)>|(\[!?|\]|\s*\?.*)/');

		$this->xlat = [];
		$i = count($parts) - 1;

		// PARSE QUERY PART OF MASK
		if (isset($parts[$i - 1]) && substr(ltrim($parts[$i - 1]), 0, 1) === '?') {
			// name=<parameter-name [pattern]>
			$matches = Strings::matchAll($parts[$i - 1], '/(?:([a-zA-Z0-9_.-]+)=)?<([^> ]+) *([^>]*)>/');

			foreach ($matches as [, $param, $name, $pattern]) { // $pattern is not used
				$meta = ($metadata[$name] ?? []) + ($this->defaultMeta['?' . $name] ?? []);

				if (array_key_exists(self::VALUE, $meta)) {
					$meta[self::FIXITY] = self::OPTIONAL;
				}

				unset($meta[self::PATTERN]);
				$meta[self::FILTER_TABLE_FLIP] = empty($meta[self::FILTER_TABLE]) ? null : array_flip($meta[self::FILTER_TABLE]);

				$metadata[$name] = $meta;
				if ($param !== '') {
					$this->xlat[$name] = $param;
				}
			}
			$i -= 5;
		}

		// PARSE PATH PART OF MASK
		$brackets = 0; // optional level
		$re = '';
		$sequence = [];
		$autoOptional = true;
		$aliases = [];
		do {
			$part = $parts[$i]; // part of path
			if (strpbrk($part, '<>') !== false) {
				throw new Nette\InvalidArgumentException("Unexpected '$part' in mask '$mask'.");
			}
			array_unshift($sequence, $part);
			$re = preg_quote($part, '#') . $re;
			if ($i === 0) {
				break;
			}
			$i--;

			$part = $parts[$i]; // [ or ]
			if ($part === '[' || $part === ']' || $part === '[!') {
				$brackets += $part[0] === '[' ? -1 : 1;
				if ($brackets < 0) {
					throw new Nette\InvalidArgumentException("Unexpected '$part' in mask '$mask'.");
				}
				array_unshift($sequence, $part);
				$re = ($part[0] === '[' ? '(?:' : ')?') . $re;
				$i -= 4;
				continue;
			}

			$pattern = trim($parts[$i--]); // validation condition (as regexp)
			$default = $parts[$i--]; // default value
			$name = $parts[$i--]; // parameter name
			array_unshift($sequence, $name);

			if ($name[0] === '?') { // "foo" parameter
				$name = substr($name, 1);
				$re = $pattern ? '(?:' . preg_quote($name, '#') . "|$pattern)$re" : preg_quote($name, '#') . $re;
				$sequence[1] = $name . $sequence[1];
				continue;
			}

			// pattern, condition & metadata
			$meta = ($metadata[$name] ?? []) + ($this->defaultMeta[$name] ?? $this->defaultMeta['#']);

			if ($pattern == '' && isset($meta[self::PATTERN])) {
				$pattern = $meta[self::PATTERN];
			}

			if ($default !== '') {
				$meta[self::VALUE] = substr($default, 1);
				$meta[self::FIXITY] = self::PATH_OPTIONAL;
			}

			$meta[self::FILTER_TABLE_FLIP] = empty($meta[self::FILTER_TABLE]) ? null : array_flip($meta[self::FILTER_TABLE]);
			if (array_key_exists(self::VALUE, $meta)) {
				if (isset($meta[self::FILTER_TABLE_FLIP][$meta[self::VALUE]])) {
					$meta[self::DEFAULT] = $meta[self::FILTER_TABLE_FLIP][$meta[self::VALUE]];

				} elseif (isset($meta[self::VALUE], $meta[self::FILTER_OUT])) {
					$meta[self::DEFAULT] = $meta[self::FILTER_OUT]($meta[self::VALUE]);

				} else {
					$meta[self::DEFAULT] = $meta[self::VALUE];
				}
			}
			$meta[self::PATTERN] = $pattern;

			// include in expression
			$aliases['p' . $i] = $name;
			$re = '(?P<p' . $i . '>(?U)' . $pattern . ')' . $re;
			if ($brackets) { // is in brackets?
				if (!isset($meta[self::VALUE])) {
					$meta[self::VALUE] = $meta[self::DEFAULT] = null;
				}
				$meta[self::FIXITY] = self::PATH_OPTIONAL;

			} elseif (isset($meta[self::FIXITY])) {
				if ($autoOptional) {
					$re = '(?:' . $re . ')?';
				}
				$meta[self::FIXITY] = self::PATH_OPTIONAL;

			} else {
				$autoOptional = false;
			}

			$metadata[$name] = $meta;
		} while (true);

		if ($brackets) {
			throw new Nette\InvalidArgumentException("Missing '[' in mask '$mask'.");
		}

		$this->aliases = $aliases;
		$this->re = '#' . $re . '/?\z#A';
		$this->metadata = $metadata;
		$this->sequence = $sequence;
	}


	/**
	 * Returns mask.
	 */
	public function getMask(): string
	{
		return $this->mask;
	}


	/**
	 * Returns default values.
	 */
	public function getDefaults(): array
	{
		$defaults = [];
		foreach ($this->metadata as $name => $meta) {
			if (isset($meta[self::FIXITY])) {
				$defaults[$name] = $meta[self::VALUE];
			}
		}
		return $defaults;
	}


	/**
	 * Returns flags.
	 */
	public function getFlags(): int
	{
		return $this->flags;
	}


	/********************* Utilities ****************d*g**/


	/**
	 * Proprietary cache aim.
	 * @internal
	 * @return string[]|null
	 */
	public function getTargetPresenters(): ?array
	{
		if ($this->flags & self::ONE_WAY) {
			return [];
		}

		$m = $this->metadata;
		$module = '';

		if (isset($m[self::MODULE_KEY])) {
			if (($m[self::MODULE_KEY][self::FIXITY] ?? null) === self::CONSTANT) {
				$module = $m[self::MODULE_KEY][self::VALUE] . ':';
			} else {
				return null;
			}
		}

		if (($m[self::PRESENTER_KEY][self::FIXITY] ?? null) === self::CONSTANT) {
			return [$module . $m[self::PRESENTER_KEY][self::VALUE]];
		}
		return null;
	}


	/**
	 * Rename keys in array.
	 */
	private static function renameKeys(array $arr, array $xlat): array
	{
		if (empty($xlat)) {
			return $arr;
		}

		$res = [];
		$occupied = array_flip($xlat);
		foreach ($arr as $k => $v) {
			if (isset($xlat[$k])) {
				$res[$xlat[$k]] = $v;

			} elseif (!isset($occupied[$k])) {
				$res[$k] = $v;
			}
		}
		return $res;
	}


	/********************* Inflectors ****************d*g**/


	/**
	 * camelCaseAction name -> dash-separated.
	 */
	public static function action2path(string $s): string
	{
		$s = preg_replace('#(.)(?=[A-Z])#', '$1-', $s);
		$s = strtolower($s);
		$s = rawurlencode($s);
		return $s;
	}


	/**
	 * dash-separated -> camelCaseAction name.
	 */
	public static function path2action(string $s): string
	{
		$s = preg_replace('#-(?=[a-z])#', ' ', $s);
		$s = lcfirst(ucwords($s));
		$s = str_replace(' ', '', $s);
		return $s;
	}


	/**
	 * PascalCase:Presenter name -> dash-and-dot-separated.
	 */
	public static function presenter2path(string $s): string
	{
		$s = strtr($s, ':', '.');
		$s = preg_replace('#([^.])(?=[A-Z])#', '$1-', $s);
		$s = strtolower($s);
		$s = rawurlencode($s);
		return $s;
	}


	/**
	 * dash-and-dot-separated -> PascalCase:Presenter name.
	 */
	public static function path2presenter(string $s): string
	{
		$s = preg_replace('#([.-])(?=[a-z])#', '$1 ', $s);
		$s = ucwords($s);
		$s = str_replace('. ', ':', $s);
		$s = str_replace('- ', '', $s);
		return $s;
	}


	/**
	 * Url encode.
	 */
	public static function param2path(string $s): string
	{
		return str_replace('%2F', '/', rawurlencode($s));
	}
}
