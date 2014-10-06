<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace AlexeyKupershtokh\LazyApcClassLoader;

/**
 * ApcClassLoader implements a wrapping autoloader cached in APC for PHP 5.3.
 *
 * It expects an object implementing a findFile method to find the file. This
 * allows using it as a wrapper around the other loaders of the component (the
 * ClassLoader and the UniversalClassLoader for instance) but also around any
 * other autoloader following this convention (the Composer one for instance)
 *
 *     require_once(__DIR__.'/vendor/AlexeyKupershtokh/LazyApcClassLoader/LazyApcClassLoader.php');
 *     $loader = new LazyApcClassLoader('my_prefix', function() {
 *         return require_once __DIR__ . '/vendor/autoload.php';
 *     });
 *     $loader->register();
 *
 * @author AlexeyKupershtokh <alexey.kupershtokh@gmail.com>
 *
 * @api
 */
class LazyApcClassLoader
{
    private $prefix;

    /**
     * The class loader object being decorated.
     *
     * @var null|object
     *   A class loader object that implements the findFile() method.
     */
    protected $decorated;

    protected $callback;

    /**
     * A class that forced the decorated class loader to be initialized.
     *
     * @var string
     */
    protected $breaker;

    /**
     * Constructor.
     *
     * @param string $prefix      The APC namespace prefix to use.
     * @param object $decorated   A class loader object that implements the findFile() method.
     *
     * @throws \RuntimeException
     * @throws \InvalidArgumentException
     *
     * @api
     */
    public function __construct($prefix, $callback)
    {
        if (!extension_loaded('apc')) {
            throw new \RuntimeException('Unable to use ApcClassLoader as APC is not enabled.');
        }

        $this->prefix = $prefix;
        $this->callback = $callback;
    }

    public function initDecorated($class)
    {
        if ($this->decorated === null) {
            $this->breaker = $class;
            $decorated = call_user_func($this->callback);
            if (!method_exists($decorated, 'findFile')) {
                throw new \InvalidArgumentException('The class finder must implement a "findFile" method.');
            }
            $this->decorated = $decorated;
        }
    }

    public function getBreaker()
    {
        return $this->breaker;
    }

    /**
     * Registers this instance as an autoloader.
     *
     * @param bool    $prepend Whether to prepend the autoloader or not
     */
    public function register($prepend = false)
    {
        spl_autoload_register(array($this, 'loadClass'), true, $prepend);
    }

    /**
     * Unregisters this instance as an autoloader.
     */
    public function unregister()
    {
        spl_autoload_unregister(array($this, 'loadClass'));
    }

    /**
     * Loads the given class or interface.
     *
     * @param string $class The name of the class
     *
     * @return bool|null    True, if loaded
     */
    public function loadClass($class)
    {
        if ($file = $this->findFile($class)) {
            require $file;

            return true;
        }
        $this->initDecorated($class);
        return false;
    }

    /**
     * Finds a file by class name while caching lookups to APC.
     *
     * @param string $class A class name to resolve to file
     *
     * @return string|null
     */
    public function findFile($class)
    {
        if (false === $file = apc_fetch($this->prefix.$class)) {
            $this->initDecorated($class);
            apc_store($this->prefix.$class, $file = $this->decorated->findFile($class));
        }

        return $file;
    }

    /**
     * Passes through all unknown calls onto the decorated object.
     */
    public function __call($method, $args)
    {
        return call_user_func_array(array($this->decorated, $method), $args);
    }
}