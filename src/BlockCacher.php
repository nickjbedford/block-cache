<?php
	/** @noinspection PhpUnused */
	
	namespace BlockCacher;
	
	use Exception;/**
	 * Represents a file-based data, HTML and text caching mechanism.
	 * This "block" cacher class provides the ability to generate and
	 * store data efficiently using the file system alone. Reduce load
	 * times to fractions of a millisecond by caching generated information.
	 * Minimum PHP support is PHP 7.3.
	 */
	class BlockCacher
	{
		/** @var int Specifies the default lifetime for cache files of one day. */
		const DefaultLifetime = 86400;
		
		/** @var BlockCacher $default Specifies the default cacher. */
		private static $default;
		
		/** @var BlockCacherOutputBuffer[] $buffers Stores the stack of currently open buffers. */
		private $buffers = array();
		
		/** @var string $directory Specifies the directory where cache files will be stored. */
		private $directory;
		
		/** @var string $prefix Specifies the text to prefix to all filenames. */
		private $prefix;
		
		/** @var bool $forceCached Specifies whether to force caching regardless of whether the cacher is enabled. */
		private $forceCached = false;
		
		/** @var bool $enabled Specifies whether the cacher will actually use cached data or not. */
		private $enabled = true;
		
		/** @var string[] $protectedPatterns Specifies the list of file patterns to protect when clearing the cache. */
		private $protectedPatterns = array();
		
		/**
		 * Initialises a new instance of the block cacher.
		 * @param string $directory The directory to store all cache files in.
		 * @param string $filePrefix Optional. The prefix to add to cache filenames (i.e. such localisation, versions).
		 * @param boolean $automaticallyEnsureStorageDirectoryExists Set to true to automatically ensure the storage directory exists.
		 * @throws
		 */
		public function __construct(
			string $directory = __DIR__ . '/cache',
			string $filePrefix = '',
			bool $automaticallyEnsureStorageDirectoryExists = true)
		{
			$this->directory = rtrim($directory, '/\\') . '/';
			$this->prefix = $filePrefix;
			
			if ($automaticallyEnsureStorageDirectoryExists)
				$this->ensureStorageDirectoryExists();
			
			if (!self::$default)
				$this->setAsDefault();
		}
		
		/**
		 * Sets this instance to be the default instance.
		 * @return self
		 */
		public function setAsDefault(): self
		{
			return self::$default = $this;
		}
		
		/**
		 * Gets the default block cacher instance.
		 * @return self|null
		 */
		public static function default(): ?self
		{
			return self::$default ?? (self::$default = new self());
		}
		
		/**
		 * Gets the block cache storage directory.
		 * @return string
		 */
		public function directory(): string
		{
			return $this->directory;
		}
		
		/**
		 * Ensures the storage directory exists. An exception will be thrown if it cannot be created.
		 * @throws
		 */
		public function ensureStorageDirectoryExists(): void
		{
			if (!file_exists($this->directory) && !mkdir($this->directory, 0755, true))
				throw new Exception("The specified block cacher storage directory ($this->directory) could not be created. Please ensure you have the correct permissions to create this directory.");
		}
		
		/**
		 * Gets a list of all cache files in the storage directory.
		 * @param string $pattern The glob file pattern to search for cache files.
		 * @return string[]
		 */
		public function getCacheFilePaths(string $pattern = '*'): array
		{
			return glob($this->directory . $pattern);
		}
		
		/**
		 * Adds a file pattern to be protected when clearing cache files. This should
		 * follows the rules of glob file pattern matching.
		 * @param string $pattern
		 */
		public function protectFilePattern(string $pattern)
		{
			$this->protectedPatterns[] = $pattern;
		}
		
		/**
		 * Clears cache files found using a specified pattern (defaults to all files).
		 * @param string $pattern The glob file search pattern.
		 * @param bool $prefixed Whether to add the cacher's prefix to the pattern.
		 * @param bool $clearProtectedFiles Set to true to include protected cache file patterns in the clear process.
		 * @param int $minimumAge Only files older than this many seconds are cleared.
		 * @return BlockCacherClearResults The results of the clearing process.
		 */
		public function clear($pattern = '*', $prefixed = true, $clearProtectedFiles = false, int $minimumAge = 0): BlockCacherClearResults
		{
			if ($prefixed)
				$pattern = "$this->prefix$pattern";
			
			/** @var array $files */
			$files = glob($this->directory . $pattern) ?: [];
			
			if (!$clearProtectedFiles && !empty($this->protectedPatterns))
				$files = $this->filterProtectedFiles($files);
			
			if ($minimumAge > 0)
				$files = $this->filterNewerFiles($minimumAge, $files);
			
			$cleared = array();
			foreach($files as $file)
				if (unlink($file))
					$cleared[] = $file;

			return new BlockCacherClearResults($files, $cleared);
		}
		
		/**
		 * Filters out any protected files based on the protected file patterns.
		 * @param array $files The files to be cleared.
		 * @return array
		 */
		private function filterProtectedFiles(array $files): array
		{
			return array_filter($files, function ($file)
			{
				$filename = pathinfo($file, PATHINFO_BASENAME);
				foreach ($this->protectedPatterns as $pattern)
				{
					if (fnmatch($pattern, $filename))
						return false;
				}
				return true;
			});
		}
		
		/**
		 * Filters files out that are newer than a specified age in seconds.
		 * @param int $minimumAge The minimum age of the files in seconds.
		 * @param array $files The files to filter.
		 * @return array
		 */
		private function filterNewerFiles(int $minimumAge, array $files): array
		{
			$timestamp = time() - $minimumAge;
			return array_filter($files, function(string $filename) use($timestamp)
			{
				return filemtime($filename) <= $timestamp;
			});
		}
		
		/**
		 * Gets a cached string that is still valid. If the cached value does not exist or has expired,
		 * null is returned.
		 * @param string $key The key for the cached value.
		 * @param int $lifetime The lifetime for the cached value in seconds. The default is 86,400 (one day).
		 * @param bool $prefixed Whether to add the cacher's prefix to this key.
		 * @return mixed|null
		 */
		public function getText(string $key, int $lifetime = self::DefaultLifetime, bool $prefixed = true)
		{
			if (!$this->enabled && !$this->forceCached)
				return null;
			
			$filename = $this->filepath($key, $prefixed);
			if (!$this->isValid($filename, $lifetime))
				return null;
			
			$tmp = fopen($filename, 'r');
			@flock($tmp, LOCK_SH);
			$contents = file_get_contents($filename);
			@flock($tmp, LOCK_UN);
			fclose($tmp);
			return $contents;
		}
		
		/**
		 * Gets a cached value that is still valid. If the cached value does not exist or has expired,
		 * null is returned.
		 * @param string $key The key for the cached value.
		 * @param int $lifetime The lifetime for the cached value in seconds. The default is 86,400 (one day).
		 * @param bool $prefixed Whether to add the cacher's prefix to this key.
		 * @return mixed|null
		 */
		public function get(string $key, int $lifetime = self::DefaultLifetime, bool $prefixed = true)
		{
			$value = $this->getText($key, $lifetime, $prefixed);
			return $value !== null ? unserialize($value) : null;
		}
		
		/**
		 * Gets the filename for a cache key.
		 * @param string $key The name for the cached value.
		 * @param bool $prefixed Whether to add the cacher's prefix to this key.
		 * @return string
		 */
		public function filepath(string $key, bool $prefixed = true): string
		{
			return $this->directory . ($prefixed ? "$this->prefix$key" : $key);
		}
		
		/**
		 * Determines if a cache file exists and is valid.
		 * @param string $filepath The full path of the file.
		 * @param int $lifetime The lifetime in seconds.
		 * @return bool
		 */
		private function isValid(string $filepath, int $lifetime): bool
		{
			return file_exists($filepath) &&
			       filemtime($filepath) > (time() - $lifetime);
		}
		
		/**
		 * Determines if a cached value exists and is valid.
		 * @param string $key The key for the cached value.
		 * @param int $lifetime The arbitrary lifetime of the cached value (in seconds).
		 * @param bool $prefixed Whether to add the cacher's prefix to this key.
		 * @return bool
		 */
		public function exists(string $key, int $lifetime = self::DefaultLifetime, bool $prefixed = true): bool
		{
			return ($this->enabled || $this->forceCached) ?
				$this->isValid($this->filepath($key, $prefixed), $lifetime) : false;
		}
		
		/**
		 * Stores a value in the file cache. The value must be serializable
		 * using the native serialize() function.
		 * @param string $key The key for the cached value.
		 * @param mixed $value The value to store in the cache.
		 * @param bool $prefixed Whether to add the cacher's prefix to this key.
		 * @return bool
		 */
		public function store(string $key, $value, bool $prefixed = true)
		{
			return $this->storeText($key, serialize($value), $prefixed);
		}
		
		/**
		 * Stores a string value in the file cache. This does not serialize the value
		 * but will coerce its type to string.
		 * @param string $key The key for the cached value.
		 * @param mixed $value The value to store in the cache as text.
		 * @param bool $prefixed Whether to add the cacher's prefix to this key.
		 * @return bool
		 */
		public function storeText(string $key, $value, bool $prefixed = true)
		{
			if (!$this->enabled && !$this->forceCached)
				return false;
			$filepath = $this->filepath($key, $prefixed);
			return file_put_contents($filepath, strval($value), LOCK_EX) !== false;
		}
		
		/**
		 * Starts a caching buffer, otherwise storing the existing cached contents until end() is called.
		 * @param string $key The key for the cached value.
		 * @param int $lifetime The arbitrary lifetime of the cached value (in seconds).
		 * @param bool $prefixed Whether to add the cacher's prefix to this key.
		 * @return bool Returns true if the output should be generated, false if the cache exists and is valid.
		 */
		public function start(string $key, int $lifetime = self::DefaultLifetime, bool $prefixed = true): bool
		{
			$this->buffers[] = $buffer =
				new BlockCacherOutputBuffer($key, $prefixed, $this->getText($key, $lifetime, $prefixed));
			
			if (!$buffer->hit)
				ob_start();
			return !$buffer->hit;
		}
		
		/**
		 * Stores the output buffer into the cache file and optionally echoes the content.
		 * @param bool $echo Set to true to echo the contents of the buffer automatically.
		 * @return BlockCacherOutputBuffer The output buffer information.
		 * @throws
		 */
		public function end(bool $echo = true): BlockCacherOutputBuffer
		{
			if (empty($this->buffers))
				throw new Exception('No block cacher buffer has been started.');
			
			$buffer = array_pop($this->buffers);
			if (!$buffer->hit)
			{
				$buffer->contents = ob_get_clean();
				if (!$this->storeText($buffer->key, $buffer->contents, $buffer->prefixed))
				{
					$file = $this->filepath($buffer->key, $buffer->prefixed);
					throw new Exception("The buffer could not be stored to \"$file\" using file_put_contents().");
				}
			}
			
			if ($echo)
				echo $buffer->contents;
			return $buffer;
		}
	}